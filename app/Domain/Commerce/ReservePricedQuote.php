<?php

namespace App\Domain\Commerce;

use App\Domain\Commerce\Inventory\InventoryPolicy;
use App\Domain\Commerce\Inventory\ReserveQuoteInventory;
use App\Domain\Commerce\Models\InventoryReservation;
use App\Domain\Commerce\Models\PromotionUse;
use App\Domain\Commerce\Models\QuotePricing;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Internal test orchestration. Retained resources are not a payable order or a rights grant. */
final class ReservePricedQuote
{
    /** @return array{pricing: QuotePricing, reservation: InventoryReservation, promotion_use: ?PromotionUse} */
    public function hold(string $quoteId, string $ownerKey, ?string $promotionCode = null, ?User $actor = null): array
    {
        InventoryPolicy::requireTestEnvironment();

        return DB::transaction(function () use ($quoteId, $ownerKey, $promotionCode, $actor) {
            // Explicit customer identity precedes resource locks; null remains anonymous/system.
            $actorId = app(CommerceAuditActor::class)->lock($actor);
            $prices = app(PriceQuote::class);
            $pricing = $promotionCode === null ? $prices->create($quoteId, $ownerKey, $actor) :
                $prices->createWithPromotion($quoteId, $ownerKey, $promotionCode, $actor);
            $use = isset($pricing->snapshot['promotion']) ? app(PromotionUsage::class)->currentUse($pricing) : null;
            // Quote -> sorted tracks/offers -> pricing -> campaign/use -> sorted scopes/claims.
            // Inventory revalidates this SAME quote; all its earlier row locks are already retained.
            $reservation = app(ReserveQuoteInventory::class)->hold($quoteId, $ownerKey, $actor);

            return $this->result($pricing, $reservation, $use);
        }, 5);
    }

    /** Requires an existing hold. A future order/intent must commit in the enclosing transaction before provider I/O. */
    public function beginAttempt(string $quoteId, string $ownerKey, string $attemptId, ?User $actor = null): array
    {
        InventoryPolicy::requireTestEnvironment();
        if (! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $attemptId)) {
            throw new QuoteException('INVALID_QUOTE_REQUEST', 422);
        }

        return DB::transaction(function () use ($quoteId, $ownerKey, $attemptId, $actor) {
            // Explicit customer identity precedes resource locks; null remains anonymous/system.
            $actorId = app(CommerceAuditActor::class)->lock($actor);
            $pricing = app(PriceQuote::class)->read($quoteId, $ownerKey, $actor);
            // Bind promotion first. If inventory rejects, its pending transition and audit roll back too.
            $use = isset($pricing->snapshot['promotion']) ?
                app(PromotionUsage::class)->beginAttempt($quoteId, $ownerKey, $attemptId, $actor) : null;
            $reservation = app(ReserveQuoteInventory::class)->beginAttempt($quoteId, $ownerKey, $attemptId, $actor);

            return $this->result($pricing, $reservation, $use);
        }, 5);
    }

    private function result(QuotePricing $pricing, InventoryReservation $reservation, ?PromotionUse $use): array
    {
        if ($pricing->quote_id !== $reservation->quote_id || ($use !== null &&
            ($use->quote_pricing_id !== $pricing->id || $use->state !== $reservation->state || $use->attempt_id !== $reservation->attempt_id))) {
            throw new QuoteException('PRICED_INVENTORY_CHANGED', 409);
        }
        // Scope lock waits can cross the earlier pricing deadline. Never extend a price by acquiring inventory.
        if ($pricing->expires_at->lessThanOrEqualTo(now())) {
            throw new QuoteException('PRICING_EXPIRED', 410);
        }

        return ['pricing' => $pricing, 'reservation' => $reservation, 'promotion_use' => $use];
    }
}
