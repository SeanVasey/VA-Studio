<?php

namespace App\Console\Commands;

use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\RefundDispute\RefundDisputeSource;
use App\Domain\Delivery\DeliveryException;
use App\Domain\Delivery\ManageTestDeliveryControl;
use App\Domain\Delivery\TestAccessPolicy;
use App\Domain\Delivery\TestDeliveryAutoEnablePolicy;
use App\Domain\SoundKits\Purchases\Models\SoundKitOrder;
use App\Domain\SoundKits\Purchases\SoundKitDelivery;
use App\Domain\SoundKits\Purchases\SoundKitPurchaseException;
use App\Domain\SoundKits\Purchases\SoundKitPurchasePolicy;
use Illuminate\Console\Command;
use Throwable;

/**
 * D-19 amendment (2026-11-12, under Sean's delegation): enables Stripe-test delivery for orders that reached the state in which staff
 * would be offered "Enable delivery" and never had a delivery control, only while {@see TestDeliveryAutoEnablePolicy} admits it
 * (default off; local, testing and staging only). Track orders (activated, no control, no active refund/dispute hold) go through
 * {@see ManageTestDeliveryControl::enableAutomatically()}; sound-kit orders (grant and contract original recorded, no control) through
 * {@see SoundKitDelivery::enableAutomatically()}, the kit lane only while {@see SoundKitPurchasePolicy} admits it. Newest orders first,
 * so an order an operator must inspect cannot hold back a new one. Idempotent: an enabled order leaves the selection and a replay
 * changes nothing. Prints "<order id> <outcome>" per order; exits non-zero when the policy refuses or any selected order is refused,
 * which the tracked schedule shows as Needs attention.
 */
final class AutoEnableTestDelivery extends Command
{
    protected $signature = 'vasey:auto-enable-test-delivery {--limit=25}';

    protected $description = 'Enable Stripe-test delivery for ready orders that never had a delivery control (optional test policy).';

    public function handle(): int
    {
        $limit = $this->option('limit');
        if (! app(TestDeliveryAutoEnablePolicy::class)->admitted() || ! is_string($limit) || ! ctype_digit($limit)
            || (int) $limit < 1 || (int) $limit > 100) {
            $this->error('Automatic test delivery enabling is unavailable or the request is invalid.');

            return self::FAILURE;
        }
        $failed = ! $this->tracks((int) $limit);
        if (app(SoundKitPurchasePolicy::class)->admitted()) {
            $failed = ! $this->kits((int) $limit) || $failed;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function tracks(int $limit): bool
    {
        try {
            $account = app(TestAccessPolicy::class)->environmentAccount();
            app(TestAccessPolicy::class)->current();
            $orders = Order::query()->select('orders.id', 'orders.public_id')
                ->join('order_finalizations', 'order_finalizations.order_id', '=', 'orders.id')
                ->join('verified_payments', 'verified_payments.id', '=', 'order_finalizations.verified_payment_id')
                ->where('order_finalizations.outcome', 'paid')->where('order_finalizations.mode', 'test')
                ->where('verified_payments.mode', 'test')->where('verified_payments.account_id', $account)
                ->whereExists(fn ($activation) => $activation->selectRaw('1')->from('test_fulfillment_activations as fa')->whereColumn('fa.order_id', 'orders.id'))
                ->whereNotExists(fn ($control) => $control->selectRaw('1')->from('test_delivery_controls as dc')->whereColumn('dc.order_id', 'orders.id'))
                ->whereNotExists(fn ($hold) => $hold->selectRaw('1')->from('refund_dispute_holds as h')->whereColumn('h.order_id', 'orders.id')
                    ->where('h.order_source', RefundDisputeSource::TEST)->whereNull('h.released_at'))
                ->orderByDesc('orders.id')->limit($limit)->get();
        } catch (Throwable) {
            $this->error('Track test delivery access is unavailable; no track order was enabled.');

            return false;
        }
        $ok = true;
        foreach ($orders as $order) {
            try {
                $outcome = app(ManageTestDeliveryControl::class)->enableAutomatically($order->public_id) ? 'enabled' : 'unchanged';
            } catch (DeliveryException $error) {
                $outcome = 'refused_'.$error->reason;
            } catch (Throwable) {
                $outcome = 'refused_other';
            }
            $ok = $ok && ! str_starts_with($outcome, 'refused');
            $this->line($order->public_id.' '.$outcome);
        }

        return $ok;
    }

    private function kits(int $limit): bool
    {
        try {
            $orders = SoundKitOrder::query()->select('sound_kit_orders.id', 'sound_kit_orders.public_id')
                ->whereExists(fn ($grant) => $grant->selectRaw('1')->from('sound_kit_grants as g')
                    ->join('sound_kit_contracts as c', 'c.sound_kit_grant_id', '=', 'g.id')->whereColumn('g.sound_kit_order_id', 'sound_kit_orders.id'))
                ->whereNotExists(fn ($control) => $control->selectRaw('1')->from('sound_kit_delivery_controls as d')
                    ->whereColumn('d.sound_kit_order_id', 'sound_kit_orders.id'))
                ->orderByDesc('sound_kit_orders.id')->limit($limit)->get();
        } catch (Throwable) {
            $this->error('Sound-kit test delivery is unavailable; no kit order was enabled.');

            return false;
        }
        $ok = true;
        foreach ($orders as $order) {
            try {
                $outcome = app(SoundKitDelivery::class)->enableAutomatically($order->public_id) ? 'enabled' : 'unchanged';
            } catch (SoundKitPurchaseException $error) {
                $outcome = $error->errorCode === 'IDEMPOTENCY_CONFLICT' ? 'unchanged' : 'refused_'.strtolower($error->errorCode);
            } catch (Throwable) {
                $outcome = 'refused_other';
            }
            $ok = $ok && ! str_starts_with($outcome, 'refused');
            $this->line($order->public_id.' '.$outcome);
        }

        return $ok;
    }
}
