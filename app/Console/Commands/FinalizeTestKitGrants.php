<?php

namespace App\Console\Commands;

use App\Domain\SoundKits\Purchases\Models\SoundKitOrder;
use App\Domain\SoundKits\Purchases\SoundKitPayments;
use App\Domain\SoundKits\Purchases\SoundKitPurchasePolicy;
use Illuminate\Console\Command;
use Throwable;

/**
 * BL-13: grants every paid Stripe-test kit order that has a verified payment but no grant (a grant that failed after its payment
 * committed). Bounded and idempotent: a granted order is never selected again and a replay writes nothing. Exits non-zero while any
 * selected order is still refused, so the tracked schedule shows Needs attention on the operational dashboard.
 */
final class FinalizeTestKitGrants extends Command
{
    protected $signature = 'vasey:finalize-test-kit-grants {--limit=25}';

    protected $description = 'Grant paid Stripe-test sound-kit orders whose grant has not been written yet.';

    public function handle(): int
    {
        $limit = $this->option('limit');
        if (! app(SoundKitPurchasePolicy::class)->admitted() || ! is_string($limit) || ! ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 100) {
            $this->error('Kit grant finalization is unavailable or the request is invalid.');

            return self::FAILURE;
        }
        try {
            $orders = SoundKitOrder::query()
                ->whereExists(fn ($payments) => $payments->selectRaw('1')->from('sound_kit_payments')
                    ->whereColumn('sound_kit_payments.sound_kit_order_id', 'sound_kit_orders.id'))
                ->whereNotExists(fn ($grants) => $grants->selectRaw('1')->from('sound_kit_grants')
                    ->whereColumn('sound_kit_grants.sound_kit_order_id', 'sound_kit_orders.id'))
                ->orderBy('id')->limit((int) $limit)->get();
        } catch (Throwable) {
            $this->error('Kit grant finalization is unavailable or the request is invalid.');

            return self::FAILURE;
        }
        $failed = false;
        foreach ($orders as $order) {
            $outcome = app(SoundKitPayments::class)->settle($order);
            $failed = $failed || $outcome !== 'granted';
            $this->line($order->public_id.' '.$outcome);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
