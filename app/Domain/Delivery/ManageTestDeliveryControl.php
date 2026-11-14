<?php

namespace App\Domain\Delivery;

use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Orders\OrderRequest;
use App\Domain\Commerce\RefundDispute\RefundDisputeAccess;
use App\Domain\Delivery\Models\TestDeliveryControl;
use App\Support\Audit\AuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Internal operator primitive. It changes technical test access, never a grant or license right. */
final class ManageTestDeliveryControl
{
    public function handle(string $orderPublicId, bool $blocked, int $expectedVersion, string $reference): TestDeliveryControl
    {
        ActivationPolicy::outsideTransactions();
        $policies = app(TestAccessPolicy::class);
        $account = $policies->environmentAccount();
        if (! $blocked) {
            $policies->current();
        }
        if (! OrderRequest::uuid($orderPublicId) || $expectedVersion < 0 || $expectedVersion >= 4294967295
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:\/-]{0,191}\z/D', $reference) !== 1) {
            throw new DeliveryException('not_found');
        }
        $order = Order::where('public_id', $orderPublicId)->first();
        if (! $order) {
            throw new DeliveryException('not_found');
        }
        $evidence = app(DeliveryAccessEvidence::class);
        $source = $evidence->source($order, $account);

        return DB::transaction(function () use ($order, $blocked, $expectedVersion, $reference, $policies, $account, $evidence, $source) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();
            if (! $locked || $policies->environmentAccount() !== $account) {
                throw new DeliveryException('unavailable');
            }
            if (! $blocked) {
                $policies->current();
            }
            $fresh = $this->unchanged($locked, $account, $evidence, $source);
            $control = TestDeliveryControl::where('order_id', $locked->id)->lockForUpdate()->first();
            $at = now()->toImmutable()->utc()->startOfSecond();
            if ($at->lessThan($fresh['activation']->activated_at)) {
                throw new DeliveryException('retry');
            }
            if (! $control) {
                if (! $blocked) {
                    throw new DeliveryException('blocked');
                }
                if ($expectedVersion !== 0) {
                    throw new DeliveryException('conflict');
                }

                return $this->provision($locked, $fresh, $at, $reference, false);
            }
            if ($control->test_fulfillment_activation_id !== $fresh['activation']->id || $control->created_at->greaterThan($control->updated_at)) {
                throw new DeliveryException('changed');
            }
            if ($control->control_version !== $expectedVersion) {
                throw new DeliveryException('conflict');
            }
            if ($at->lessThan($control->updated_at)) {
                throw new DeliveryException('retry');
            }
            if ($control->blocked === $blocked) {
                return $control;
            }
            // Reserve the final reachable even version for blocking; enabling must never exhaust the revocation transition.
            if (! $blocked && $expectedVersion >= 4294967294) {
                throw new DeliveryException('conflict');
            }

            return $this->transition($locked, $control, $blocked, $at, $reference, false);
        }, 5);
    }

    /**
     * The optional automatic enable ({@see TestDeliveryAutoEnablePolicy}, D-19 amendment 2026-11-12). Under one order lock it records
     * the same two audited transitions staff and the console record (provisioned blocked at version 0, then enabled at version 1),
     * attributed to no actor, plus one `commerce.delivery.auto_enabled` event naming the policy. It acts only on an order that never
     * had a control: an existing control, blocked or enabled by anyone, returns null and is never touched, so a person's decision
     * is final. An active refund/dispute hold on the order also returns null (issuance and redemption refuse it anyway). Every
     * check {@see handle()} applies to an enable applies here: environment/account, the current access policy, complete activation
     * evidence unchanged since it was read outside the transaction, and the order → control lock order.
     */
    public function enableAutomatically(string $orderPublicId): ?TestDeliveryControl
    {
        ActivationPolicy::outsideTransactions();
        $automatic = app(TestDeliveryAutoEnablePolicy::class);
        try {
            $automatic->current();
        } catch (\RuntimeException) {
            throw new DeliveryException('unavailable');
        }
        $policies = app(TestAccessPolicy::class);
        $account = $policies->environmentAccount();
        $policies->current();
        if (! OrderRequest::uuid($orderPublicId)) {
            throw new DeliveryException('not_found');
        }
        $order = Order::where('public_id', $orderPublicId)->first();
        if (! $order) {
            throw new DeliveryException('not_found');
        }
        $evidence = app(DeliveryAccessEvidence::class);
        $source = $evidence->source($order, $account);

        return DB::transaction(function () use ($order, $policies, $account, $evidence, $source, $automatic): ?TestDeliveryControl {
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();
            if (! $locked || $policies->environmentAccount() !== $account) {
                throw new DeliveryException('unavailable');
            }
            $policies->current();
            try {
                $automatic->current();
            } catch (\RuntimeException) {
                throw new DeliveryException('unavailable');
            }
            $fresh = $this->unchanged($locked, $account, $evidence, $source);
            if (TestDeliveryControl::where('order_id', $locked->id)->lockForUpdate()->exists()) {
                return null;
            }
            if (RefundDisputeAccess::anyHeld($locked->id)) {
                return null;
            }
            $at = now()->toImmutable()->utc()->startOfSecond();
            if ($at->lessThan($fresh['activation']->activated_at)) {
                throw new DeliveryException('retry');
            }
            $control = $this->transition($locked, $this->provision($locked, $fresh, $at, TestDeliveryAutoEnablePolicy::REFERENCE, true),
                false, $at, TestDeliveryAutoEnablePolicy::REFERENCE, true);
            AuditEvent::recordAttributed('commerce.delivery.auto_enabled', $control, ['order_public_id' => $locked->public_id,
                'control_version' => $control->control_version, 'policy_version' => TestDeliveryAutoEnablePolicy::VERSION, 'test_only' => true], null);

            return $control;
        }, 5);
    }

    /** @return array<string, mixed> the evidence source re-read under the order lock; it must match what was read before the lock */
    private function unchanged(Order $locked, string $account, DeliveryAccessEvidence $evidence, array $source): array
    {
        $fresh = $evidence->source($locked, $account);
        if ($fresh['snapshot_hash'] !== $source['snapshot_hash'] || $fresh['activation']->evidence_hash !== $source['activation']->evidence_hash) {
            throw new DeliveryException('changed');
        }

        return $fresh;
    }

    private function provision(Order $locked, array $fresh, CarbonImmutable $at, string $reference, bool $system): TestDeliveryControl
    {
        $control = TestDeliveryControl::create(['public_id' => (string) Str::uuid(), 'order_id' => $locked->id,
            'test_fulfillment_activation_id' => $fresh['activation']->id, 'blocked' => true, 'control_version' => 0,
            'created_at' => $at, 'updated_at' => $at]);
        $this->audit('commerce.delivery.control_provisioned', $control, ['order_public_id' => $locked->public_id,
            'blocked' => true, 'control_version' => 0, 'reference_hash' => hash('sha256', $reference), 'test_only' => true], $system);

        return $control;
    }

    private function transition(Order $locked, TestDeliveryControl $control, bool $blocked, CarbonImmutable $at, string $reference, bool $system): TestDeliveryControl
    {
        $expectedVersion = $control->control_version;
        // The immutable-identity model permits changes only through the guarded SQL transition.
        DB::table('test_delivery_controls')->where('id', $control->id)->where('control_version', $expectedVersion)->update([
            'blocked' => $blocked, 'control_version' => $expectedVersion + 1, 'updated_at' => $at,
        ]);
        $control->refresh();
        $this->audit('commerce.delivery.control_changed', $control, ['order_public_id' => $locked->public_id,
            'blocked' => $blocked, 'control_version' => $control->control_version,
            'reference_hash' => hash('sha256', $reference), 'test_only' => true], $system);

        return $control;
    }

    /** Staff and console changes keep their ambient attribution; an automatic change is attributed to no actor. */
    private function audit(string $action, TestDeliveryControl $control, array $context, bool $system): void
    {
        $system ? AuditEvent::recordAttributed($action, $control, $context, null) : AuditEvent::record($action, $control, $context);
    }
}
