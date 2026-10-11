<?php

namespace App\Domain\Commerce\Inventory;

use App\Domain\Catalog\Models\Offer;
use App\Domain\Catalog\Models\OfferRevision;
use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublicationReadiness;
use App\Domain\Commerce\Models\RightsScope;
use App\Domain\Commerce\Models\RightsScopeOffer;
use App\Domain\Commerce\QuoteException;
use App\Domain\Staff\StaffWriteAuthority;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Internal test foundation. An explicit link does not establish legal ownership or publish an exclusive.
 * Every write applies the admin panel's staff multi-factor rule itself; a console caller may replace it only
 * with a recorded `StaffWriteAuthority` attestation bound to that exact write and spent by it (#68 review
 * condition C2).
 */
final class ManageRightsScope
{
    /**
     * Registers a scope key once; a repeat with the same reference is unchanged, another reference conflicts.
     *
     * @param  StaffWriteAuthority|null  $attestation  A console operator's recorded, unused attestation for this key
     *                                                 and reference; null applies the panel's staff multi-factor
     *                                                 rule to `$actor` instead.
     */
    public function register(string $key, string $reference, User $actor, ?StaffWriteAuthority $attestation = null): RightsScope
    {
        return DB::transaction(function () use ($key, $reference, $actor, $attestation) {
            $actor = $this->authorize($actor, $reference, $attestation, 'register', $key);
            if (! preg_match('/\A[a-z0-9][a-z0-9._:-]{0,95}\z/D', $key)) {
                throw new QuoteException('INVALID_QUOTE_REQUEST', 422);
            }
            $id = (string) Str::uuid();
            DB::table('rights_scopes')->insertOrIgnore(['public_id' => $id, 'scope_key' => $key,
                'evidence_reference' => $reference, 'created_by' => $actor->id, 'created_at' => now()->utc()->startOfSecond(),
                'blocked' => false, 'control_version' => 0]);
            $scope = RightsScope::where('scope_key', $key)->lockForUpdate()->first();
            if (! $scope || $scope->evidence_reference !== $reference) {
                throw new QuoteException('INVENTORY_SCOPE_CONFLICT', 409);
            }
            if ($scope->public_id === $id) {
                AuditEvent::record('commerce.inventory.scope_registered', $scope,
                    ['public_id' => $id] + ($attestation?->auditContext() ?? []), $actor->id);
            }

            return $scope;
        }, 5);
    }

    /**
     * Links a current, publishable offer revision to a scope; the link is immutable once made.
     *
     * @param  StaffWriteAuthority|null  $attestation  A console operator's recorded, unused attestation for this scope
     *                                                 key, revision and reference; null applies the panel's staff
     *                                                 multi-factor rule.
     */
    public function link(int $scopeId, int $revisionId, string $reference, User $actor, ?StaffWriteAuthority $attestation = null): RightsScopeOffer
    {
        return DB::transaction(function () use ($scopeId, $revisionId, $reference, $actor, $attestation) {
            $actor = $this->authorize($actor, $reference, $attestation, 'link', $scopeId, $revisionId);
            $revision = OfferRevision::findOrFail($revisionId);
            $track = Track::whereKey($revision->track_id)->lockForUpdate()->firstOrFail();
            $offer = Offer::whereKey($revision->offer_id)->lockForUpdate()->firstOrFail();
            if ($track->status !== 'published' || ! $offer->is_active || $offer->current_revision_id !== $revision->id ||
                app(PublicationReadiness::class)->blockers($track) !== [] ||
                app(PublicationReadiness::class)->revisionBlockers($offer, $revision) !== []) {
                throw new QuoteException('SELECTION_CHANGED', 409);
            }
            RightsScope::whereKey($scopeId)->lockForUpdate()->firstOrFail();
            $link = RightsScopeOffer::where('offer_revision_id', $revisionId)->lockForUpdate()->first();
            if ($link) {
                if ($link->rights_scope_id !== $scopeId || $link->evidence_reference !== $reference) {
                    throw new QuoteException('INVENTORY_SCOPE_CONFLICT', 409);
                }

                return $link;
            }
            $link = RightsScopeOffer::create(['rights_scope_id' => $scopeId, 'offer_revision_id' => $revisionId,
                'evidence_reference' => $reference, 'linked_by' => $actor->id, 'created_at' => now()->utc()->startOfSecond()]);
            AuditEvent::record('commerce.inventory.offer_linked', $link,
                ['scope_id' => $scopeId, 'offer_revision_id' => $revisionId] + ($attestation?->auditContext() ?? []), $actor->id);

            return $link;
        }, 5);
    }

    /**
     * Blocks or unblocks a scope under optimistic control versioning.
     *
     * @param  StaffWriteAuthority|null  $attestation  A console operator's recorded, unused attestation for this scope
     *                                                 key, requested state, expected version and reference; null
     *                                                 applies the panel's staff multi-factor rule to `$actor`.
     */
    public function block(int $scopeId, bool $blocked, int $expectedVersion, string $reference, User $actor, ?StaffWriteAuthority $attestation = null): RightsScope
    {
        return DB::transaction(function () use ($scopeId, $blocked, $expectedVersion, $reference, $actor, $attestation) {
            $actor = $this->authorize($actor, $reference, $attestation, 'block', $scopeId, null, $blocked, $expectedVersion);
            $scope = RightsScope::whereKey($scopeId)->lockForUpdate()->firstOrFail();
            if ($scope->control_version !== $expectedVersion) {
                throw new QuoteException('INVENTORY_CONTROL_CONFLICT', 409);
            }
            if ($scope->blocked === $blocked) {
                return $scope;
            }
            DB::table('rights_scopes')->where('id', $scopeId)->update([
                'blocked' => $blocked, 'control_version' => $expectedVersion + 1,
            ]);
            AuditEvent::record('commerce.inventory.control_changed', $scope, ['blocked' => $blocked,
                'control_version' => $expectedVersion + 1, 'reference_hash' => hash('sha256', $reference)]
                + ($attestation?->auditContext() ?? []), $actor->id);

            return $scope->refresh();
        }, 5);
    }

    /**
     * `$scope` is the key being registered or the id of an existing scope; an attestation binds to the key, the
     * reference and, for block, the requested state and expected version, and is spent inside this transaction.
     */
    private function authorize(User $actor, string $reference, ?StaffWriteAuthority $attestation, string $action, int|string $scope,
        ?int $revisionId = null, ?bool $blocked = null, ?int $expectedVersion = null): User
    {
        $actor = $actor->exists ? User::query()->lockForUpdate()->find($actor->getKey()) : null;
        if (! $actor) {
            throw new AuthorizationException;
        }
        // The caller may retain an older REPEATABLE READ snapshot; keep policy current too.
        Gate::forUser($actor)->authorize('administer-catalog', [true]);
        InventoryPolicy::requireTestEnvironment();
        // Scope keys are immutable identities, so a plain read after the actor lock binds an attestation by id
        // without touching the ordered catalog locks taken later. Without an attestation nothing extra is read:
        // the panel rule is evaluated on the actor row locked above, keeping the writer's lock sequence unchanged.
        $scopeKey = is_string($scope) ? $scope : ($attestation === null ? null : RightsScope::whereKey($scope)->value('scope_key'));
        StaffWriteAuthority::requireFor($actor, $attestation, $action, $scopeKey, $revisionId, $reference, $blocked, $expectedVersion);
        if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:\/-]{0,191}\z/D', $reference)) {
            throw new QuoteException('INVALID_QUOTE_REQUEST', 422);
        }

        return $actor;
    }
}
