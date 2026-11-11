<?php

namespace App\Domain\Delivery;

use App\Domain\Commerce\Models\Order;
use App\Domain\Delivery\Models\TestDeliveryControl;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\Audit\AuditEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Staff view of Stripe-test track orders: the same enable/block step as `vasey:control-test-delivery`, callable from the admin panel.
 * It owns no business rule. Every change goes through {@see ManageTestDeliveryControl}, which keeps its environment/account/policy
 * admission, complete-activation requirement, order → control locks, expected-version check and its own
 * `commerce.delivery.control_provisioned` / `commerce.delivery.control_changed` audit events. This class adds the admission, the
 * catalog permission and admin MFA on the server (not only in navigation), and one actor-attributed
 * `commerce.delivery.staff_action.*` event per attempt, success or refusal. A refusal carries only a stable, staff-readable reason.
 */
final class TestDeliveryStaffOperations
{
    public const PERMISSION = 'administer-catalog';

    public const REFERENCE = '/\A[A-Za-z0-9][A-Za-z0-9._:\/-]{0,191}\z/D';

    /** Exactly where the console command can act at all: a test-commerce environment, Stripe test mode and a configured `acct_` account. */
    public function admitted(): bool
    {
        try {
            app(TestAccessPolicy::class)->environmentAccount();

            return true;
        } catch (DeliveryException) {
            return false;
        }
    }

    /** Re-reads the actor: the permission and the panel's MFA requirement apply to every caller, not only the Filament page. */
    public function allows(?User $actor): bool
    {
        return $actor !== null && $this->admitted() && Gate::forUser($actor)->allows(self::PERMISSION)
            && AdminMultiFactor::satisfiedBy($actor);
    }

    /**
     * Track orders that reached Stripe test Checkout for the configured account, newest first, with recorded progress only. The buyer's
     * identity (only a test customer's public id, else Guest), owner keys, provider identifiers, evidence and file paths are never selected.
     *
     * @return Builder<Order>
     */
    public function orders(): Builder
    {
        try {
            $account = app(TestAccessPolicy::class)->environmentAccount();
        } catch (DeliveryException) {
            return Order::query()->whereRaw('1 = 0');
        }
        $first = 'SELECT %s FROM order_lines l JOIN offer_revisions r ON r.id = l.offer_revision_id %s WHERE l.order_id = orders.id ORDER BY l.position LIMIT 1';

        return Order::query()
            ->join('checkout_intents as ci', 'ci.order_id', '=', 'orders.id')
            ->leftJoin('customer_accounts as ca', 'ca.owner_key', '=', 'orders.owner_key')
            ->where('ci.mode', 'test')->where('ci.account_id', $account)
            ->select(['orders.id', 'orders.public_id', 'orders.created_at', 'ca.public_id as customer_public_id'])
            ->selectRaw('('.sprintf($first, 't.title', 'JOIN tracks t ON t.id = r.track_id').') AS track_title')
            ->selectRaw('('.sprintf($first, 'lt.name', 'JOIN license_versions v ON v.id = r.license_version_id JOIN license_templates lt ON lt.id = v.license_template_id').') AS license_name')
            ->selectRaw('(SELECT COUNT(*) FROM order_lines l2 WHERE l2.order_id = orders.id) AS line_count')
            ->selectRaw("(SELECT COUNT(*) FROM verified_payments p WHERE p.order_id = orders.id AND p.mode = 'test' AND p.account_id = ?) AS has_payment", [$account])
            ->selectRaw("(SELECT f.outcome FROM order_finalizations f WHERE f.order_id = orders.id AND f.mode = 'test') AS finalization")
            ->selectRaw('(SELECT COUNT(*) FROM license_grants g JOIN order_finalizations f2 ON f2.id = g.order_finalization_id WHERE f2.order_id = orders.id) AS grant_count')
            ->selectRaw('(SELECT COUNT(*) FROM grant_contracts c JOIN license_grants g2 ON g2.id = c.license_grant_id JOIN order_finalizations f3 ON f3.id = g2.order_finalization_id WHERE f3.order_id = orders.id) AS contract_count')
            ->selectRaw('(SELECT COUNT(*) FROM test_fulfillment_activations a WHERE a.order_id = orders.id) AS activated')
            ->selectRaw('(SELECT d.blocked FROM test_delivery_controls d WHERE d.order_id = orders.id) AS delivery_blocked')
            ->selectRaw('(SELECT d2.control_version FROM test_delivery_controls d2 WHERE d2.order_id = orders.id) AS delivery_version');
    }

    /** "Awaiting delivery": activated, and delivery never enabled or currently blocked. */
    public static function filterAwaiting(Builder $query): Builder
    {
        return $query->whereExists(fn ($activation) => $activation->selectRaw('1')->from('test_fulfillment_activations as fa')->whereColumn('fa.order_id', 'orders.id'))
            ->whereNotExists(fn ($control) => $control->selectRaw('1')->from('test_delivery_controls as dc')->whereColumn('dc.order_id', 'orders.id')->where('dc.blocked', false));
    }

    /** The control's state from the recorded row: null when no control exists yet. */
    public function deliveryState(Order $order): ?string
    {
        $blocked = $order->getAttribute('delivery_blocked');

        return $blocked === null ? null : ((bool) (int) $blocked ? 'blocked' : 'enabled');
    }

