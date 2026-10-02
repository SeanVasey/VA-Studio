<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\Offer;
use App\Domain\Catalog\Models\OfferRevision;
use App\Domain\Catalog\Models\Track;
use App\Domain\Commerce\Models\ExclusiveSale;
use App\Domain\Commerce\Models\RightsScope;
use App\Domain\Commerce\Models\RightsScopeOffer;
use App\Domain\Commerce\QuoteException;
use App\Domain\Delivery\MediaEvidenceValues;
use App\Domain\Media\VerifiedMedia;
use App\Domain\Rights\Models\LicenseVersion;
use App\Domain\Rights\VerifiedLicense;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Read-only current evidence. File availability retains MediaIntegrity's bounded 60-second byte cache. */
final class ReadTrackPublicationManifest
{
    public function handle(int $trackId, User $actor): TrackPublicationManifest
    {
        // Readiness uses ordinary reads. Never inherit a caller's Repeatable Read snapshot.
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Track publication manifests require a standalone transaction.');
        }

        return DB::transaction(function () use ($trackId, $actor): TrackPublicationManifest {
            $current = $actor->exists ? User::query()->lockForUpdate()->find($actor->getKey()) : null;
            if ($current === null) {
                throw new AuthorizationException;
            }
            Gate::forUser($current)->authorize('administer-catalog', [true]);
            if (! AdminMultiFactor::satisfiedBy($current, lockForUpdate: true)) {
                throw new AuthorizationException('Admin multi-factor authentication is required.');
            }
            if ($trackId < 1) {
                throw (new ModelNotFoundException)->setModel(Track::class, [$trackId]);
            }
            $track = Track::query()->lockForUpdate()->findOrFail($trackId);

            return $this->captureLocked($track, $current, CarbonImmutable::instance(now())->utc());
        });
    }

    /** Internal projection: caller has authorized and locked actor/track, and owns the child-read view. */
    public function captureLocked(Track $track, User $actor, CarbonImmutable $capturedAt): TrackPublicationManifest
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A locked publication capture requires its caller transaction.');
        }
        $blockers = app(PublicationReadiness::class)->blockers($track);
        if ($blockers !== []) {
            throw ValidationException::withMessages(['publication' => implode(' ', $blockers)]);
        }

        // No source/run locks follow the track lock; verified revisions are immutable.
        $rights = $track->rightsDeclarations()->latest('id')->first();
        if ($rights === null || $rights->status !== 'verified' || ! $rights->verified_by || ! $rights->verified_at) {
            $this->invalid();
        }
        $offers = $track->getRelation('offers')->sortBy('id')->values()->map(fn (Offer $offer) => $this->offer($offer, $capturedAt))->all();

        return new TrackPublicationManifest([
            'schema_version' => TrackPublicationManifest::SCHEMA_VERSION,
            'canonicalization_version' => CanonicalJson::VERSION,
            'track' => $this->track($track),
            'rights' => ['id' => $this->integer($rights->id), 'identity_hash' => app(OfferSnapshot::class)->rightsHash($rights)],
            'artwork' => $this->derivative($track, 'artwork'),
            'preview_tagged' => $this->derivative($track, 'preview_tagged'),
            'offers' => $offers,
        ], $this->integer($actor->id), $capturedAt);
    }

    private function track(Track $track): array
    {
        $tags = $track->tags;
        if ($tags !== null && (! is_array($tags) || ! array_is_list($tags) || array_filter($tags, fn ($tag) => ! is_string($tag)))) {
            $this->invalid();
        }
        if (! in_array($track->status, ['draft', 'published'], true)) {
            $this->invalid();
        }

        return [
            'id' => $this->integer($track->id), 'status' => $track->status,
            'metadata_version' => $this->integer($track->metadata_version, 0, 2147483647),
            'publication_version' => $this->integer($track->publication_version, 0, 2147483647),
            'title' => $this->string($track->title), 'slug' => $this->string($track->slug),
            'published_slug' => $this->nullableString($track->published_slug), 'artist' => $this->string($track->artist),
            'bpm' => $this->integer($track->bpm, 20, 400), 'musical_key' => $this->string($track->musical_key),
            'genre' => $this->string($track->genre), 'mood' => $this->nullableString($track->mood),
            'tags' => $tags, 'description' => $this->nullableString($track->description),
        ];
    }

    private function derivative(Track $track, string $role): array
    {
        // Match readiness exactly; an unavailable newest ready revision never falls back.
        $asset = $track->assets()->where('role', $role)->where('status', 'ready')->latest('id')->first();
        $verified = app(VerifiedMedia::class);
        if ($asset === null || ! $verified->available($asset) || ($evidence = $verified->evidence($asset)) === null) {
            $this->invalid();
        }
        $identity = [
            'asset_id' => $this->integer($asset->id), 'role' => $role, 'sha256' => $this->digest($asset->sha256),
            'size_bytes' => $this->integer($asset->size_bytes), 'parent_asset_id' => $this->integer($asset->parent_asset_id),
            'processing_run_id' => $this->integer($asset->processing_run_id),
            'profile_fingerprint' => $this->digest($evidence['run']['profile_fingerprint'] ?? null),
        ];
        if ($role === 'preview_tagged') {
            $metadata = $asset->technical_metadata;
            $duration = $metadata['duration_seconds'] ?? null;
            if ((! is_int($duration) && ! is_float($duration)) || ! is_finite((float) $duration) || $duration < 1) {
                $this->invalid();
            }
            // Retain exact measured numeric identity independently of ambient JSON float formatting.
            $identity += ['waveform_sha256' => $this->digest($metadata['waveform_sha256'] ?? null),
                'tag_sha256' => $this->digest($metadata['tag_sha256'] ?? null),
                'duration_seconds_reference' => MediaEvidenceValues::reference($duration)];
        }

        return $identity;
    }

    private function offer(Offer $offer, CarbonImmutable $capturedAt): array
    {
        $revision = $offer->getRelation('currentRevision');
        if (! $revision instanceof OfferRevision) {
            $this->invalid();
        }
        // Ordinary readiness checks each license using its own clock read. All captured offers must
        // additionally be effective at this one recorded instant, including both interval boundaries.
        $currentLicense = LicenseVersion::find($revision->license_version_id);
        if ($currentLicense === null || ! app(VerifiedLicense::class)->available($currentLicense, $capturedAt)) {
            $this->invalid();
        }
        $snapshot = $revision->snapshot;
        $schema = $snapshot['schema_version'] ?? null;
        if (! in_array($schema, [1, 2], true)) {
            $this->invalid();
        }
        $license = $snapshot['license'];
        $identity = [
            'id' => $this->integer($license['id'] ?? null), 'template_id' => $this->integer($license['template_id'] ?? null),
            'version' => $this->integer($license['version'] ?? null), 'type' => $this->string($license['type'] ?? null),
            'source_hash' => $this->digest($license['source_hash'] ?? null), 'model_hash' => $this->digest($license['model_hash'] ?? null),
            'renderer_version' => $this->string($license['renderer_version'] ?? null),
            'render_fixture_hash' => $this->digest($license['render_fixture_hash'] ?? null),
            'submission_hash' => $this->digest($license['submission_hash'] ?? null),
            'review_evidence_id' => $this->integer($license['review_evidence_id'] ?? null),
            'review_evidence_hash' => $this->digest($license['review_evidence_hash'] ?? null),
            'effective_from' => $this->nullableString($license['effective_from'] ?? null),
            'effective_until' => $this->nullableString($license['effective_until'] ?? null),
            'identity_hash' => CanonicalJson::hash($license),
        ];
        $deliverables = array_map(fn (array $entry) => $this->deliverable($entry), $snapshot['assets']);
        usort($deliverables, fn (array $left, array $right) => strcmp($left['role'], $right['role']) ?: $left['asset_id'] <=> $right['asset_id']);

        return [
            'offer_id' => $this->integer($offer->id), 'revision_id' => $this->integer($revision->id),
            'revision' => $this->integer($revision->revision), 'schema_version' => $schema,
            'snapshot_hash' => $this->digest($revision->snapshot_hash), 'canonicalization_version' => $revision->canonicalization_version,
            'commercial' => ['price_minor' => $this->integer($snapshot['commercial']['price_minor'] ?? null, 1, 2147483647),
                'currency' => $this->string($snapshot['commercial']['currency'] ?? null), 'type' => $this->string($snapshot['commercial']['type'] ?? null)],
            'license' => $identity, 'deliverables' => $deliverables,
            'exclusive' => $schema === 2 ? $this->exclusive($revision) : null,
        ];
    }

    private function deliverable(array $entry): array
    {
        $binding = null;
        if (($entry['role'] ?? null) === 'stems_zip') {
            $record = $entry['recording_binding'] ?? [];
            foreach (['id', 'stems_asset_id', 'master_asset_id', 'preview_asset_id', 'recording_source_id'] as $field) {
                $binding[$field] = $this->integer($record[$field] ?? null);
            }
            $binding['evidence_hash'] = $this->digest($record['evidence_hash'] ?? null);
        }

        return ['asset_id' => $this->integer($entry['id'] ?? null), 'role' => $this->string($entry['role'] ?? null),
            'sha256' => $this->digest($entry['sha256'] ?? null), 'mime_type' => $this->string($entry['mime_type'] ?? null),
            'size_bytes' => $this->integer($entry['size_bytes'] ?? null),
            'parent_asset_id' => $this->integer($entry['parent_asset_id'] ?? null),
            'processing_run_id' => $this->integer($entry['processing_run_id'] ?? null), 'recording_binding' => $binding];
    }

    private function exclusive(OfferRevision $revision): array
    {
        try {
            $activation = app(ExclusiveActivationEvidence::class)->current($revision);
        } catch (QuoteException) {
            $this->invalid();
        }
        $link = RightsScopeOffer::where('offer_revision_id', $revision->id)->first();
        $scope = $link ? RightsScope::find($link->rights_scope_id) : null;
        if ($scope === null || $scope->blocked || ExclusiveSale::where('rights_scope_id', $scope->id)->exists()) {
            $this->invalid();
        }
        $inventory = app(ExclusiveOfferScope::class)->capture($scope, $link->evidence_reference);
        $policy = $activation->snapshot['policy'];

        // Scope controls have independent writers. These fields describe this consistent read only.
        return ['eligible' => true,
            'activation' => ['id' => $this->integer($activation->id), 'snapshot_hash' => $this->digest($activation->snapshot_hash),
                'canonicalization_version' => $activation->canonicalization_version],
            'policy' => $policy, 'policy_hash' => CanonicalJson::hash($policy),
            'scope' => ['scope_id' => $this->integer($scope->id), 'scope_public_id' => $this->string($inventory['scope_public_id']),
                'scope_identity_hash' => $this->digest($inventory['scope_identity_hash']), 'link_reference_hash' => $this->digest($inventory['link_reference_hash']),
                'link_id' => $this->integer($link->id), 'control_version' => $this->integer($scope->control_version, 0),
                'blocked' => $scope->blocked, 'exclusive_sale_id' => null]];
    }

    private function integer(mixed $value, int $minimum = 1, int $maximum = PHP_INT_MAX): int
    {
        // PDO may return integral columns as decimal strings; JSON evidence remains canonicalized separately.
        if (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) &&
            (strlen($value) < strlen((string) $maximum) || (strlen($value) === strlen((string) $maximum) && strcmp($value, (string) $maximum) <= 0))) {
            $value = (int) $value;
        }
        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            $this->invalid();
        }

        return $value;
    }

    private function string(mixed $value): string
    {
        if (! is_string($value)) {
            $this->invalid();
        }

        return $value;
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : $this->string($value);
    }

    private function digest(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/\A[a-f0-9]{64}\z/D', $value)) {
            $this->invalid();
        }

        return $value;
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['publication' => 'Current publication evidence could not be captured.']);
    }
}
