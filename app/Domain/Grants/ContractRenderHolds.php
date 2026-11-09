<?php

namespace App\Domain\Grants;

use App\Domain\Contracts\ContractIssuanceException;
use App\Support\Audit\AuditEvent;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * Contract increment 5 activation blocker (review of c5d0ce4c): the durable, staff-visible record of a paid order whose
 * `vasey-license-pdf-v2` original cannot be rendered. Selection builds the document but does not render it, so a refusal
 * only rendering finds (a character a face cannot draw, page or byte limits, `invalid_pdf`/`render_failed`, a signature
 * rotation or pinned-file change since selection) fails the line after payment. No retry fixes those; the order needs staff.
 *
 * Written exactly as {@see ContractIdentityHolds} writes its hold, with its own action: one append-only `audit_events` row per
 * order, the paid order's row id as the subject, and only the chain, the refusal code and the order's public id as context
 * (no buyer data). The document issuers call it after the failed attempt's own frames, outside any transaction; the write
 * locks the order row and appends only when the order has no render hold yet, so retries and further failed lines of the
 * same order leave the one record. A failed write is reported and swallowed: the caller still answers with the render's own
 * failure, and the next failed attempt writes the record. Only lines whose recorded profile is a v2 selection reach this,
 * so nothing changes while the selection gate is off.
 */
final class ContractRenderHolds
{
    public const ACTION = 'paid_order.contract_render_held';

    /** A v2 line that used up its attempts on failures not listed in {@see PERMANENT}. */
    public const ATTEMPTS_EXHAUSTED = 'attempts_exhausted';

    /**
     * Render refusals that no retry can fix. `unavailable` (an open transaction) and `storage_failed` (the private disk) are
     * not here: they are held only once the line's attempts are spent.
     */
    public const PERMANENT = ['profile_changed', 'signature_changed', 'signature_unavailable', 'signature_invalid',
        'unsupported_input', 'invalid_pdf', 'render_failed'];

    /**
     * The hold reason for a failed attempt of a v2 line, or null when the line may still succeed on a retry.
     *
     * @param  int  $attempt  The attempt that failed (1-based, as counted by the line's work row).
     */
    public static function reason(Throwable $error, int $attempt, int $maxAttempts): ?string
    {
        if ($error instanceof ContractIssuanceException && in_array($error->reason, self::PERMANENT, true)) {
            return $error->reason;
        }

        return $attempt >= $maxAttempts ? self::ATTEMPTS_EXHAUSTED : null;
    }

    /**
     * Called by a document issuer after a v2 line's failed attempt and its failure frame. Writes nothing for a null reason;
     * inside any open transaction it writes nothing and reports why. Never throws.
     */
    public static function recordAfterFailure(string $chain, string $orderPublicId, ?string $reason): void
    {
        if ($reason === null) {
            return;
        }
        try {
            if (DB::transactionLevel() !== 0) {
                throw new LogicException('A contract render hold is recorded only outside the failed attempt\'s transaction.');
            }
            self::record($chain, $orderPublicId, $reason);
        } catch (Throwable $error) {
            try {
                report($error);
            } catch (Throwable) {
            }
        }
    }

    public static function record(string $chain, string $orderPublicId, string $reason): void
    {
        [$table, $subject] = ContractIdentityHolds::CHAINS[$chain] ?? throw new InvalidArgumentException('Unknown paid-order chain.');
        if (! in_array($reason, [...self::PERMANENT, self::ATTEMPTS_EXHAUSTED], true)) {
            throw new InvalidArgumentException('Unknown contract render hold reason.');
        }
        DB::transaction(function () use ($chain, $orderPublicId, $reason, $table, $subject): void {
            $order = DB::table($table)->where('public_id', $orderPublicId)->lockForUpdate()->first(['id']);
            if ($order === null) {
                return;
            }
            $held = AuditEvent::query()->where('action', self::ACTION)->where('subject_type', $subject)
                ->where('subject_id', (int) $order->id)->exists();
            if (! $held) {
                AuditEvent::create(['actor_id' => null, 'action' => self::ACTION, 'subject_type' => $subject, 'subject_id' => (int) $order->id,
                    'context' => ['chain' => $chain, 'reason' => $reason, 'order_public_id' => $orderPublicId]]);
            }
        });
    }
}
