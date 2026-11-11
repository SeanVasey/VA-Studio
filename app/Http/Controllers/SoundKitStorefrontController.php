<?php

namespace App\Http\Controllers;

use App\Domain\SiteBuilder\EditorialContent;
use App\Domain\SiteBuilder\SiteContent;
use App\Domain\SoundKits\Products\SoundKitPresentation;
use App\Domain\SoundKits\Products\SoundKitProduct;
use App\Domain\SoundKits\Products\SoundKitProductMedia;
use App\Domain\SoundKits\Products\SoundKitProducts;
use App\Domain\SoundKits\Products\SoundKitStorefrontPolicy;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Public sound-kit pages (BL-53, FP-007). Read-only; no cart, checkout or download. 404 whenever the flag is off. */
final class SoundKitStorefrontController extends Controller
{
    public function index(): Response
    {
        abort_unless(app(SoundKitStorefrontPolicy::class)->enabled(), 404);
        $kits = app(SoundKitPresentation::class);
        $metadata = $kits->metadata(null);

        return Inertia::render('SoundKits', [
            'siteContent' => app(EditorialContent::class)->chrome(app(SiteContent::class)->current()),
            'kits' => $kits->index(), 'metadata' => $metadata,
        ])->withViewData(['metadata' => $metadata]);
    }

    public function show(string $slug): Response
    {
        abort_unless(app(SoundKitStorefrontPolicy::class)->enabled(), 404);
        $kits = app(SoundKitPresentation::class);
        $kit = $kits->detail($slug);
        abort_if($kit === null, 404);
        $metadata = $kits->metadata($kit);

        return Inertia::render('SoundKit', [
            'siteContent' => app(EditorialContent::class)->chrome(app(SiteContent::class)->current()),
            'kit' => $kit, 'metadata' => $metadata,
        ])->withViewData(['metadata' => $metadata]);
    }

    /** The tagged preview of the kit's approved demo. 404 unless the pages are on, the kit is listed and this is its approved demo. */
    public function demo(string $slug, string $demo): BinaryFileResponse
    {
        abort_unless(app(SoundKitStorefrontPolicy::class)->enabled(), 404);
        $product = SoundKitProduct::query()->where('is_published', true)->where('slug', $slug)->first();
        abort_unless($product !== null && app(SoundKitProducts::class)->listed($product), 404);
        $approved = app(SoundKitProductMedia::class)->demo($product);
        abort_unless($approved !== null && hash_equals($approved->public_id, $demo), 404);
        // The exact bytes are hashed against the retained evidence before they are sent.
        $path = SoundKitProductMedia::previewPath($approved);
        abort_unless($path !== null, 404);

        return response()->file($path, ['Content-Type' => 'audio/mpeg', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'public, max-age=300']);
    }
}
