<?php

namespace Tests\Canary;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\CheckoutSchema;
use App\Domain\Commerce\ProductionCheckout\Evidence;
use App\Domain\Media\PrivateMediaFiles;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ProductionCheckoutJourneyTest;

final class CheckoutTerminalFreshPolicyCanaryTest extends ProductionCheckoutJourneyTest
{
    public function test_terminal_private_media_resolution_withdrawal_refuses_new_order_and_rolls_back(): void
    {
        $f = $this->payable(false);
        $reviewRow = (array) DB::table(CheckoutSchema::TABLES['review'])->first();
        $body = Evidence::open($reviewRow, 'production_checkout_review');
        $initialCount = count($body['selection']['bytes']);
        $callbackCount = 0;
        app()->resolving(PrivateMediaFiles::class, function () use (&$callbackCount, $initialCount): void {
            $callbackCount++;
            if ($callbackCount > $initialCount) {
                config(['production_checkout.fresh_checkout_enabled' => false]);
            }
        });
        $returned = null;
        $refusal = null;
        try {
            $returned = $f['checkout']->accept($f['buyer']['principal'], $f['buyer']['user'], $f['review']['reviewId'], $f['review']['reviewHash'], true, 'synthetic-terminal-withdrawal');
        } catch (CheckoutException $error) {
            $refusal = $error->reason;
        }
        $counts = [];
        foreach (['order', 'line', 'attempt'] as $kind) {
            $counts[$kind] = DB::table(CheckoutSchema::TABLES[$kind])->count();
        }
        file_put_contents(__DIR__.'/snapshot.json', json_encode([
            'source' => 'b6f1dfbb7b92cee5319eac79443b88bf24178ab2',
            'expected_initial_resolution_count' => $initialCount,
            'actual_resolver_callbacks' => $callbackCount,
            'fresh_enabled' => config('production_checkout.fresh_checkout_enabled'),
            'refusal' => $refusal,
            'returned_order' => $returned,
            'retained_counts' => $counts,
            'review_row_unchanged' => $reviewRow === (array) DB::table(CheckoutSchema::TABLES['review'])->first(),
        ], JSON_PRETTY_PRINT).'\n');
        $this->assertGreaterThan($initialCount, $callbackCount, 'Actual terminal media resolver did not run.');
        $this->assertFalse(config('production_checkout.fresh_checkout_enabled'));
        $this->assertSame('disabled', $refusal, 'New order escaped after terminal fresh-checkout policy withdrawal.');
        $this->assertSame(['order' => 0, 'line' => 0, 'attempt' => 0], $counts);
    }
}
