<?php

namespace App\Domain\Commerce;

use App\Domain\Catalog\Models\Offer;
use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublicationReadiness;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\VerifiedMedia;
use App\Domain\Media\RecordingAssociation;

/** Resolve and lock authoritative selections; called only inside a database transaction. */
final class QuoteSelection
{
    public function resolve(array $items, bool $freshDigests): array
    {
        // All tracks, then all offers, in stable numeric order; compatible with publication's track-before-offer order.
        $tracks = Track::query()->whereIn('id', array_column($items, 'trackId'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $offers = Offer::query()->whereIn('id', array_column($items, 'offerId'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $lines = [];
        foreach ($items as $item) {
            $track = $tracks->get($item['trackId']);
            $offer = $offers->get($item['offerId']);
            if (! $track || $track->status !== 'published' || ! $offer || $offer->track_id !== $track->id || ! $offer->is_active || $offer->current_revision_id !== $item['offerRevisionId']) {
                throw new QuoteException('SELECTION_CHANGED', 409);
            }
            $revision = $offer->currentRevision()->first();
            if (! $revision || $revision->license_version_id !== $item['licenseVersionId'] || app(PublicationReadiness::class)->blockers($track) !== [] || app(PublicationReadiness::class)->revisionBlockers($offer, $revision) !== []) {
                throw new QuoteException('SELECTION_CHANGED', 409);
            }
            if ($freshDigests) {
                $assetIds = [...array_column($revision->snapshot['assets'], 'id'), $revision->snapshot['preview']['id']];
                foreach (MediaAsset::whereIn('id', $assetIds)->get() as $asset) {
                    $assetIds = [...$assetIds, ...app(RecordingAssociation::class)->relatedAssetIds($asset)];
                }
                foreach (array_unique($assetIds) as $assetId) {
                    $asset = MediaAsset::find($assetId);
                    $path = $asset ? app(VerifiedMedia::class)->path($asset) : null;
                    if ($path) {
                        clearstatcache(true, $path);
                    }
                    $hash = $path ? @hash_file('sha256', $path) : false;
                    if (! $path || ! is_string($hash) || ! hash_equals($asset->sha256, $hash) || @filesize($path) !== $asset->size_bytes) {
                        throw new QuoteException('SELECTION_CHANGED', 409);
                    }
                }
            }
            $lines[] = ['track_id' => $track->id, 'offer_id' => $offer->id, 'offer_revision_id' => $revision->id, 'license_version_id' => $revision->license_version_id, 'offer_snapshot_hash' => $revision->snapshot_hash, 'offer_snapshot' => $revision->snapshot];
        }

        return $lines;
    }
}
