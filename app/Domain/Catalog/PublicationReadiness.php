<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\Offer;
use App\Domain\Catalog\Models\OfferRevision;
use App\Domain\Catalog\Models\Track;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\VerifiedMedia;
use App\Domain\Media\RecordingAssociation;
use App\Domain\Rights\Models\LicenseVersion;
use App\Domain\Rights\VerifiedLicense;
use App\Support\CanonicalJson;
use Throwable;

class PublicationReadiness
{
    public function draftBlockers(Offer $offer): array
    {
        return $this->draftEvidenceBlockers($offer, 'non-exclusive');
    }

    /** Internal preparation only. Public publication still requires a non-exclusive license. */
    public function exclusiveDraftBlockers(Offer $offer): array
    {
        return $this->draftEvidenceBlockers($offer, 'exclusive');
    }

    private function draftEvidenceBlockers(Offer $offer, string $type): array
    {
        $blockers = [];
        $license = $offer->licenseVersion()->first();
        if (! $license || ! app(VerifiedLicense::class)->available($license)) {
            return ['An offer needs a published, effective license with intact review evidence.'];
        }
        if ($offer->price_minor <= 0 || $offer->price_minor > 2147483647 || $offer->currency !== 'USD') {
            $blockers[] = 'Launch offers require a positive USD price in integer cents.';
        }
        if ($license->template->type !== $type) {
            $blockers[] = $type === 'exclusive' ? 'Exclusive preparation requires an explicitly reviewed exclusive license.' : 'Exclusive and free commerce workflows are not enabled in this foundation.';
        }
        $track = Track::find($offer->track_id);
        $rights = $track?->rightsDeclarations()->latest('id')->first();
        if (! $rights || $rights->status !== 'verified' || ! $rights->verified_by || ! $rights->verified_at) {
            $blockers[] = 'The latest rights declaration must be verified.';
        }
        $ids = $offer->deliverable_asset_ids ?? [];
        if (! is_array($ids) || $ids === [] || count($ids) !== count(array_unique($ids)) || collect($ids)->contains(fn ($id) => ! is_int($id) || $id <= 0)) {
            return array_merge($blockers, ['Select distinct exact deliverable asset IDs.']);
        }
        $assets = MediaAsset::query()->whereIn('id', $ids)->where('track_id', $offer->track_id)->where('status', 'ready')->get();
        if ($assets->count() !== count(array_unique($ids))) {
            $blockers[] = 'Every deliverable must resolve to a verified asset revision on this track.';
        }
        if ($assets->pluck('role')->sort()->values()->all() !== collect($license->requiredAssetRoles())->sort()->values()->all()) {
            $blockers[] = 'Deliverable assets must match the licensed roles exactly, once per role.';
        }
        foreach ($license->requiredAssetRoles() as $role) {
            if (! $assets->contains('role', $role)) {
                $blockers[] = 'Missing required deliverable: '.$role.'.';
            }
        }
        if ($license->requiredAssetRoles() === []) {
            $blockers[] = 'License deliverable roles are required.';
        }
        $preview = Track::find($offer->track_id)?->assets()->where('role', 'preview_tagged')->where('status', 'ready')->latest('id')->first();
        if (! $preview || ! $this->available($preview)) {
            $blockers[] = 'A verified current preview is required before publishing an offer.';
        }
        foreach ($assets as $asset) {
            if (! $preview || ! app(RecordingAssociation::class)->matches($asset, $preview)) {
                $blockers[] = 'Deliverables must come from the same verified recording revision as the current preview.';
            }
            if (! $this->available($asset)) {
                $blockers[] = 'A deliverable revision is not available in private storage.';
            }
        }

        return array_values(array_unique($blockers));
    }

    public function offerBlockers(Offer $offer): array
    {
        $current = $offer->currentRevision()->first();
        if (! $offer->is_active || ! $current) {
            return ['An active published commercial revision is required.'];
        }

        $offer->setRelation('currentRevision', $current);

        return $this->revisionBlockers($offer, $current);
    }

    /** Validate frozen evidence against present eligibility; never read editable commercial fields. */
    public function revisionBlockers(Offer $offer, OfferRevision $revision): array
    {
        if (($revision->snapshot['schema_version'] ?? null) === 2) {
            try {
                app(ExclusiveActivationEvidence::class)->current($revision);
            } catch (\Illuminate\Database\QueryException $exception) {
                throw $exception;
            } catch (Throwable) {
                return ['An intact explicit test activation is required for this exclusive revision.'];
            }

            return $this->preparedExclusiveBlockers($offer, $revision);
        }

        return $this->revisionEvidenceBlockers($offer, $revision, 1, 'non-exclusive');
    }

    /** A valid prepared revision is still inactive and unsupported by public selection/pricing. */
    public function preparedExclusiveBlockers(Offer $offer, OfferRevision $revision): array
    {
        return array_merge($this->revisionEvidenceBlockers($offer, $revision, 2, 'exclusive'),
            app(ExclusiveOfferScope::class)->blockers($revision));
    }

