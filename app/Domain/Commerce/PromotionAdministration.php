<?php

namespace App\Domain\Commerce;

use App\Domain\Commerce\Models\PromotionAvailability;
use App\Domain\Commerce\Models\PromotionAvailabilityRevision;
use App\Domain\Commerce\Models\PromotionCampaign;
use App\Domain\Commerce\Models\PromotionUse;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use App\Support\Environment\TestEnvironment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

/** Test-only authoring. Commercial terms and lifetime identities never change. */
final class PromotionAdministration
{
    public function create(array $policy, User $actor): PromotionCampaign
    {
        try {
            return DB::transaction(function () use ($policy, $actor): PromotionCampaign {
                $actor = $this->actor($actor);
                try {
                    $rules = app(PromotionPolicy::class);
                    $policy = $rules->validate($policy);
                    $configured = $rules->configuredPolicies(true);
                } catch (InvalidArgumentException) {
                    $this->invalid('The promotion or existing test-promotion configuration is invalid.');
                }
                foreach ($configured as $legacy) {
                    if ($legacy['key'] === $policy['key'] || $legacy['code'] === $policy['code']) {
                        $this->invalid('This key or code belongs to a configured promotion. Use a new key and code.');
                    }
                }
                if (PromotionCampaign::where('policy_key', $policy['key'])->orWhere('code', $policy['code'])->exists()) {
                    $this->invalid('Promotion keys and codes are retained for their lifetime. Use a new key and code.');
                }
                $at = now()->utc()->startOfSecond();
                $campaign = PromotionCampaign::create(['policy_key' => $policy['key'], 'code' => $policy['code'],
                    'snapshot' => $policy, 'snapshot_hash' => CanonicalJson::hash($policy), 'created_at' => $at]);
                PromotionAvailabilityRevision::create(['promotion_campaign_id' => $campaign->id,
                    'revision' => 1, 'enabled' => false, 'previous_enabled' => null, 'operation' => 'create',
                    'policy_hash' => $campaign->snapshot_hash, 'actor_id' => $actor->id, 'created_at' => $at]);
                PromotionAvailability::create(['promotion_campaign_id' => $campaign->id,
                    'revision' => 1, 'enabled' => false, 'updated_at' => $at]);
                AuditEvent::record('commerce.promotion.created', $campaign, [
                    'policy_hash' => $campaign->snapshot_hash, 'availability_revision' => 1, 'enabled' => false, 'test_only' => true,
                ], $actor->id);

                return $campaign;
            }, 5);
        } catch (UniqueConstraintViolationException) {
            $this->invalid('Promotion keys and codes are retained for their lifetime. Use a new key and code.');
        }
    }

    public function setAvailability(int $campaignId, int $expectedRevision, bool $enabled, User $actor): PromotionAvailability
    {
        return DB::transaction(function () use ($campaignId, $expectedRevision, $enabled, $actor): PromotionAvailability {
            // Share the existing usage mutex; never take quote/catalog/usage locks from this command.
            $campaign = PromotionCampaign::query()->lockForUpdate()->findOrFail($campaignId);
            $actor = $this->actor($actor);
            $policy = $this->policy($campaign);
            $availability = $this->availability($campaign, true);
            if ($availability === null) {
                $this->invalid('Configured legacy campaigns are read-only. Create a promotion with a new key and code.');
            }
            if ($expectedRevision !== $availability->revision || $expectedRevision >= 2147483646) {
                $this->invalid('Promotion availability changed. Refresh before enabling or disabling it.');
            }
            if ($enabled === $availability->enabled) {
                $this->invalid('This promotion already has the requested availability.');
            }
            $at = now()->utc()->startOfSecond();
            if ($at->lessThan($availability->updated_at)) {
                $this->invalid('The promotion clock precedes its retained availability history.');
            }
            if ($enabled && app(PricingPolicy::class)->timestamp($policy['effective_until'])->lessThanOrEqualTo($at)) {
                $this->invalid('An expired promotion cannot be enabled. Create a new promotion with a new key and code.');
            }
            $revision = $availability->revision + 1;
            PromotionAvailabilityRevision::create(['promotion_campaign_id' => $campaign->id,
                'revision' => $revision, 'enabled' => $enabled, 'previous_enabled' => $availability->enabled,
                'operation' => $enabled ? 'enable' : 'disable', 'policy_hash' => $campaign->snapshot_hash,
                'actor_id' => $actor->id, 'created_at' => $at]);
            DB::table('promotion_availabilities')->where('id', $availability->id)->update([
                'revision' => $revision, 'enabled' => $enabled, 'updated_at' => $at,
            ]);
            AuditEvent::record('commerce.promotion.'.($enabled ? 'enabled' : 'disabled'), $campaign, [
                'policy_hash' => $campaign->snapshot_hash, 'availability_revision' => $revision,
                'previous_enabled' => $availability->enabled, 'enabled' => $enabled, 'test_only' => true,
            ], $actor->id);

            return $availability->refresh();
        }, 5);
    }

    public function campaigns(User $actor): Builder
    {
        $this->actor($actor);

        return PromotionCampaign::query()->with('availability');
    }

