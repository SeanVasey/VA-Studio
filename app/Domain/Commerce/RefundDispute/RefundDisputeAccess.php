<?php

namespace App\Domain\Commerce\RefundDispute;

use App\Domain\Commerce\RefundDispute\Models\RefundDisputeHold;
use App\Domain\Delivery\DeliveryException;

/**
 * Download gate for active refund/dispute holds. Delivery calls it inside its own transaction after locking
 * the Order row, which every hold writer also takes first, so a hold either commits before the delivery
 * decision (and refuses it) or waits for it. The read is a current locking read even when the caller holds an
 * older REPEATABLE READ snapshot.
 *
 * The production tax chain's delivery (PaidTaxDownloads) calls `assertClear` with its batch id and the line's origin id, after its
 * frame has read the batch row under lock; its `$source` is `RefundDisputeSource::PRODUCTION_TAX`. A hold on one source never
 * affects an order of the other, even when their numeric ids are equal.
 */
final class RefundDisputeAccess
{
    public static function assertClear(int $orderId, int $licenseGrantId, string $source = RefundDisputeSource::TEST): void
    {
        $held = RefundDisputeHold::where('order_id', $orderId)->where('order_source', $source)->whereNull('released_at')
            ->where(fn ($query) => $query->whereNull('license_grant_id')->orWhere('license_grant_id', $licenseGrantId))
            ->lockForUpdate()->exists();
        if ($held) {
            throw new DeliveryException('blocked');
        }
    }

    /** Non-locking projection read for owner download listings; issuance and redemption still enforce the gate. */
    public static function heldGrantIds(int $orderId, string $source = RefundDisputeSource::TEST): array
    {
        $holds = RefundDisputeHold::where('order_id', $orderId)->where('order_source', $source)->whereNull('released_at')->get(['license_grant_id']);

        return ['order' => $holds->contains(fn ($hold) => $hold->license_grant_id === null),
            'grants' => $holds->pluck('license_grant_id')->filter()->values()->all()];
    }
}
