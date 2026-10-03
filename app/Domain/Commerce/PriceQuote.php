<?php

namespace App\Domain\Commerce;

use App\Domain\Commerce\Models\QuotePricing;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class PriceQuote
{
    public function create(string $quoteId, string $ownerKey, ?User $actor = null): QuotePricing
    {
        return $this->handle($quoteId, $ownerKey, true, null, $actor);
    }

    public function read(string $quoteId, string $ownerKey, ?User $actor = null): QuotePricing
    {
        return $this->handle($quoteId, $ownerKey, false, null, $actor);
    }

    public function createWithPromotion(string $quoteId, string $ownerKey, string $code, ?User $actor = null): QuotePricing
    {
        return $this->handle($quoteId, $ownerKey, true, $code, $actor);
    }

    private function handle(string $quoteId, string $ownerKey, bool $create, ?string $code = null, ?User $actor = null): QuotePricing
    {
        return DB::transaction(function () use ($quoteId, $ownerKey, $create, $code, $actor) {
            // Explicit customer identity precedes resource locks; null remains anonymous/system.
            $actorId = app(CommerceAuditActor::class)->lock($actor);
            // The outer transaction retains ReadQuote's quote/track/offer locks until pricing commits.
            // One pricing per quote is the idempotency boundary, including concurrent first requests.
            $quote = app(ReadQuote::class)->handle($quoteId, $ownerKey);
            $pricing = QuotePricing::query()->where('quote_id', $quote->id)->lockForUpdate()->first();
            if (! $pricing && ! $create) {
                throw new QuoteException('PRICING_NOT_FOUND', 404);
            }
            if ($pricing && $pricing->expires_at->lessThanOrEqualTo(now())) {
                throw new QuoteException('PRICING_EXPIRED', 410);
            }
            if ($code !== null && ! PromotionPolicy::validCode($code)) {
                throw new QuoteException('INVALID_QUOTE_REQUEST', 422);
            }
            $storedCode = $pricing?->snapshot['promotion']['code'] ?? null;
            if ($pricing && $create && $storedCode !== $code) {
                throw new QuoteException('PRICING_CHANGED', 409);
            }
            $selectedCode = $create ? $code : $storedCode;
            $promotion = $selectedCode === null ? null : app(PromotionPolicy::class)->current($selectedCode);
            $policy = app(PricingPolicy::class)->current();
            $snapshots = app(PricingSnapshot::class);
            if ($pricing) {
                try {
                    $snapshot = $snapshots->verify($pricing, $quote);
                    if ($snapshot['policy_hash'] !== ($policy === null ? null : CanonicalJson::hash($policy)) ||
                        ($snapshot['promotion_hash'] ?? null) !== ($promotion === null ? null : CanonicalJson::hash($promotion))) {
                        throw new QuoteException('PRICING_CHANGED', 409);
                    }
                } catch (QueryException $exception) {
                    throw $exception;
                } catch (Throwable) {
                    throw new QuoteException('PRICING_CHANGED', 409);
                }
                if ($promotion !== null) {
                    app(PromotionUsage::class)->currentUse($pricing);
                }
            } else {
                $issuedAt = now()->toImmutable()->utc()->startOfSecond();
                if ($quote->expires_at->lessThanOrEqualTo($issuedAt)) {
                    throw new QuoteException('QUOTE_EXPIRED', 410);
                }
                $id = (string) Str::uuid();
                $snapshot = $promotion === null ? $snapshots->build($quote, $id, $issuedAt, $policy) :
                    $snapshots->buildWithPromotion($quote, $id, $issuedAt, $policy, $promotion);
                $pricing = QuotePricing::create([
                    'public_id' => $id, 'quote_id' => $quote->id, 'snapshot' => $snapshot,
                    'snapshot_hash' => CanonicalJson::hash($snapshot), 'canonicalization_version' => CanonicalJson::VERSION,
                    'created_at' => $issuedAt, 'expires_at' => $snapshot['expires_at'],
                ]);
                if ($promotion !== null) {
                    app(PromotionUsage::class)->hold($pricing, $actor);
                }
                AuditEvent::recordAttributed('commerce.quote.priced', $pricing, [
                    'public_id' => $id, 'quote_public_id' => $quote->public_id, 'snapshot_hash' => $pricing->snapshot_hash,
                    'tax_status' => $snapshot['tax_status'], 'policy_hash' => $snapshot['policy_hash'],
                ], $actorId);
            }
            if ($quote->snapshot['schema_version'] === 2) {
                // All pricing/campaign/use locks are already retained. A failed shared
                // scope acquisition rolls back first pricing and promotion creation.
                $inventory = app(Inventory\ReserveQuoteInventory::class);
                $create ? $inventory->hold($quoteId, $ownerKey, $actor) : $inventory->read($quoteId, $ownerKey, $actor);
            }
            // Policy validation/rendering must not extend a lifetime across a boundary.
            if ($pricing->expires_at->lessThanOrEqualTo(now())) {
                throw new QuoteException('PRICING_EXPIRED', 410);
            }

            return $pricing;
        }, 5);
    }
}
