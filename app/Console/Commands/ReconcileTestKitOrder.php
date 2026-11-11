<?php

namespace App\Console\Commands;

use App\Domain\SoundKits\Purchases\Models\SoundKitOrder;
use App\Domain\SoundKits\Purchases\SoundKitPayments;
use App\Domain\SoundKits\Purchases\SoundKitPurchasePolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/** BL-13: verify a kit order's Stripe test payment from GET-only provider reads (a webhook that never arrived), then grant once. */
final class ReconcileTestKitOrder extends Command
{
    protected $signature = 'vasey:reconcile-test-kit-order {order}';

    protected $description = 'Reconcile one Stripe-test sound-kit order from provider reads; grants at most once.';

    public function handle(): int
    {
        try {
            app(SoundKitPurchasePolicy::class)->require();
            $id = (string) $this->argument('order');
            $order = Str::isUuid($id) ? SoundKitOrder::query()->where('public_id', $id)->first() : null;
            if (! $order) {
                throw new \InvalidArgumentException;
            }
            $this->line($order->public_id.' '.app(SoundKitPayments::class)->reconcile($order));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Kit order reconciliation is unavailable or the request is invalid.');

            return self::FAILURE;
        }
    }
}
