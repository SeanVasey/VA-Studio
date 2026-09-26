<?php

namespace App\Domain\Commerce\Orders;

use App\Domain\Commerce\Models\InventoryReservation;
use App\Domain\Commerce\Models\PromotionCampaign;
use App\Domain\Commerce\Models\PromotionUse;
use App\Domain\Commerce\Models\Quote;
use App\Domain\Commerce\Models\QuotePricing;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\Finalization\FinalizationEvidence;
use App\Domain\Commerce\QuoteException;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/** Reproducible retained evidence, independent of today's availability and configuration. */
final class OrderEvidence
{
    public const MAX_BYTES = 16777216;

    public function capture(string $orderId, string $attemptId, Quote $quote, QuotePricing $pricing,
        InventoryReservation $reservation, ?PromotionUse $use, array $policy,
        #[SensitiveParameter] array $request, CarbonImmutable $at): array
    {
        return $this->reconstruct($orderId, $attemptId, $quote, $pricing, $reservation, $use, $policy, $request, $at);
    }

    public function historical(Order $order, $attempt, Quote $quote, QuotePricing $pricing,
        InventoryReservation $reservation, ?PromotionUse $use, array $policy,
        #[SensitiveParameter] array $request): array
    {
        $finalization = OrderFinalization::where('order_id', $order->id)->first();
        if ($finalization) {
            app(FinalizationEvidence::class)->resourceDisposition($finalization, $order, $attempt, $reservation, $use);
        } elseif ($reservation->consumed_at !== null || $use?->consumed_at !== null) {
            throw new QuoteException('ORDER_CHANGED', 409);
        }
        // Only this historical path can reconstruct a proved consumed resource as its original pending state.
        $originalReservation = clone $reservation;
        $originalUse = $use === null ? null : clone $use;
        if ($finalization?->outcome === 'paid') {
            $originalReservation->state = 'pending';
            if ($originalUse) { $originalUse->state = 'pending'; }
        }

        return $this->reconstruct($order->public_id, $attempt->public_id, $quote, $pricing,
            $originalReservation, $originalUse, $policy, $request, $order->created_at);
    }

    private function reconstruct(string $orderId, string $attemptId, Quote $quote, QuotePricing $pricing,
        InventoryReservation $reservation, ?PromotionUse $use, array $policy,
        #[SensitiveParameter] array $request, CarbonImmutable $at): array
    {
        if ($quote->canonicalization_version !== CanonicalJson::VERSION ||
            $pricing->canonicalization_version !== CanonicalJson::VERSION ||
            $reservation->canonicalization_version !== CanonicalJson::VERSION ||
            $pricing->quote_id !== $quote->id || $reservation->quote_id !== $quote->id ||
            CanonicalJson::hash($quote->snapshot) !== $quote->snapshot_hash ||
            CanonicalJson::hash($reservation->snapshot) !== $reservation->snapshot_hash ||
            ($reservation->snapshot['quote_public_id'] ?? null) !== $quote->public_id ||
            ($reservation->snapshot['quote_snapshot_hash'] ?? null) !== $quote->snapshot_hash ||
            $reservation->state !== 'pending' || $reservation->attempt_id !== $attemptId ||
            $reservation->pending_at === null || $reservation->pending_at->lessThan($reservation->created_at) ||
            $reservation->pending_at->greaterThanOrEqualTo($reservation->expires_at) || $reservation->pending_at->greaterThan($at)) {
            throw new QuoteException('ORDER_CHANGED', 409);
        }
        $review = app(ReviewOrder::class)->capture($quote, $pricing, $policy);
        if ($request['quoteId'] !== $quote->public_id || $request['reviewHash'] !== $review['reviewHash'] ||
            CanonicalJson::encode(OrderRequest::normalize($request)) !== CanonicalJson::encode($request)) {
            throw new QuoteException('ORDER_CHANGED', 409);
        }
        $references = $quote->lines()->orderBy('position')->get();
        $quoteLines = $quote->snapshot['lines']; $priceLines = $pricing->snapshot['lines'];
        if ($references->count() !== count($quoteLines) || count($priceLines) !== count($quoteLines)) {
            throw new QuoteException('ORDER_CHANGED', 409);
        }
        $lines = [];
        foreach ($quoteLines as $position => $line) {
            $reference = $references[$position]; $priceLine = $priceLines[$position];
            if ($reference->position !== $position || $reference->offer_revision_id !== $line['offer_revision_id'] ||
                $priceLine['offer_revision_id'] !== $line['offer_revision_id'] ||
                $reference->line_hash !== CanonicalJson::hash($line) ||
                $priceLine['disclosure_hash'] !== $review['items'][$position]['disclosure']['disclosureHash']) {
                throw new QuoteException('ORDER_CHANGED', 409);
            }
            $lines[] = ['position' => $position, 'quote_line_id' => $reference->id,
                'offer_revision_id' => $line['offer_revision_id'], 'quote_line_hash' => $reference->line_hash,
                'selection' => $line, 'pricing' => $priceLine, 'disclosure' => $review['items'][$position]['disclosure']];
        }
        $bindings = $reservation->snapshot['bindings'];
        $scopeIds = array_column($bindings, 'scope_id'); $revisionIds = array_column($bindings, 'offer_revision_id');
        $expectedRevisions = array_column($quoteLines, 'offer_revision_id'); sort($revisionIds); sort($expectedRevisions);
        $claims = DB::table('inventory_claims')->where('inventory_reservation_id', $reservation->id)
            ->orderBy('rights_scope_id')->pluck('rights_scope_id')->map(fn ($id) => (int) $id)->all();
        if ($claims !== $scopeIds || $revisionIds !== $expectedRevisions || count(array_unique($scopeIds)) !== count($scopeIds)) {
            throw new QuoteException('ORDER_CHANGED', 409);
        }
        $promotion = null;
        if (isset($pricing->snapshot['promotion'])) {
            $campaign = $use === null ? null : PromotionCampaign::find($use->promotion_campaign_id);
            if ($use === null || $campaign === null || $use->quote_pricing_id !== $pricing->id ||
                $use->state !== 'pending' || $use->attempt_id !== $attemptId || $use->pending_at === null ||
                $use->pending_at->lessThan($use->created_at) || $use->pending_at->greaterThanOrEqualTo($use->expires_at) ||
                $use->pending_at->greaterThan($at) || ! $use->created_at->equalTo($pricing->created_at) ||
                ! $use->expires_at->equalTo($pricing->expires_at) ||
                CanonicalJson::hash($campaign->snapshot) !== $campaign->snapshot_hash ||
                $campaign->snapshot_hash !== $pricing->snapshot['promotion_hash']) {
                throw new QuoteException('ORDER_CHANGED', 409);
            }
            $promotion = ['id' => $use->id, 'campaign_id' => $campaign->id, 'campaign_snapshot' => $campaign->snapshot,
                'campaign_snapshot_hash' => $campaign->snapshot_hash, 'created_at' => $use->created_at->toIso8601ZuluString(),
                'expires_at' => $use->expires_at->toIso8601ZuluString(), 'pending_at' => $use->pending_at->toIso8601ZuluString()];
        } elseif ($use !== null) {
            throw new QuoteException('ORDER_CHANGED', 409);
        }
        $expires = $quote->expires_at->min($pricing->expires_at)->min($reservation->expires_at);
        if ($at->lessThan($quote->created_at) || $at->lessThan($pricing->created_at) || $at->lessThan($reservation->created_at) ||
            $expires->lessThanOrEqualTo($at)) { throw new QuoteException('ORDER_CHANGED', 409); }
        $attempt = ['schema_version' => 1, 'purpose' => 'test_order_attempt', 'public_id' => $attemptId,
            'order_public_id' => $orderId, 'quote_public_id' => $quote->public_id, 'quote_snapshot_hash' => $quote->snapshot_hash,
            'pricing_public_id' => $pricing->public_id, 'pricing_snapshot_hash' => $pricing->snapshot_hash,
            'inventory' => ['id' => $reservation->id, 'public_id' => $reservation->public_id,
                'snapshot' => $reservation->snapshot, 'snapshot_hash' => $reservation->snapshot_hash,
                'created_at' => $reservation->created_at->toIso8601ZuluString(), 'expires_at' => $reservation->expires_at->toIso8601ZuluString(),
                'pending_at' => $reservation->pending_at->toIso8601ZuluString()], 'promotion' => $promotion,
            'created_at' => $at->toIso8601ZuluString(), 'expires_at' => $expires->toIso8601ZuluString()];

        return ['schema_version' => 1, 'purpose' => 'test_order_preparation', 'order_id' => $orderId,
            'attempt_id' => $attemptId, 'owner_key' => $quote->owner_key, 'created_at' => $at->toIso8601ZuluString(),
            'policy' => $policy, 'request' => $request, 'review' => $review,
            'quote' => ['id' => $quote->id, 'public_id' => $quote->public_id, 'snapshot_hash' => $quote->snapshot_hash, 'snapshot' => $quote->snapshot],
            'pricing' => ['id' => $pricing->id, 'public_id' => $pricing->public_id, 'snapshot_hash' => $pricing->snapshot_hash, 'snapshot' => $pricing->snapshot],
            'lines' => $lines, 'attempt' => $attempt];
    }
}