    /** One plain-language stage from the recorded progress (see {@see orders()}). */
    public function stage(Order $order): string
    {
        $grants = (int) $order->getAttribute('grant_count');

        return match (true) {
            (int) $order->getAttribute('has_payment') === 0 => 'Awaiting payment',
            $order->getAttribute('finalization') === 'paid_exception' => 'Paid exception, no grant',
            $grants === 0 => 'Paid, grant pending',
            (int) $order->getAttribute('contract_count') < $grants => 'Granted, contract pending',
            (int) $order->getAttribute('activated') === 0 => 'Contract issued, activation pending',
            $this->deliveryState($order) === 'enabled' => 'Delivery enabled',
            $this->deliveryState($order) === 'blocked' => 'Delivery blocked',
            default => 'Ready, delivery not enabled',
        };
    }

    /** The control version a delivery action must carry (0 = never provisioned), read fresh when the action opens. */
    public function deliveryVersion(string $orderPublicId): int
    {
        $order = Order::query()->where('public_id', $orderPublicId)->first();

        return (int) ($order ? TestDeliveryControl::query()->where('order_id', $order->id)->value('control_version') : 0);
    }

    /**
     * Enable or block delivery for one order. A never-provisioned order is first provisioned blocked at version 0 and then enabled from
     * version 0, the same two audited transitions the console documents (`block --expected-version=0`, then `enable --expected-version=0`).
     * Asking for the state the order already has returns that state and records no control change.
     *
     * @return array{ok: bool, message: string}
     */
    public function controlDelivery(User $actor, string $orderPublicId, string $action, int $expectedVersion, string $reference): array
    {
        if (! $this->allows($actor) || ! in_array($action, ['enable', 'block'], true)) {
            return ['ok' => false, 'message' => 'Not available.'];
        }
        $order = $this->orders()->where('orders.public_id', $orderPublicId)->first();
        if (! $order) {
            return ['ok' => false, 'message' => 'This order was not found.'];
        }
        $blocked = $action === 'block';
        $outcome = 'refused';
        $version = null;
        if (preg_match(self::REFERENCE, $reference) !== 1 || $expectedVersion < 0) {
            $message = 'The reference must be 1 to 192 letters, digits, dots, colons, slashes, dashes or underscores, starting with a letter or digit.';
        } else {
            [$outcome, $message, $version] = $this->apply($actor, $order, $blocked, $expectedVersion, $reference);
        }
        try {
            AuditEvent::recordAttributed('commerce.delivery.staff_action.delivery_'.$action, $order, ['order_public_id' => $order->public_id,
                'result' => $outcome, 'control_version' => $version, 'reference_hash' => hash('sha256', $reference), 'test_only' => true], $actor->getKey());
        } catch (Throwable) {
            // The control service already audited its own change; the attribution row is best effort and never changes the outcome.
        }

        return ['ok' => $outcome !== 'refused', 'message' => $message];
    }

    /** @return array{0: string, 1: string, 2: ?int} outcome (accepted, unchanged or refused), message, resulting version */
    private function apply(User $actor, Order $order, bool $blocked, int $expectedVersion, string $reference): array
    {
        $manage = app(ManageTestDeliveryControl::class);
        $current = TestDeliveryControl::query()->where('order_id', $order->id)->first();
        if ($current !== null && $current->blocked === $blocked) {
            return ['unchanged', $this->already($current), $current->control_version];
        }
        try {
            if ($current === null && $expectedVersion === 0) {
                if (! $blocked) {
                    // Refuse before provisioning when enabling would be refused anyway; the control service checks it again.
                    app(TestAccessPolicy::class)->current();
                }
                $current = $manage->handle($order->public_id, true, 0, $reference);
                if ($blocked) {
                    return ['accepted', 'Delivery control recorded as blocked (version '.$current->control_version.').', $current->control_version];
                }
            }
            $control = $manage->handle($order->public_id, $blocked, $expectedVersion, $reference);
        } catch (DeliveryException $error) {
            $now = TestDeliveryControl::query()->where('order_id', $order->id)->first();
            if ($now !== null && $now->blocked === $blocked) {
                return ['unchanged', $this->already($now), $now->control_version];
            }

            return ['refused', $this->reason($error->reason, $order), $now?->control_version];
        } catch (Throwable) {
            return ['refused', 'The change was not recorded.', null];
        }

        return ['accepted', 'Delivery is now '.($control->blocked ? 'blocked' : 'enabled').' (version '.$control->control_version.').', $control->control_version];
    }

    private function already(TestDeliveryControl $control): string
    {
        return 'Delivery is already '.($control->blocked ? 'blocked' : 'enabled').' (version '.$control->control_version.'). Nothing changed.';
    }

    private function reason(string $reason, Order $order): string
    {
        return match ($reason) {
            'blocked' => match ($this->stage($order)) {
                'Awaiting payment' => 'This order has no verified test payment, so delivery cannot be enabled.',
                'Paid exception, no grant' => 'This order was paid as an exception and has no grant, so delivery cannot be enabled.',
                'Paid, grant pending' => 'This order has no grant yet, so delivery cannot be enabled.',
                'Granted, contract pending' => 'The license contract is not issued yet, so delivery cannot be enabled.',
                'Contract issued, activation pending' => 'The order is not activated yet. The test commerce pipeline activates it after the contract is issued; try again in a few minutes.',
                default => 'Delivery cannot be enabled for this order.',
            },
            'conflict' => 'Another change was recorded first. Close this dialog, reopen it and review the current state.',
            'unavailable' => 'Test delivery access is switched off or not configured on this host. Nothing changed.',
            'changed' => 'The order no longer matches its recorded activation evidence. Nothing changed; an operator must inspect it.',
            'retry' => 'The change could not be recorded yet; try again shortly.',
            default => 'The change was not recorded.',
        };
    }
}
