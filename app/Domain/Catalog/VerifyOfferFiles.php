<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\Offer;
use App\Domain\Catalog\Models\OfferRevision;
use App\Domain\Catalog\Models\Track;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\RecordingAssociation;
use App\Domain\Media\VerifiedMedia;
use Illuminate\Validation\ValidationException;

/** A new commercial promise always hashes the selected bytes without the public-read cache. */
class VerifyOfferFiles
{
    public function revision(OfferRevision $revision): void
    {
        $ids = [...array_column($revision->snapshot['assets'], 'id'), $revision->snapshot['preview']['id']];
        foreach (MediaAsset::whereIn('id', $ids)->get() as $asset) {
            $ids = [...$ids, ...app(RecordingAssociation::class)->relatedAssetIds($asset)];
        }
        foreach (array_unique($ids) as $id) {
            $asset = MediaAsset::find($id);
            $path = $asset ? app(VerifiedMedia::class)->path($asset) : null;
            if ($path) { clearstatcache(true, $path); }
            $hash = $path ? @hash_file('sha256', $path) : false;
            if (! $path || ! is_string($hash) || ! hash_equals($asset->sha256, $hash) || @filesize($path) !== $asset->size_bytes) {
                throw ValidationException::withMessages(['offer' => 'Activation requires fresh digests of the exact frozen files.']);
            }
        }
    }

    public function handle(Offer $offer, Track $track): void
    {
        $preview = $track->assets()->where('role', 'preview_tagged')->where('status', 'ready')->latest('id')->firstOrFail();
        $assets = MediaAsset::query()->whereIn('id', [...$offer->deliverable_asset_ids, $preview->id])->get();
        $related = $assets->flatMap(fn (MediaAsset $asset) => app(RecordingAssociation::class)->relatedAssetIds($asset));
        $assets = $assets->merge(MediaAsset::whereIn('id', $related)->get())->unique('id');
        foreach ($assets as $asset) {
            $path = app(VerifiedMedia::class)->path($asset);
            if ($path) { clearstatcache(true, $path); }
            $hash = $path ? @hash_file('sha256', $path) : false;
            if (! $path || ! is_string($hash) || ! hash_equals($asset->sha256, $hash) || @filesize($path) !== $asset->size_bytes) {
                throw ValidationException::withMessages(['offer' => 'Publication requires a fresh digest match for every deliverable and preview.']);
            }
        }
    }
}
