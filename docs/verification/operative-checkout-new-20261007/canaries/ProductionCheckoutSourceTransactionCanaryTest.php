<?php

namespace Tests\Canary;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\ProductionPaidOrderLocatorV1;
use App\Domain\Commerce\ProductionCheckout\ProductionPaidOrderSourceV1;
use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ProductionCheckoutJourneyTest;

final class ProductionCheckoutSourceTransactionCanaryTest extends ProductionCheckoutJourneyTest
{
    public function test_paid_locked_source_never_mints_without_a_held_consumer_transaction(): void
    {
        $f = $this->payable();
        $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $f['gateway']->paid = true;
        $f['hosted']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $locator = ProductionPaidOrderLocatorV1::locate($f['order']['orderId']);
        $reader = new CurrentRows(DB::connection()->getPdo(), DB::getDriverName());
        $historical = $f['access']->verifyHistoricalBinding($locator->historicalBuyerBinding(), $reader);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        $this->expectException(CheckoutException::class);
        ProductionPaidOrderSourceV1::lockedRead($locator, $reader, $historical);
    }
}
