<?php

namespace App\Domain\SoundKits\Purchases;

use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\SoundKits\Purchases\Models\SoundKitContract;
use App\Domain\SoundKits\Purchases\Models\SoundKitDeliveryControl;
use App\Domain\SoundKits\Purchases\Models\SoundKitGrant;
use App\Domain\SoundKits\Purchases\Models\SoundKitOrder;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * BL-13 staff view: the same operator steps as `vasey:issue-test-kit-contracts`, `vasey:control-test-kit-delivery` and
 * `vasey:reconcile-test-kit-order`, callable from the admin panel. It owns no business rule: every step calls the service the
 * command calls ({@see SoundKitContracts}, {@see SoundKitDelivery}, {@see SoundKitPayments}), which keep their own audit events (those
 * record no actor, as from the console). This class adds one actor-attributed `sound_kit.staff_action.*` event per attempt, success or
 * refusal, so the Filament user who pressed the button is recorded. The lane gate and the catalog permission are enforced here, not
 * only in navigation. A refusal never carries private detail: only a stable, staff-readable reason.
 */
final class SoundKitStaffOperations
{
    public const PERMISSION = 'administer-catalog';

    /** Whether the kit lane is open and this user may operate it; a closed lane is indistinguishable from absence. */
    public function allows(?User $actor): bool
    {
        return $actor !== null && app(SoundKitPurchasePolicy::class)->admitted() && Gate::forUser($actor)->allows(self::PERMISSION);
    }

    /**
     * Order rows for the list, newest first: public id, price and the recorded progress only. The licensee, provider identifiers and
     * storage paths are never selected.
     *
     * @return Builder<SoundKitOrder>
     */
    public function orders(): Builder
    {
        return SoundKitOrder::query()->select(['sound_kit_orders.id', 'sound_kit_orders.public_id', 'sound_kit_orders.snapshot',
            'sound_kit_orders.price_minor', 'sound_kit_orders.currency', 'sound_kit_orders.created_at'])
            ->selectRaw('(select count(*) from sound_kit_checkout_sessions s where s.sound_kit_order_id = sound_kit_orders.id) as has_checkout')
            ->selectRaw('(select count(*) from sound_kit_payments p where p.sound_kit_order_id = sound_kit_orders.id) as has_payment')
            ->selectRaw('(select count(*) from sound_kit_grants g where g.sound_kit_order_id = sound_kit_orders.id) as has_grant')
            ->selectRaw('(select count(*) from sound_kit_contracts c join sound_kit_grants g2 on g2.id = c.sound_kit_grant_id where g2.sound_kit_order_id = sound_kit_orders.id) as has_contract')
            ->selectRaw('(select d.state from sound_kit_delivery_controls d where d.sound_kit_order_id = sound_kit_orders.id) as delivery_state')
            ->selectRaw('(select d2.version from sound_kit_delivery_controls d2 where d2.sound_kit_order_id = sound_kit_orders.id) as delivery_version');
    }

    /** One plain-language stage from the recorded flags (see {@see orders()}). */
    public function stage(SoundKitOrder $order): string
    {
        return match (true) {
            (int) $order->has_grant === 0 && (int) $order->has_payment === 0 => (int) $order->has_checkout === 0 ? 'Order started' : 'Awaiting payment',
            (int) $order->has_grant === 0 => 'Paid, grant pending',
            (int) $order->has_contract === 0 => 'Granted, contract pending',
            $order->delivery_state === 'enabled' => 'Delivery enabled',
            $order->delivery_state === 'blocked' => 'Delivery blocked',
            default => 'Contract issued, delivery not enabled',
        };
    }

    /** The control version a delivery action must carry (0 = never set), read fresh when the action opens. */
    public function deliveryVersion(string $orderPublicId): int
    {
        $order = SoundKitOrder::query()->where('public_id', $orderPublicId)->first();

        return (int) ($order ? SoundKitDeliveryControl::query()->where('sound_kit_order_id', $order->id)->value('version') : 0);
    }