    private function revisionEvidenceBlockers(Offer $offer, OfferRevision $revision, int $schema, string $type): array
    {
        try {
            $snapshot = $revision->snapshot;
            if ($revision->offer_id !== $offer->id || $revision->track_id !== $offer->track_id || $revision->canonicalization_version !== CanonicalJson::VERSION || ! hash_equals($revision->snapshot_hash, CanonicalJson::hash($snapshot)) || ($snapshot['schema_version'] ?? null) !== $schema || ($snapshot['product']['id'] ?? null) !== $offer->track_id || ($snapshot['commercial']['price_minor'] ?? null) !== $revision->price_minor || ($snapshot['commercial']['currency'] ?? null) !== $revision->currency || $revision->price_minor < 1 || $revision->price_minor > 2147483647 || $revision->currency !== 'USD' || ($snapshot['commercial']['type'] ?? null) !== $type || ($snapshot['license']['type'] ?? null) !== $type) {
                return ['The published commercial snapshot is invalid.'];
            }
            $blockers = [];
            $license = LicenseVersion::find($revision->license_version_id);
            $snapshots = app(OfferSnapshot::class);
            if (! $license || ! app(VerifiedLicense::class)->available($license) || ! hash_equals(CanonicalJson::hash($snapshot['license']), CanonicalJson::hash($snapshots->license($license)))) {
                $blockers[] = 'The published offer license or review evidence is no longer valid or effective.';
            }
            $track = Track::find($revision->track_id);
            $rights = $track?->rightsDeclarations()->latest('id')->first();
            if (! $rights || $rights->id !== $revision->rights_declaration_id || ($snapshot['rights']['id'] ?? null) !== $rights->id || $rights->status !== 'verified' || ! $rights->verified_by || ! $rights->verified_at || ! hash_equals($snapshot['rights']['identity_hash'] ?? '', $snapshots->rightsHash($rights))) {
                $blockers[] = 'The current rights declaration differs from the published offer evidence.';
            }
            $preview = $track?->assets()->where('role', 'preview_tagged')->where('status', 'ready')->latest('id')->first();
            if (! $preview || ! $this->available($preview) || ($snapshot['preview']['id'] ?? null) !== $preview->id || ($snapshot['preview']['parent_asset_id'] ?? null) !== $preview->parent_asset_id || ! hash_equals($snapshot['preview']['sha256'] ?? '', $preview->sha256)) {
                $blockers[] = 'Deliverables must come from the same verified recording revision as the current preview.';
            }
            $manifest = $snapshot['assets'] ?? [];
            $required = $snapshot['license']['required_asset_roles'] ?? [];
            if ($manifest === [] || $required === [] || collect($manifest)->pluck('role')->sort()->values()->all() !== collect($required)->sort()->values()->all() || count(array_unique(array_column($manifest, 'id'))) !== count($manifest)) {
                $blockers[] = 'The published deliverable manifest does not match the licensed roles.';
            }
            foreach ($manifest as $entry) {
                $asset = MediaAsset::find($entry['id'] ?? null);
                if (! $asset || $asset->track_id !== $revision->track_id || ! $this->available($asset) || ! $preview || ! app(RecordingAssociation::class)->matches($asset, $preview) || ! hash_equals(CanonicalJson::hash($entry), CanonicalJson::hash($snapshots->asset($asset)))) {
                    $blockers[] = 'A published deliverable revision is unavailable or differs from its frozen manifest.';
                }
            }

            return array_values(array_unique($blockers));
        } catch (Throwable) {
            return ['The published commercial evidence could not be verified.'];
        }
    }

    public function blockers(Track $track): array
    {
        $blockers = [];
        if (! $track->title || ! $track->slug || ! $track->artist || ! $track->genre || ! $track->musical_key || $track->bpm < 20 || $track->bpm > 400) {
            $blockers[] = 'Complete title, slug, artist, genre, musical key, BPM (20–400).';
        }
        $rights = $track->exists ? $track->rightsDeclarations()->latest('id')->first() : null;
        if (! $rights || $rights->status !== 'verified' || ! $rights->verified_by || ! $rights->verified_at) {
            $blockers[] = 'The latest rights declaration must be verified.';
        }
        foreach (['artwork', 'preview_tagged'] as $role) {
            $asset = $track->exists ? $track->assets()->where('role', $role)->where('status', 'ready')->latest('id')->first() : null;
            if (! $asset || ! $this->available($asset)) {
                $blockers[] = 'A verified '.$role.' asset is required.';
            }
        }
        $preview = $track->exists ? $track->assets()->where('role', 'preview_tagged')->where('status', 'ready')->latest('id')->first() : null;
        if (empty($preview?->technical_metadata['waveform']) || ($preview?->technical_metadata['duration_seconds'] ?? 0) < 1) {
            $blockers[] = 'Measured preview duration and waveform peaks are required.';
        }
        $offers = $track->exists ? $track->offers()->where('is_active', true)->get() : collect();
        $track->setRelation('offers', $offers);
        if ($offers->isEmpty()) {
            $blockers[] = 'At least one active license offer is required.';
        }
        foreach ($offers as $offer) {
            $blockers = array_merge($blockers, $this->offerBlockers($offer));
        }

        return array_values(array_unique($blockers));
    }

    private function available(MediaAsset $asset): bool
    {
        return app(VerifiedMedia::class)->available($asset);
    }
}
