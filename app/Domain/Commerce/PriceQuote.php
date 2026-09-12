<?php

namespace App\Domain\Commerce;

use App\Domain\Commerce\Models\QuotePricing;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class PriceQuote
{
    public function create(string $quoteId, string $ownerKey): QuotePricing
    {
        return $this->handle($quoteId, $ownerKey, true);
    }

    public function read(string $quoteId, string $ownerKey): QuotePricing
    {
        return $this->handle($quoteId, $ownerKey, false);
    }

    private function handle(string $quoteId, string $ownerKey, bool $create): QuotePricing
    {
        return DB::transaction(function () use ($quoteId, $ownerKey, $create) {
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
            $policy = app(PricingPolicy::class)->current();
            $snapshots = app(PricingSnapshot::class);
            if ($pricing) {
                try {
                    $snapshot = $snapshots->verify($pricing, $quote);
                    if ($snapshot['policy_hash'] !== ($policy === null ? null : CanonicalJson::hash($policy))) {
                        throw new QuoteException('PRICING_CHANGED', 409);
                    }
                } catch (QueryException $exception) {
                    throw $exception;
                } catch (Throwable) {
                    throw new QuoteException('PRICING_CHANGED', 409);
                }
            } else {
                $issuedAt = now()->toImmutable()->utc()->startOfSecond();
                if ($quote->expires_at->lessThanOrEqualTo($issuedAt)) {
                    throw new QuoteException('QUOTE_EXPIRED', 410);
                }
                $id = (string) Str::uuid();
                $snapshot = $snapshots->build($quote, $id, $issuedAt, $policy);
                $pricing = QuotePricing::create([
                    'public_id' => $id, 'quote_id' => $quote->id, 'snapshot' => $snapshot,
                    'snapshot_hash' => CanonicalJson::hash($snapshot), 'canonicalization_version' => CanonicalJson::VERSION,
                    'created_at' => $issuedAt, 'expires_at' => $snapshot['expires_at'],
                ]);
                AuditEvent::record('commerce.quote.priced', $pricing, [
                    'public_id' => $id, 'quote_public_id' => $quote->public_id, 'snapshot_hash' => $pricing->snapshot_hash,
                    'tax_status' => $snapshot['tax_status'], 'policy_hash' => $snapshot['policy_hash'],
                ]);
            }
            // Policy validation/rendering must not extend a lifetime across a boundary.
            if ($pricing->expires_at->lessThanOrEqualTo(now())) {
                throw new QuoteException('PRICING_EXPIRED', 410);
            }

            return $pricing;
        }, 5);
    }
}