    /** @return array{ok: bool, message: string} */
    public function issueContract(User $actor, string $orderPublicId): array
    {
        return $this->run($actor, 'contract_issue', $orderPublicId, function (SoundKitOrder $order): array {
            $grant = SoundKitGrant::query()->where('sound_kit_order_id', $order->id)->first();
            if (! $grant) {
                return [false, 'This order has no grant yet, so there is no contract to issue.'];
            }
            if (SoundKitContract::query()->where('sound_kit_grant_id', $grant->id)->exists()) {
                return [false, 'A contract original is already recorded for this order.'];
            }
            try {
                $contract = app(SoundKitContracts::class)->issue($grant);
            } catch (Throwable $error) {
                return [false, 'The contract was not issued: '.($error instanceof ContractIssuanceException ? $error->reason : 'unavailable').'.'];
            }

            return [true, 'Contract original recorded (sha256 '.substr($contract->sha256, 0, 12).').'];
        });
    }

    /** @return array{ok: bool, message: string} */
    public function controlDelivery(User $actor, string $orderPublicId, string $action, int $expectedVersion, string $reference): array
    {
        return $this->run($actor, 'delivery_'.$action, $orderPublicId, function () use ($orderPublicId, $action, $expectedVersion, $reference): array {
            try {
                $control = app(SoundKitDelivery::class)->control($orderPublicId, $action, $expectedVersion, $reference);
            } catch (SoundKitPurchaseException $error) {
                return [false, match ($error->errorCode) {
                    'IDEMPOTENCY_CONFLICT' => 'Another change was recorded first. Close this dialog, reopen it and review the current state.',
                    'NOT_FOUND' => 'This order has no grant, so delivery cannot be controlled.',
                    'INVALID_REQUEST' => 'The reference must be 3 to 128 letters, digits, dots, colons, dashes or underscores, starting with a letter or digit.',
                    default => 'The change was not recorded.',
                }];
            } catch (Throwable) {
                return [false, 'The change was not recorded.'];
            }

            return [true, 'Delivery is now '.$control->state.' (version '.$control->version.').'];
        });
    }

    /** @return array{ok: bool, message: string} */
    public function recheckPayment(User $actor, string $orderPublicId): array
    {
        return $this->run($actor, 'payment_recheck', $orderPublicId, function (SoundKitOrder $order): array {
            $outcome = app(SoundKitPayments::class)->reconcile($order);
            if ($outcome === 'granted') {
                return [true, 'Payment verified and the kit is granted.'];
            }

            return [false, 'Not granted: '.match ($outcome) {
                'payment_changed' => 'the payment or purchased kit no longer matches the order (payment_changed). An operator must inspect it.',
                'awaiting_grant' => 'the payment is verified and recorded, but the grant was refused (awaiting_grant). The scheduled sweep retries it; an operator must inspect it if it keeps failing.',
                'unmatched' => 'no Checkout session was started for this order (unmatched).',
                'retry' => 'the check could not finish; try again shortly (retry).',
                default => 'the provider evidence did not establish a verified payment ('.preg_replace('/[^a-z_]/', '', $outcome).').',
            }];
        });
    }

    /**
     * @param  callable(SoundKitOrder): array{0: bool, 1: string}  $step
     * @return array{ok: bool, message: string}
     */
    private function run(User $actor, string $kind, string $orderPublicId, callable $step): array
    {
        if (! $this->allows($actor)) {
            return ['ok' => false, 'message' => 'Not available.'];
        }
        $order = SoundKitOrder::query()->where('public_id', $orderPublicId)->first();
        if (! $order) {
            return ['ok' => false, 'message' => 'This order was not found.'];
        }
        [$ok, $message] = $step($order);
        try {
            AuditEvent::recordAttributed('sound_kit.staff_action.'.$kind, $order, ['order_public_id' => $order->public_id,
                'result' => $ok ? 'accepted' : 'refused', 'test_only' => true], $actor->getKey());
        } catch (Throwable) {
            // The service already audited its own change; the attribution row is best effort and never changes the outcome.
        }

        return ['ok' => $ok, 'message' => $message];
    }
}
