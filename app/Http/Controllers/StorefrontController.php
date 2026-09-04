<?php

namespace App\Http\Controllers;

use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublicationReadiness;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class StorefrontController extends Controller
{
    public function index(?string $slug = null): Response
    {
        $catalog = $this->catalog();
        if ($slug !== null) {
            abort_unless(collect($catalog['tracks'])->contains('slug', $slug), 404);
        }

        return Inertia::render('Storefront', $catalog + ['selectedTrackSlug' => $slug, 'commerceEnabled' => false]);
    }

    public function json(): JsonResponse
    {
        return response()->json($this->catalog() + ['commerceEnabled' => false]);
    }

    private function catalog(): array
    {
        $tracks = Track::query()->where('status', 'published')->with(['assets', 'offers.licenseVersion.template'])->orderByDesc('published_at')->get();
        // Fail closed if a dependent declaration/offer/asset no longer passes readiness.
        $tracks = $tracks->filter(fn (Track $track) => app(PublicationReadiness::class)->blockers($track) === []);
        $versions = $tracks->flatMap(fn ($track) => $track->offers->where('is_active', true)->map(fn ($offer) => $offer->licenseVersion))->unique('id');

        return [
            'tracks' => $tracks->map(function (Track $track) {
                $assets = $track->assets->where('status', 'ready')->sortByDesc('id');

                return [
                    'id' => $track->id, 'slug' => $track->slug, 'title' => $track->title, 'artist' => $track->artist,
                    'bpm' => $track->bpm, 'musicalKey' => $track->musical_key, 'genre' => $track->genre, 'mood' => $track->mood,
                    'durationSeconds' => $track->duration_seconds, 'tags' => $track->tags ?? [], 'waveform' => $track->waveform ?? [],
                    'artworkUrl' => route('media.public', $assets->firstWhere('role', 'artwork')->id),
                    'previewUrl' => route('media.public', $assets->firstWhere('role', 'preview_tagged')->id),
                    'shareUrl' => route('tracks.show', $track->slug),
                    'offers' => $track->offers->where('is_active', true)->map(fn ($offer) => [
                        'id' => $offer->id, 'licenseVersionId' => $offer->license_version_id, 'licenseName' => $offer->licenseVersion->template->name,
                        'priceMinor' => $offer->price_minor, 'currency' => $offer->currency, 'deliverableRoles' => $offer->licenseVersion->requiredAssetRoles(),
                    ])->values()->all(),
                ];
            })->values()->all(),
            'licenseTiers' => $versions->map(fn ($version) => [
                'id' => $version->id, 'name' => $version->template->name, 'version' => $version->version, 'type' => $version->template->type,
                'features' => $version->features(), 'requiredAssetRoles' => $version->requiredAssetRoles(),
            ])->values()->all(),
        ];
    }
}
