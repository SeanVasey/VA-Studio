<?php

namespace Tests\Canary;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\ProductionPaidOrderLocatorV1;
use App\Domain\Commerce\ProductionCheckout\ProductionPaidOrderSourceV1;
use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use ArrayObject;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\Feature\ProductionCheckoutJourneyTest;

final class CheckoutReceiptArrayParentCanaryTest extends ProductionCheckoutJourneyTest
{
    public function test_plain_receipt_rejects_late_app_arrayobject_parent_without_executing_offsets(): void
    {
        config(['production_checkout.committed_read_receipts_enabled' => true,
            'production-customer-identity.historical_receipts_enabled' => true,
            'production-customer-identity.historical_receipts_version' => 'identity-historical-committed-receipt-v1']);
        $f = $this->payable();
        $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $f['gateway']->paid = true;
        $f['hosted']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $locator = ProductionPaidOrderLocatorV1::locate($f['order']['orderId']);
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $pdo->exec('CREATE TABLE pco_parent_callback_probe (id INTEGER PRIMARY KEY, marker INTEGER NOT NULL)');
        $pdo->exec('INSERT INTO pco_parent_callback_probe VALUES (1,9123)');
        $receipt = $connection->transaction(function () use ($f, $locator, $pdo) {
            $reader = new CurrentRows($pdo, DB::getDriverName());
            $expected = $f['access']->verifyHistoricalBinding($locator->historicalBuyerBinding(), $reader);
            $source = ProductionPaidOrderSourceV1::lockedRead($locator, $reader, $expected);
            $receipt = $source->committedReadReceipt($reader, hrtime(true) + 60_000_000_000);
            $source->proveRetainedCurrent($reader);

            return $receipt;
        });
        $configuration = app('config');
        $original = $configuration->get('app');
        $parent = new ReceiptCallbackArrayParent($original, $pdo);
        $configuration->set('app', $parent);
        $refusal = null;
        try {
            $receipt->proveClosed();
        } catch (CheckoutException $error) {
            $refusal = $error->reason;
        } finally {
            $configuration->set('app', $original);
        }
        $marker = (int) $pdo->query('SELECT marker FROM pco_parent_callback_probe WHERE id=1')->fetchColumn();
        file_put_contents(__DIR__.'/observed-parent.json', json_encode(['callbacks' => $parent->callbacks, 'marker' => $marker,
            'refusal' => $refusal, 'physical_transaction' => $pdo->inTransaction()], JSON_PRETTY_PRINT)."\n");
        $this->assertSame(0, $parent->callbacks, 'Plain committed receipt invoked an application parent callback.');
        $this->assertSame(9123, $marker);
        $this->assertSame('committed_read_frame', $refusal);
        $this->assertFalse($pdo->inTransaction());
    }
}

final class ReceiptCallbackArrayParent extends ArrayObject
{
    public int $callbacks = 0;

    public function __construct(array $items, private readonly PDO $primary)
    {
        parent::__construct($items);
    }

    public function offsetExists(mixed $key): bool
    {
        $this->callbacks++;
        $this->primary->exec('UPDATE pco_parent_callback_probe SET marker=9133 WHERE id=1');

        return parent::offsetExists($key);
    }

    public function offsetGet(mixed $key): mixed
    {
        $this->callbacks++;
        $this->primary->exec('UPDATE pco_parent_callback_probe SET marker=9133 WHERE id=1');

        return parent::offsetGet($key);
    }
}
