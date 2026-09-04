<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\Offer;
use App\Domain\Catalog\Models\Track;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\VerifiedMedia;

class PublicationReadiness
{
    public function offerBlockers(Offer $offer): array
    {
        $blockers = [];
        $license = $offer->licenseVersion;
        if (! $license || $license->status !== 'published' || ! $license->published_at) {
            return ['An active offer needs a published license version.'];
        }
        if ($offer->price_minor <= 0 || $offer->currency !== 'USD') {
            $blockers[] = 'Launch offers require a positive USD price in integer cents.';
        }
        if ($license->template->type !== 'non-exclusive') {
            $blockers[] = 'Exclusive and free commerce workflows are not enabled in this foundation.';
        }
        $ids = $offer->deliverable_asset_ids ?? [];
        $assets = MediaAsset::query()->whereIn('id', $ids)->where('track_id', $offer->track_id)->where('status', 'ready')->get();
        if ($assets->count() !== count(array_unique($ids))) {
            $blockers[] = 'Every deliverable must resolve to a verified asset revision on this track.';
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
        foreach ($assets as $asset) {
            if (! $preview || ! $preview->parent_asset_id || $asset->parent_asset_id !== $preview->parent_asset_id) {
                $blockers[] = 'Deliverables must come from the same verified recording revision as the current preview.';
            }
            if (! $this->available($asset)) {
                $blockers[] = 'A deliverable revision is not available in private storage.';
            }
        }

        return array_values(array_unique($blockers));
    }

    public function blockers(Track $track): array
    {
        $blockers = [];
        if (! $track->title || ! $track->slug || ! $track->artist || ! $track->genre || ! $track->musical_key || $track->bpm < 20 || $track->bpm > 400) {
            $blockers[] = 'Complete title, slug, artist, genre, musical key, BPM (20–400), and BPM.';
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
