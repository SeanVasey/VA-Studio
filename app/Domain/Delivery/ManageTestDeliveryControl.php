<?php

namespace App\Domain\Delivery;

use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Orders\OrderRequest;
use App\Domain\Delivery\Models\TestDeliveryControl;
use App\Support\Audit\AuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Internal operator primitive. It changes technical test access, never a grant or license right. */
final class ManageTestDeliveryControl
{
    public function handle(string $orderPublicId, bool $blocked, int $expectedVersion, string $reference): TestDeliveryControl
    {
        ActivationPolicy::outsideTransactions();
        $policies = app(TestAccessPolicy::class); $account = $policies->environmentAccount();
        if (! $blocked) { $policies->current(); }
        if (! OrderRequest::uuid($orderPublicId) || $expectedVersion < 0 || $expectedVersion >= 4294967295
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:\/-]{0,191}\z/D', $reference) !== 1) { throw new DeliveryException('not_found'); }
        $order = Order::where('public_id', $orderPublicId)->first();
        if (! $order) { throw new DeliveryException('not_found'); }
        $evidence = app(DeliveryAccessEvidence::class); $source = $evidence->source($order, $account);
        return DB::transaction(function () use ($order, $blocked, $expectedVersion, $reference, $policies, $account, $evidence, $source) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();
            if (! $locked || $policies->environmentAccount() !== $account) { throw new DeliveryException('unavailable'); }
            if (! $blocked) { $policies->current(); }
            $fresh = $evidence->source($locked, $account);
            if ($fresh['snapshot_hash'] !== $source['snapshot_hash'] || $fresh['activation']->evidence_hash !== $source['activation']->evidence_hash) {
                throw new DeliveryException('changed');
            }
            $control = TestDeliveryControl::where('order_id', $locked->id)->lockForUpdate()->first();
            $at = now()->toImmutable()->utc()->startOfSecond();
            if ($at->lessThan($fresh['activation']->activated_at)) { throw new DeliveryException('retry'); }
            if (! $control) {
                if (! $blocked) { throw new DeliveryException('blocked'); }
                if ($expectedVersion !== 0) { throw new DeliveryException('conflict'); }
                $control = TestDeliveryControl::create(['public_id' => (string) Str::uuid(), 'order_id' => $locked->id,
                    'test_fulfillment_activation_id' => $fresh['activation']->id, 'blocked' => true, 'control_version' => 0,
                    'created_at' => $at, 'updated_at' => $at]);
                AuditEvent::record('commerce.delivery.control_provisioned', $control, ['order_public_id' => $locked->public_id,
                    'blocked' => true, 'control_version' => 0, 'reference_hash' => hash('sha256', $reference), 'test_only' => true]);
                return $control;
            }
            if ($control->test_fulfillment_activation_id !== $fresh['activation']->id || $control->created_at->greaterThan($control->updated_at)) {
                throw new DeliveryException('changed');
            }
            if ($control->control_version !== $expectedVersion) { throw new DeliveryException('conflict'); }
            if ($at->lessThan($control->updated_at)) { throw new DeliveryException('retry'); }
            if ($control->blocked === $blocked) { return $control; }
            // Reserve the final reachable even version for blocking; enabling must never exhaust the revocation transition.
            if (! $blocked && $expectedVersion >= 4294967294) { throw new DeliveryException('conflict'); }
            // The immutable-identity model permits changes only through the guarded SQL transition.
            DB::table('test_delivery_controls')->where('id', $control->id)->where('control_version', $expectedVersion)->update([
                'blocked' => $blocked, 'control_version' => $expectedVersion + 1, 'updated_at' => $at,
            ]);
            $control->refresh();
            AuditEvent::record('commerce.delivery.control_changed', $control, ['order_public_id' => $locked->public_id,
                'blocked' => $blocked, 'control_version' => $control->control_version,
                'reference_hash' => hash('sha256', $reference), 'test_only' => true]);
            return $control;
        }, 5);
    }
}
