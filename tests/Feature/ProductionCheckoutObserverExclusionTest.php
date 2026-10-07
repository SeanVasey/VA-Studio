<?php

namespace Tests\Feature;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\CheckoutSchema;
use App\Domain\Commerce\ProductionCheckout\CheckoutWriteAdmission;
use App\Domain\Commerce\ProductionCheckout\CommandTransaction;
use App\Domain\Commerce\ProductionCheckout\FreshCheckoutPolicy;
use App\Domain\Commerce\ProductionCheckout\ProductionPaidOrderLocatorV1;
use App\Domain\Commerce\ProductionCheckout\ProductionPaidOrderSourceV1;
use App\Domain\Commerce\ProductionCheckout\Records;
use App\Domain\Customers\ProductionIdentity\IdentityHistoricalCommittedReceipt;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionCheckoutJourneyFixture;
use Tests\TestCase;

/**
 * The NEW-write command observer and the paid-source receipt observer each own a whole
 * original frame. Composing them on one frame would let one observer's anchor release
 * destroy the other's younger savepoint, so either installation order is refused.
 */
class ProductionCheckoutObserverExclusionTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use ProductionCheckoutJourneyFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'production-customer-identity.enabled' => true, 'production-customer-identity.provenance' => IdentityPolicy::REHEARSAL,
            'production-customer-identity.public_origin' => 'http://localhost', 'production-customer-identity.notifications_enabled' => true,
            'production-customer-identity.transport_capability' => LoopbackSmtp::CAPABILITY,
            'production-customer-identity.historical_receipts_enabled' => true,
            'production-customer-identity.historical_receipts_version' => IdentityHistoricalCommittedReceipt::VERSION,
            'production_checkout.fresh_checkout_enabled' => true, 'production_checkout.reconciliation_enabled' => true,
            'production_checkout.committed_read_receipts_enabled' => true]);
        Queue::fake();
    }

    public function test_receipt_observer_refuses_frame_already_holding_new_write_observer(): void
    {
        [$f, $locator] = $this->paid();
        $delegate = DB::connection()->getEventDispatcher();
        $reached = false;
        try {
            CommandTransaction::run(function (Records $rows) use ($f, $locator, &$reached): void {
                CheckoutWriteAdmission::capture($rows, $f['access'], $f['buyer']['principal'], $f['buyer']['user'],
                    FreshCheckoutPolicy::capture(), 'order', $f['order']['orderId']);
                $reached = true;
                $source = $this->source($f, $locator, $rows);
                $source->committedReadReceipt($rows->current, hrtime(true) + 60_000_000_000);
                $this->fail('A paid receipt observer was stacked on the NEW-write command frame.');
            });
            $this->fail('The stacked command unexpectedly committed.');
        } catch (CheckoutException $error) {
            $this->assertSame('committed_read_frame', $error->reason);
        }
        $this->assertTrue($reached, 'The NEW-write admission must be registered before the receipt attempt.');
        $this->assertFrameReleased($delegate);
    }

    public function test_new_write_observer_refuses_frame_already_holding_receipt_observer(): void
    {
        [$f, $locator] = $this->paid();
        $delegate = DB::connection()->getEventDispatcher();
        $reached = false;
        try {
            CommandTransaction::run(function (Records $rows) use ($f, $locator, &$reached): void {
                $source = $this->source($f, $locator, $rows);
                $source->committedReadReceipt($rows->current, hrtime(true) + 60_000_000_000);
                $reached = true;
                CheckoutWriteAdmission::capture($rows, $f['access'], $f['buyer']['principal'], $f['buyer']['user'],
                    FreshCheckoutPolicy::capture(), 'order', $f['order']['orderId']);
                $this->fail('A NEW-write observer was stacked on the paid receipt frame.');
            });
            $this->fail('The stacked command unexpectedly committed.');
        } catch (CheckoutException $error) {
            $this->assertSame('write_frame', $error->reason);
        }
        $this->assertTrue($reached, 'The paid receipt must be minted before the NEW-write attempt.');
        $this->assertFrameReleased($delegate);
    }

    private function paid(): array
    {
        $f = $this->payable();
        $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $f['gateway']->paid = true;
        $f['hosted']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);

        return [$f, ProductionPaidOrderLocatorV1::locate($f['order']['orderId'])];
    }

    private function source(array $f, ProductionPaidOrderLocatorV1 $locator, Records $rows): ProductionPaidOrderSourceV1
    {
        $identity = $f['access']->verifyHistoricalBinding($locator->historicalBuyerBinding(), $rows->current);

        return ProductionPaidOrderSourceV1::lockedRead($locator, $rows->current, $identity);
    }

    private function assertFrameReleased(object $delegate): void
    {
        $connection = DB::connection();
        $this->assertSame($delegate, $connection->getEventDispatcher());
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($connection->getPdo()->inTransaction());
        $this->assertDatabaseCount(CheckoutSchema::TABLES['order'], 1);
        $this->assertDatabaseCount(CheckoutSchema::TABLES['payment'], 1);
        $this->assertDatabaseCount('license_grants', 0);
    }
}
