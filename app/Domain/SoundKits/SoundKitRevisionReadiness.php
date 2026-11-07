<?php

namespace App\Domain\SoundKits;

use App\Domain\Catalog\FamilyRevisionSelection;
use App\Domain\Media\MediaFailure;
use App\Domain\SoundKits\Models\SoundKitRevision;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;

/** Internal staff inspection only. A returned value grants no authority beyond this completed technical check. */
final class SoundKitRevisionReadiness
{
    public function capture(FamilyRevisionSelection $selection, User $actor): array
    {
        // Literal opt-in, before authority/resource reads. This flag never enables quoting or a public mount.
        if (config('product-families.wav_sample_kit.technical_revision_preparation') !== true) {
            throw new MediaFailure('family_preparation_disabled', 'Family revision preparation is disabled.');
        }

        return DB::transaction(function () use ($selection, $actor): array {
            $drafts = app(SoundKitDrafts::class);
            $drafts->actor($actor);
            $route = SoundKitRevision::query()->where('public_id', $selection->revisionId)->first(['id', 'sound_kit_draft_id']);
            if ($route === null) {
                $this->changed();
            }
            // Same actor→draft→revision order as intake/retry/processing. Never accept a caller's hydrated model.
            $draft = $drafts->lock($route->sound_kit_draft_id);
            $revision = SoundKitRevision::query()->whereKey($route->id)->where('sound_kit_draft_id', $draft->id)
                ->where('public_id', $selection->revisionId)->lockForUpdate()->first();
            if ($revision === null || $revision->status !== 'ready' || ! is_string($revision->manifest_sha256)
                || ! hash_equals($selection->manifestHash, $revision->manifest_sha256)
                || $revision->archive_path !== 'sound-kits/revisions/'.$revision->public_id.'/samples.zip') {
                $this->changed();
            }
            // Reuse the retained manifest, scan evidence, profile and exact private archive byte verification.
            $manifest = app(SoundKitManifest::class)->verified($revision);
            $snapshot = ['schema' => 'wav-kit-technical-revision-v1', 'canonicalization' => CanonicalJson::VERSION,
                'purpose' => 'internal_family_revision_preparation', 'family' => $selection->family,
                'licensed' => false, 'payable' => false, 'commercial_blockers' => ['kit_license_policy_unset'],
                'kit_id' => $draft->public_id, 'revision_id' => $revision->public_id, 'revision_number' => $revision->number,
                'manifest_sha256' => $revision->manifest_sha256, 'manifest' => $manifest,
                'archive' => $manifest['archive'], 'member_count' => count($manifest['members'])];

            return ['snapshot' => $snapshot, 'snapshot_sha256' => CanonicalJson::hash($snapshot)];
        });
    }

    private function changed(): never
    {
        throw new MediaFailure('family_revision_changed', 'The exact verified family revision is unavailable.');
    }
}