    public function detail(int $campaignId, User $actor): array
    {
        return DB::transaction(function () use ($campaignId, $actor): array {
            $this->actor($actor);
            $campaign = PromotionCampaign::findOrFail($campaignId);
            $policy = $this->policy($campaign);
            $availability = $this->availability($campaign);
            $at = now()->toImmutable();
            $counts = PromotionUse::where('promotion_campaign_id', $campaignId)
                ->selectRaw("SUM(CASE WHEN state = 'held' AND expires_at > ? THEN 1 ELSE 0 END) AS held", [$at])
                ->selectRaw("SUM(CASE WHEN state = 'held' AND expires_at <= ? THEN 1 ELSE 0 END) AS expired", [$at])
                ->selectRaw("SUM(CASE WHEN state = 'pending' THEN 1 ELSE 0 END) AS pending")
                ->selectRaw("SUM(CASE WHEN state = 'consumed' THEN 1 ELSE 0 END) AS consumed")->first();
            $usage = array_map(fn ($field) => (int) ($counts->{$field} ?? 0), ['held', 'pending', 'consumed', 'expired']);
            $usage = array_combine(['held', 'pending', 'consumed', 'expired'], $usage);
            $usage['remaining'] = max(0, $policy['max_uses'] - $usage['held'] - $usage['pending'] - $usage['consumed']);
            $dates = app(PricingPolicy::class);
            $status = $dates->timestamp($policy['effective_until'])->lessThanOrEqualTo($at) ? 'expired'
                : ($availability === null ? 'legacy' : (! $availability->enabled ? 'disabled'
                    : ($dates->timestamp($policy['effective_from'])->greaterThan($at) ? 'scheduled' : 'active')));

            return ['id' => $campaign->id, 'key' => $policy['key'], 'code' => $policy['code'],
                'version' => $policy['version'], 'currency' => $policy['currency'], 'policy' => $policy,
                'status' => $status, 'managed' => $availability !== null, 'enabled' => $availability?->enabled,
                'revision' => $availability?->revision, 'usage' => $usage];
        });
    }

    /** Lookup precedes the unchanged configuration fallback. Disabled managed codes cannot fall through. */
    public function currentPolicy(string $code): ?array
    {
        $campaign = PromotionCampaign::where('code', $code)->first();
        if ($campaign === null) { return null; }
        try {
            $availability = $this->availability($campaign);
            if ($availability === null) { return null; }
            $policy = $this->policy($campaign);
        } catch (ValidationException|InvalidArgumentException) {
            throw new QuoteException('PROMOTION_UNAVAILABLE', 503);
        }
        if (! $availability->enabled) { throw new QuoteException('PROMOTION_UNAVAILABLE', 409); }

        return $policy;
    }

    /** Caller holds this campaign's FOR UPDATE lock through its new use/attempt transaction. */
    public function requireAvailable(PromotionCampaign $campaign): void
    {
        if (DB::transactionLevel() < 1) { throw new LogicException('Promotion availability requires the campaign transaction.'); }
        try {
            $availability = $this->availability($campaign, true);
            if ($availability !== null) { $this->policy($campaign); }
        } catch (ValidationException|InvalidArgumentException) {
            throw new QuoteException('PROMOTION_UNAVAILABLE', 503);
        }
        if ($availability !== null && ! $availability->enabled) {
            throw new QuoteException('PROMOTION_UNAVAILABLE', 409);
        }
    }

    private function actor(User $actor): User
    {
        if (! TestEnvironment::admitsTestCommerce()) { throw new AuthorizationException; }
        // A current locking read defeats a previously established MySQL consistent-read snapshot.
        $query = User::query();
        if (DB::transactionLevel() > 0) { $query->sharedLock(); }
        $current = $actor->exists ? $query->find($actor->getKey()) : null;
        if ($current === null) { throw new AuthorizationException; }
        Gate::forUser($current)->authorize('administer-catalog');

        return $current;
    }

    private function policy(PromotionCampaign $campaign): array
    {
        try {
            $policy = app(PromotionPolicy::class)->validate($campaign->snapshot);
            if ($campaign->policy_key !== $policy['key'] || $campaign->code !== $policy['code']
                || ! hash_equals($campaign->snapshot_hash, CanonicalJson::hash($policy))) {
                throw new InvalidArgumentException;
            }
        } catch (InvalidArgumentException) {
            $this->invalid('The retained promotion failed its integrity check.');
        }

        return $policy;
    }

    private function availability(PromotionCampaign $campaign, bool $lock = false): ?PromotionAvailability
    {
        $query = PromotionAvailability::where('promotion_campaign_id', $campaign->id);
        $historyQuery = PromotionAvailabilityRevision::where('promotion_campaign_id', $campaign->id)->orderByDesc('revision');
        if ($lock) { $query->lockForUpdate(); $historyQuery->lockForUpdate(); }
        $availability = $query->first(); $history = $historyQuery->first();
        if ($availability === null && $history === null) { return null; }
        if ($availability === null || $history === null || $availability->revision < 1 || $availability->revision > 2147483646
            || $history->revision !== $availability->revision || $history->enabled !== $availability->enabled
            || ! $history->created_at->equalTo($availability->updated_at)
            || ! hash_equals($campaign->snapshot_hash, $history->policy_hash)
            || ($history->revision === 1 && ($history->enabled || $history->previous_enabled !== null || $history->operation !== 'create'))
            || ($history->revision > 1 && ($history->previous_enabled !== ! $history->enabled || $history->operation !== ($history->enabled ? 'enable' : 'disable')))) {
            $this->invalid('The retained promotion availability failed its integrity check.');
        }

        return $availability;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['promotion' => $message]);
    }
}
