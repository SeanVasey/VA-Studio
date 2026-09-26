<?php

namespace App\Domain\Commerce\Finalization;

use App\Domain\Catalog\Models\OfferRevision;
use App\Domain\Catalog\OfferSnapshot;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\VerifiedMedia;
use App\Support\CanonicalJson;

/** Fresh byte reads precede the transaction; WP-08 must recheck durability before activation. */
class FinalizationAssets
{
    public function inspect(array $original): bool
    {
        $available = true;
        foreach ($original['lines'] as $line) {
            $snapshot = $line['selection']['offer_snapshot'];
            $revision = OfferRevision::find($line['offer_revision_id']);
            if (! $revision || CanonicalJson::encode($revision->snapshot) !== CanonicalJson::encode($snapshot)
                || $revision->snapshot_hash !== CanonicalJson::hash($snapshot)) { throw new FinalizationException('changed'); }
            $manifest = $snapshot['assets'];
            $roles = array_column($manifest, 'role'); sort($roles);
            $required = $snapshot['license']['required_asset_roles']; sort($required);
            if ($manifest === [] || $roles !== $required || count(array_unique(array_column($manifest, 'id'))) !== count($manifest)) {
                throw new FinalizationException('changed');
            }
            foreach ($manifest as $entry) {
                $asset = MediaAsset::find($entry['id']);
                if (! $asset || $asset->track_id !== $snapshot['product']['id']
                    || CanonicalJson::encode(app(OfferSnapshot::class)->asset($asset)) !== CanonicalJson::encode($entry)) {
                    throw new FinalizationException('changed');
                }
                if ($asset->status !== 'ready') { $available = false; continue; }
                $path = app(VerifiedMedia::class)->path($asset);
                // An unavailable local store/provenance reader is retryable, not proof of permanent loss.
                if ($path === null) { throw new FinalizationException('retry'); }
                clearstatcache(true, $path);
                $actual = @hash_file('sha256', $path); $bytes = @filesize($path);
                if (! is_string($actual) || $bytes === false) { throw new FinalizationException('retry'); }
                if (! hash_equals($entry['sha256'], $actual) || $bytes !== $entry['size_bytes']) { $available = false; }
            }
        }

        return $available;
    }
}
