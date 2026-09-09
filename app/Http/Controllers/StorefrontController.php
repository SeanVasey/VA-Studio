<?php

namespace App\Http\Controllers;

use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublicationReadiness;
use App\Support\StorefrontMetadata;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class StorefrontController extends Controller
{
    public function index(?string $slug = null): Response
    {
        $catalog = $this->catalog();
        $selectedTrack = $slug === null ? null : collect($catalog['tracks'])->firstWhere('slug', $slug);
        if ($slug !== null) {
            abort_if($selectedTrack === null, 404);
        }

        $metadata = app(StorefrontMetadata::class)->forPage($selectedTrack);

        return Inertia::render('Storefront', $catalog + ['selectedTrackSlug' => $slug, 'commerceEnabled' => false, 'metadata' => $metadata])
            ->withViewData(['metadata' => $metadata]);
    }

    public function json(): JsonResponse
    {
        return response()->json($this->catalog() + ['commerceEnabled' => false]);
    }

    private function catalog(): array
    {
        $tracks = Track::query()->where('status', 'published')->with(['assets', 'offers.currentRevision'])->orderByDesc('published_at')->get();
        // Eligibility is current; commercial fields come only from the immutable active revision.
        $tracks = $tracks->filter(fn (Track $track) => app(PublicationReadiness::class)->blockers($track) === []);
        $licenses = $tracks->flatMap(fn ($track) => $track->offers->where('is_active', true)->map(fn ($offer) => $offer->currentRevision->snapshot['license']))->unique('id');

        return [
            'tracks' => $tracks->map(function (Track $track) {
                $assets = $track->assets->where('status', 'ready')->sortByDesc('id');
                $preview = $assets->firstWhere('role', 'preview_tagged');

                return [
                    'id' => $track->id, 'slug' => $track->slug, 'title' => $track->title, 'artist' => $track->artist,
                    'bpm' => $track->bpm, 'musicalKey' => $track->musical_key, 'genre' => $track->genre, 'mood' => $track->mood,
                    'durationSeconds' => $preview->technical_metadata['duration_seconds'], 'tags' => $track->tags ?? [], 'waveform' => $preview->technical_metadata['waveform'],
                    'artworkUrl' => route('media.public', $assets->firstWhere('role', 'artwork')->id),
                    'previewUrl' => route('media.public', $preview->id),
                    'shareUrl' => route('tracks.show', $track->slug),
                    'offers' => $track->offers->where('is_active', true)->map(function ($offer) {
                        $revision = $offer->currentRevision;
                        $license = $revision->snapshot['license'];

                        return [
                            'id' => $offer->id, 'offerRevisionId' => $revision->id, 'licenseVersionId' => $license['id'], 'licenseName' => $license['name'],
                            'priceMinor' => $revision->price_minor, 'currency' => $revision->currency, 'deliverableRoles' => $license['required_asset_roles'],
                        ];
                    })->values()->all(),
                ];
            })->values()->all(),
            'licenseTiers' => $licenses->map(fn ($license) => [
                'id' => $license['id'], 'name' => $license['name'], 'version' => $license['version'], 'type' => $license['type'],
                'features' => $license['features'], 'requiredAssetRoles' => $license['required_asset_roles'],
            ])->values()->all(),
        ];
    }
}
