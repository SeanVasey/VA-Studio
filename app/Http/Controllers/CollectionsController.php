<?php

namespace App\Http\Controllers;

use App\Domain\Collections\CollectionPresentation;
use App\Domain\Collections\CollectionsPolicy;
use App\Domain\SiteBuilder\EditorialContent;
use App\Domain\SiteBuilder\SiteContent;
use Inertia\Inertia;
use Inertia\Response;

/** Public collections: browse groupings of individually licensed tracks (BL-54, FP-006). Read-only; no price, cart or bundle. 404 whenever the flag is off. */
final class CollectionsController extends Controller
{
    public function index(): Response
    {
        abort_unless(app(CollectionsPolicy::class)->enabled(), 404);
        $collections = app(CollectionPresentation::class);
        $metadata = $collections->metadata(null);

        return Inertia::render('Collections', [
            'siteContent' => app(EditorialContent::class)->chrome(app(SiteContent::class)->current()),
            'collections' => $collections->index(), 'metadata' => $metadata,
        ])->withViewData(['metadata' => $metadata]);
    }

    public function show(string $slug): Response
    {
        abort_unless(app(CollectionsPolicy::class)->enabled(), 404);
        $collections = app(CollectionPresentation::class);
        $collection = $collections->detail($slug);
        abort_if($collection === null, 404);
        $metadata = $collections->metadata($collection);

        return Inertia::render('Collection', [
            'siteContent' => app(EditorialContent::class)->chrome(app(SiteContent::class)->current()),
            'collection' => $collection, 'metadata' => $metadata,
        ])->withViewData(['metadata' => $metadata]);
    }
}
