<?php

namespace App\Domain\Grants;

use App\Domain\Commerce\ProductionCheckout\CheckoutSchema;
use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutSchema;
use App\Support\Audit\AuditEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * D-30 (decided 2026-11-08): the durable, staff-visible record of a paid order whose fulfillment paid-grant finalization holds
 * because its frozen v2 contract identity declaration no longer verifies (`contract_identity_held`).
 *
 * Finalization refuses inside a transaction that rolls back, so the record is written afterwards, in its own transaction, as
 * one append-only `audit_events` row per order: the paid order's row id as the subject, and only the chain, the reason and
 * the order's public id as context (no buyer name, address or other declaration field). Retries are idempotent: the write
 * locks the order row and appends only when no record for that order exists. Only the hold path calls this, after the
 * finalizer's ownership checks and its locked read of the paid source.
 */
final class ContractIdentityHolds
{
    public const ACTION = 'paid_order.contract_identity_held';

    public const REASON = 'contract_identity_held';

    /** chain => [order table, audit subject type] */
    public const CHAINS = [
        'production_checkout_v1' => [CheckoutSchema::TABLES['order'], 'production_checkout_order'],
        'production_tax_checkout_v2' => [TaxCheckoutSchema::TABLES['order'], 'production_tax_checkout_order'],
    ];

    /**
     * Called by a finalizer with the hold it is about to rethrow. A failed write is reported, never swallowed silently, and
     * never replaces the hold refusal: the next finalization attempt writes the record.
     */
    public static function recordAfterHold(string $chain, string $orderPublicId): void
    {
        try {
            self::record($chain, $orderPublicId);
        } catch (\Throwable $error) {
            report($error);
        }
    }

    public static function record(string $chain, string $orderPublicId): void
    {
        [$table, $subject] = self::CHAINS[$chain] ?? throw new InvalidArgumentException('Unknown paid-order chain.');
        DB::transaction(function () use ($chain, $orderPublicId, $table, $subject): void {
            $order = DB::table($table)->where('public_id', $orderPublicId)->lockForUpdate()->first(['id']);
            if ($order === null) {
                return;
            }
            $held = AuditEvent::query()->where('action', self::ACTION)->where('subject_type', $subject)
                ->where('subject_id', (int) $order->id)->exists();
            if (! $held) {
                AuditEvent::create(['actor_id' => null, 'action' => self::ACTION, 'subject_type' => $subject, 'subject_id' => (int) $order->id,
                    'context' => ['chain' => $chain, 'reason' => self::REASON, 'order_public_id' => $orderPublicId]]);
            }
        });
    }

    /** Every recorded hold, for staff only: the current user's catalog administration authority is checked on the server. */
    public static function staffQuery(): Builder
    {
        Gate::authorize('administer-catalog');

        return AuditEvent::query()->where('action', self::ACTION)
            ->whereIn('subject_type', array_column(self::CHAINS, 1));
    }
}
