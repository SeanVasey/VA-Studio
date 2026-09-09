<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\Offer;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\RecordingAssociation;
use App\Domain\Rights\Models\LicenseVersion;
use App\Domain\Rights\Models\RightsDeclaration;
use App\Support\CanonicalJson;

/** Server-authored commercial evidence. Private object paths never enter this record. */
class OfferSnapshot
{
    public function capture(Offer $offer): array
    {
        $track = $offer->track()->firstOrFail();
        $rights = $track->rightsDeclarations()->latest('id')->firstOrFail();
        $preview = $track->assets()->where('role', 'preview_tagged')->where('status', 'ready')->latest('id')->firstOrFail();

        return [
            'schema_version' => 1,
            'product' => $track->only(['id', 'slug', 'title', 'artist', 'bpm', 'musical_key', 'genre', 'mood', 'tags']),
            'commercial' => ['price_minor' => $offer->price_minor, 'currency' => $offer->currency, 'type' => 'non-exclusive'],
            'license' => $this->license($offer->licenseVersion()->firstOrFail()),
            'rights' => ['id' => $rights->id, 'identity_hash' => $this->rightsHash($rights)],
            'preview' => $preview->only(['id', 'parent_asset_id', 'sha256']),
            'assets' => MediaAsset::query()->whereIn('id', $offer->deliverable_asset_ids)->orderBy('role')->orderBy('id')->get()->map(fn ($asset) => $this->asset($asset))->all(),
        ];
    }

    public function license(LicenseVersion $version): array
    {
        $template = $version->template()->firstOrFail();
        $evidence = $version->reviewEvidence()->first();

        return [
            'id' => $version->id, 'template_id' => $template->id, 'name' => $template->name, 'version' => $version->version, 'type' => $template->type,
            'authored_source' => $version->authored_source, 'source_hash' => $version->source_hash, 'model_hash' => $version->model_hash,
            'structured_terms' => $version->structured_terms, 'features' => $version->features(), 'required_asset_roles' => $version->requiredAssetRoles(),
            'renderer_version' => $version->renderer_version, 'render_fixture_hash' => $version->render_fixture_hash,
            'submission_hash' => $version->submission_hash, 'review_evidence_id' => $evidence?->id, 'review_evidence_hash' => $evidence?->evidence_hash,
            'approval_reference' => $version->approval_reference, 'approved_by' => $version->approved_by, 'approved_at' => $version->approved_at?->toISOString(),
            'effective_from' => $version->effective_from?->toISOString(), 'effective_until' => $version->effective_until?->toISOString(),
        ];
    }

    public function rightsHash(RightsDeclaration $rights): string
    {
        return CanonicalJson::hash($rights->only(['id', 'track_id', 'provenance_reference', 'sample_disclosure', 'status', 'verified_by']) + ['verified_at' => $rights->verified_at?->toISOString()]);
    }

    public function asset(MediaAsset $asset): array
    {
        $snapshot = $asset->only(['id', 'role', 'sha256', 'mime_type', 'size_bytes', 'original_name', 'parent_asset_id', 'processing_run_id']);

        // Preserve byte-identical historical snapshots for all existing non-stems offers.
        return $asset->role === 'stems_zip' ? $snapshot + ['recording_binding' => app(RecordingAssociation::class)->snapshot($asset)] : $snapshot;
    }
}
