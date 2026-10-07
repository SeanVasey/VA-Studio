<?php

namespace Tests\Feature;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\ProductionPaidOrderLocatorV1;
use App\Domain\Commerce\ProductionCheckout\ProductionPaidOrderSourceV1;
use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use ArrayObject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionCheckoutJourneyFixture;
use Tests\TestCase;

final class ProductionCheckoutReceiptParentTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use ProductionCheckoutJourneyFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('j', 32)),
            'production-customer-identity.enabled' => true, 'production-customer-identity.provenance' => IdentityPolicy::REHEARSAL,
            'production-customer-identity.public_origin' => 'http://localhost', 'production-customer-identity.notifications_enabled' => true,
            'production-customer-identity.transport_capability' => LoopbackSmtp::CAPABILITY,
            'production_checkout.fresh_checkout_enabled' => true, 'production_checkout.reconciliation_enabled' => true]);
        Queue::fake();
    }

    public static function parents(): array
    {
        return [['app'], ['database'], ['production_checkout'], ['production-customer-identity']];
    }

    #[DataProvider('parents')]
    public function test_plain_receipt_refuses_late_configuration_parent_before_application_offsets(string $key): void
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
        $original = $configuration->get($key);
        $parent = new ReceiptParentWritingArray($original, $pdo);
        $configuration->set($key, $parent);
        $refusal = null;
        try {
            $receipt->proveClosed();
        } catch (CheckoutException $error) {
            $refusal = $error->reason;
        } finally {
            $configuration->set($key, $original);
        }
        $marker = (int) $pdo->query('SELECT marker FROM pco_parent_callback_probe WHERE id=1')->fetchColumn();
        $this->assertSame(0, $parent->callbacks, 'Plain committed receipt invoked an application parent callback.');
        $this->assertSame(9123, $marker);
        $this->assertSame('committed_read_frame', $refusal);
        $this->assertFalse($pdo->inTransaction());
    }
}

final class ReceiptParentWritingArray extends ArrayObject
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
