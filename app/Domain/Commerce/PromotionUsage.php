<?php

namespace App\Domain\Commerce;

use App\Domain\Commerce\Models\PromotionCampaign;
use App\Domain\Commerce\Models\PromotionUse;
use App\Domain\Commerce\Models\QuotePricing;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use App\Support\Environment\TestEnvironment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/** Quote holds consume capacity. Pending attempts retain it until future WP-07
 * verified settlement/reconciliation; this class never calls a payment provider.
 */
final class PromotionUsage
{
    public function hold(QuotePricing $pricing, ?User $actor = null): PromotionUse
    {
        $this->requireTransaction();
        $actorId = app(CommerceAuditActor::class)->lock($actor);
        $policy = $pricing->snapshot['promotion'];
        $campaign = $this->campaign($policy, true);
        app(PromotionAdministration::class)->requireAvailable($campaign);
        $at = now()->toImmutable(); // Sample only after the shared campaign lock.
        if ($pricing->expires_at->lessThanOrEqualTo($at)) {
            throw new QuoteException('PRICING_EXPIRED', 410);
        }
        try {
            app(PricingPolicy::class)->activeAt($policy, $at);
        } catch (InvalidArgumentException) {
            throw new QuoteException('PROMOTION_UNAVAILABLE', 409);
        }
        // A locking read sees the latest committed uses under MySQL REPEATABLE READ,
        // even when quote validation previously established a consistent-read snapshot.
        $used = PromotionUse::query()->where('promotion_campaign_id', $campaign->id)
            ->where(fn ($query) => $query->whereIn('state', ['pending', 'consumed'])
                ->orWhere(fn ($held) => $held->where('state', 'held')->where('expires_at', '>', $at)))
            ->limit($policy['max_uses'])->lockForUpdate()->pluck('id')->count();
        if ($used >= $policy['max_uses']) {
            throw new QuoteException('PROMOTION_LIMIT_REACHED', 409);
        }
        $use = PromotionUse::create(['promotion_campaign_id' => $campaign->id, 'quote_pricing_id' => $pricing->id,
            'state' => 'held', 'created_at' => $pricing->created_at, 'expires_at' => $pricing->expires_at]);
        AuditEvent::recordAttributed('commerce.promotion.held', $use, [
            'pricing_public_id' => $pricing->public_id, 'policy_hash' => $campaign->snapshot_hash,
            'expires_at' => $use->expires_at->toIso8601ZuluString(),
        ], $actorId);

        return $use;
    }

    public function currentUse(QuotePricing $pricing): PromotionUse
    {
        $this->requireTransaction();
        $campaign = $this->campaign($pricing->snapshot['promotion'], false);
        $use = PromotionUse::query()->where('quote_pricing_id', $pricing->id)->lockForUpdate()->first();
        if (! $use || $use->promotion_campaign_id !== $campaign->id || ! $use->expires_at->equalTo($pricing->expires_at) ||
            ! $use->created_at->equalTo($pricing->created_at) || ! in_array($use->state, ['held', 'pending'], true) ||
            ($use->state === 'held' && ($use->attempt_id !== null || $use->pending_at !== null)) ||
            ($use->state === 'pending' && (! $this->validAttempt($use->attempt_id) || $use->pending_at === null ||
                $use->pending_at->lessThan($use->created_at) || $use->pending_at->greaterThanOrEqualTo($use->expires_at)))) {
            throw new QuoteException('PRICING_CHANGED', 409);
        }
        if ($use->state === 'held' && $use->expires_at->lessThanOrEqualTo(now())) {
            throw new QuoteException('PRICING_EXPIRED', 410);
        }

        return $use;
    }

