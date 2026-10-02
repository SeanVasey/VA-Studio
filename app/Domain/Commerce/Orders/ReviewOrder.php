<?php

namespace App\Domain\Commerce\Orders;

use App\Domain\Commerce\CommerceAuditActor;
use App\Domain\Commerce\Models\Quote;
use App\Domain\Commerce\Models\QuotePricing;
use App\Domain\Commerce\PriceQuote;
use App\Domain\Commerce\PricingSnapshot;
use App\Domain\Commerce\QuoteException;
use App\Domain\Commerce\QuoteLicenseDisclosure;
use App\Domain\Commerce\ReadQuote;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;

/** An owned, read-only presentation of existing pricing and exact frozen disclosures. */
final class ReviewOrder
{
    public function handle(string $quoteId, string $ownerKey, ?User $actor = null): array
    {
        $policy = app(OrderPolicy::class)->current();

        return DB::transaction(function () use ($quoteId, $ownerKey, $policy, $actor) {
            // Explicit customer identity precedes resource locks; null remains anonymous/system.
            $actorId = app(CommerceAuditActor::class)->lock($actor);
            $quote = app(ReadQuote::class)->handle($quoteId, $ownerKey);
            $pricing = app(PriceQuote::class)->read($quoteId, $ownerKey, $actor);

            return $this->capture($quote, $pricing, $policy);
        }, 5);
    }

    /** Caller authorizes/currently verifies for a fresh review; historical callers use retained records/policy. */
    public function capture(Quote $quote, QuotePricing $pricing, array $policy): array
    {
        OrderPolicy::validate($policy);
        $snapshot = app(PricingSnapshot::class)->verify($pricing, $quote);
        if ($snapshot['tax_status'] !== 'fixed_test' || ! is_int($snapshot['tax_minor']) ||
            ! is_int($snapshot['total_minor']) || $snapshot['total_minor'] < 0 || $snapshot['payable'] !== false) {
            throw new QuoteException('ORDER_PRICING_UNAVAILABLE', 409);
        }
        $data = ['reviewSchema' => 1, 'quoteId' => $quote->public_id,
            'expiresAt' => $quote->expires_at->min($pricing->expires_at)->utc()->toISOString(),
            'pricing' => app(PricingSnapshot::class)->present($pricing),
            'sellerName' => $policy['seller']['legal_name'], 'policyVersion' => $policy['version'],
            'assent' => $policy['assent'],
            'items' => array_map(fn (array $line) => ['offerRevisionId' => (string) $line['offer_revision_id'],
                'title' => $line['offer_snapshot']['product']['title'], 'licenseName' => $line['offer_snapshot']['license']['name'],
                'disclosure' => app(QuoteLicenseDisclosure::class)->fromLine($quote, $line)], $quote->snapshot['lines']),
            'testOnly' => true, 'payable' => false];

        return $data + ['reviewHash' => CanonicalJson::hash($data)];
    }
}
