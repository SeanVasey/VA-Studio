<?php

namespace App\Http\Controllers;

use App\Domain\Catalog\PublicCatalog;
use App\Domain\Media\VerifiedMedia;
use App\Http\Responses\PublicTrackEmbedResponse;
use Symfony\Component\HttpFoundation\Response;

final class PublicTrackEmbedController extends Controller
{
    public function show(string $slug): Response
    {
        $preview = app(PublicCatalog::class)->preview($slug);
        $base = rtrim((string) config('app.url'), '/');
        $url = parse_url($base);
        abort_unless(is_array($url) && in_array($url['scheme'] ?? null, ['http', 'https'], true)
            && ! empty($url['host']) && ! isset($url['user']) && ! isset($url['pass'])
            && ! isset($url['query']) && ! isset($url['fragment']), 503);

        return PublicTrackEmbedResponse::protect(response()->view('public-track-embed', [
            'title' => $preview->track->title,
            'artist' => $preview->track->artist,
            'storeUrl' => $base.route('tracks.show', ['slug' => $slug], false),
            'previewUrl' => route('embeds.preview', ['slug' => $slug, 'asset' => $preview->id], false),
        ]));
    }

    public function preview(string $slug, string $asset): Response
    {
        // Re-evaluate publication, rights, offers and inventory on every GET/HEAD/range request.
        // A copied old URL cannot select a master, another track, or a superseded preview.
        $preview = app(PublicCatalog::class)->preview($slug);
        abort_unless((string) $preview->id === $asset, 404);
        $path = app(VerifiedMedia::class)->path($preview);
        abort_unless($path !== null, 404);

        return PublicTrackEmbedResponse::protect(response()->file($path, [
            'Content-Type' => $preview->mime_type,
            'Content-Disposition' => 'inline; filename="preview.mp3"',
        ]));
    }
}