    /** Internal test-mode handoff. WP-07 must commit this binding in its order/intent
     * transaction BEFORE making any provider request. There is no customer route.
     */
    public function beginAttempt(string $quoteId, string $ownerKey, string $attemptId, ?User $actor = null): PromotionUse
    {
        return DB::transaction(function () use ($quoteId, $ownerKey, $attemptId, $actor) {
            // Explicit customer identity precedes resource locks; null remains anonymous/system.
            $actorId = app(CommerceAuditActor::class)->lock($actor);
            $pricing = app(PriceQuote::class)->read($quoteId, $ownerKey, $actor);
            if (! $this->validAttempt($attemptId)) {
                throw new QuoteException('INVALID_QUOTE_REQUEST', 422);
            }
            if (! in_array($pricing->snapshot['schema_version'] ?? null, [2, 3], true) || ! isset($pricing->snapshot['promotion'])) {
                throw new QuoteException('PROMOTION_NOT_ELIGIBLE', 409);
            }
            $use = $this->currentUse($pricing);
            if ($use->state === 'pending') {
                if ($use->attempt_id !== $attemptId) {
                    throw new QuoteException('PROMOTION_ATTEMPT_CONFLICT', 409);
                }

                return $use;
            }
            // currentUse retained the campaign lock. Re-read availability as current
            // evidence after that lock, not the earlier pricing transaction's snapshot.
            app(PromotionAdministration::class)->requireAvailable(PromotionCampaign::findOrFail($use->promotion_campaign_id));
            $at = now()->toImmutable()->utc()->startOfSecond();
            if ($use->expires_at->lessThanOrEqualTo($at)) {
                throw new QuoteException('PRICING_EXPIRED', 410);
            }
            // Another worker may already have reused this hold's expired capacity.
            // Recheck under the retained campaign lock so clock skew/rollback cannot
            // revive an old hold into an extra pending attempt. Pending and consumed uses always count.
            $otherUses = PromotionUse::query()->where('promotion_campaign_id', $use->promotion_campaign_id)
                ->where('id', '<>', $use->id)
                ->where(fn ($query) => $query->whereIn('state', ['pending', 'consumed'])
                    ->orWhere(fn ($held) => $held->where('state', 'held')->where('expires_at', '>', $at)))
                ->limit($pricing->snapshot['promotion']['max_uses'])->lockForUpdate()->pluck('id')->count();
            if ($otherUses >= $pricing->snapshot['promotion']['max_uses']) {
                throw new QuoteException('PROMOTION_LIMIT_REACHED', 409);
            }
            try {
                $changed = DB::table('promotion_uses')->where('id', $use->id)->where('state', 'held')->update([
                    'state' => 'pending', 'attempt_id' => $attemptId, 'pending_at' => $at,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new QuoteException('PROMOTION_ATTEMPT_CONFLICT', 409);
            }
            if ($changed !== 1) {
                throw new LogicException('Promotion attempt transition lost its lock.');
            }
            AuditEvent::recordAttributed('commerce.promotion.pending', $use, [
                'pricing_public_id' => $pricing->public_id, 'attempt_id' => $attemptId,
            ], $actorId);
            if ($use->expires_at->lessThanOrEqualTo(now())) {
                throw new QuoteException('PRICING_EXPIRED', 410);
            }

            return $use->refresh();
        }, 5);
    }

    private function campaign(array $policy, bool $create): PromotionCampaign
    {
        $policy = app(PromotionPolicy::class)->validate($policy);
        $hash = CanonicalJson::hash($policy);
        $campaign = PromotionCampaign::query()->where('policy_key', $policy['key'])->lockForUpdate()->first();
        if (! $campaign && $create) {
            // Insert-only registration handles concurrent first use without updating
            // immutable records. Every ignored insert must pass the identity readback.
            DB::table('promotion_campaigns')->insertOrIgnore([
                'policy_key' => $policy['key'], 'code' => $policy['code'],
                'snapshot' => CanonicalJson::encode($policy), 'snapshot_hash' => $hash,
                'created_at' => now()->utc()->startOfSecond(),
            ]);
            $campaign = PromotionCampaign::query()->where('policy_key', $policy['key'])->lockForUpdate()->first();
        }
        if (! $campaign || $campaign->code !== $policy['code'] || ! hash_equals($campaign->snapshot_hash, $hash) ||
            ! hash_equals($hash, CanonicalJson::hash($campaign->snapshot))) {
            throw new QuoteException('PROMOTION_CHANGED', 409);
        }

        return $campaign;
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Promotion usage requires the quote transaction.');
        }
        if (! TestEnvironment::admitsTestCommerce()) {
            throw new QuoteException('PROMOTION_UNAVAILABLE', 503);
        }
    }

    private function validAttempt(mixed $id): bool
    {
        return is_string($id) && (bool) preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $id);
    }
}
