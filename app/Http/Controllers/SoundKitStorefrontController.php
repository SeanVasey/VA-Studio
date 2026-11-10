<?php

namespace App\Http\Controllers;

use App\Domain\SiteBuilder\EditorialContent;
use App\Domain\SiteBuilder\SiteContent;
use App\Domain\SoundKits\Products\SoundKitPresentation;
use App\Domain\SoundKits\Products\SoundKitStorefrontPolicy;
use Inertia\Inertia;
use Inertia\Response;

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
}
