<?php

namespace App\Console\Commands;

use App\Domain\SoundKits\Purchases\Models\SoundKitOrder;
use App\Domain\SoundKits\Purchases\Models\SoundKitPayment;
use App\Domain\SoundKits\Purchases\SoundKitPayments;
use App\Domain\SoundKits\Purchases\SoundKitPurchasePolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
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
                ->orderByRaw($this->priority(), [SoundKitPayment::class, 'payment_changed', SoundKitPayment::class])
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

    /**
     * Fair selection without a new column: orders never refused first, then orders whose only recorded refusal is transient, and
     * orders audited as `payment_changed` (they need an operator, and each is retried only when nothing newer is waiting) last. 25 or
     * more permanently refused orders therefore cannot starve a newer retryable one. The refusal is audited once per reason on the
     * order's payment ({@see SoundKitPayments::settle()}).
     */
    private function priority(): string
    {
        $refused = "from audit_events a where a.action = 'sound_kit.test_grant.refused' and a.subject_type = ?"
            .' and a.subject_id = (select p.id from sound_kit_payments p where p.sound_kit_order_id = sound_kit_orders.id)';
        $changed = $refused.(DB::getDriverName() === 'sqlite' ? " and json_extract(a.context, '$.reason') = ?" : " and json_unquote(json_extract(a.context, '$.reason')) = ?");

        return "case when exists (select 1 {$changed}) then 2 when exists (select 1 {$refused}) then 1 else 0 end";
    }
}
