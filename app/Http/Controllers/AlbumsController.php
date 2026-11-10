<?php

namespace App\Http\Controllers;

use App\Domain\Albums\AlbumPresentation;
use App\Domain\Albums\AlbumsPolicy;
use App\Domain\SiteBuilder\EditorialContent;
use App\Domain\SiteBuilder\SiteContent;
use App\Support\PublicStructuredData;
use Inertia\Inertia;
use Inertia\Response;

/** Public albums for Sean's own released music (BL-67). Read-only; there is no purchase path. 404 whenever the flag is off. */
final class AlbumsController extends Controller
{
    public function index(): Response
    {
        abort_unless(app(AlbumsPolicy::class)->enabled(), 404);
        $albums = app(AlbumPresentation::class);
        $metadata = $albums->metadata(null);

        return Inertia::render('Albums', [
            'siteContent' => app(EditorialContent::class)->chrome(app(SiteContent::class)->current()),
            'albums' => $albums->index(), 'metadata' => $metadata,
        ])->withViewData(['metadata' => $metadata]);
    }

    public function show(string $slug): Response
    {
        abort_unless(app(AlbumsPolicy::class)->enabled(), 404);
        $albums = app(AlbumPresentation::class);
        $album = $albums->detail($slug);
        abort_if($album === null, 404);
        $metadata = $albums->metadata($album);
        $metadata['structuredData'] = app(PublicStructuredData::class)->forAlbum($album, $metadata);

        return Inertia::render('Album', [
            'siteContent' => app(EditorialContent::class)->chrome(app(SiteContent::class)->current()),
            'album' => $album, 'metadata' => $metadata,
        ])->withViewData(['metadata' => $metadata]);
    }
}
