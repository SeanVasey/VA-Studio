<?php

namespace App\Http\Controllers;

use App\Domain\Catalog\PublicCatalog;
use App\Domain\SiteBuilder\EditorialContent;
use App\Domain\SiteBuilder\Models\SiteRelease;
use App\Domain\SiteBuilder\SiteContent;
use App\Domain\SiteBuilder\SiteImagePresentation;
use App\Http\Middleware\SitePreviewPrivacy;
use App\Support\StorefrontMetadata;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class SiteReleasePreviewController extends Controller
{
    public function __invoke(Request $request, SiteRelease $release, ?string $section = null, ?string $slug = null): Response
    {
        // Authorization is refreshed by the service, in addition to panel authentication/MFA middleware.
        $content = app(SiteContent::class)->preview($release->id, $request->user());
        $base = route('filament.admin.site-releases.preview', $release, false);
        if ($section !== null) {
            $page = app(EditorialContent::class)->page($content, $section, $slug, false);
            abort_if($page === null, 404);
            $metadata = app(StorefrontMetadata::class)->forEditorial($page, app(SiteImagePresentation::class)->share($content, true));
            $metadata['robots'] = 'noindex, nofollow';

            return SitePreviewPrivacy::protect(Inertia::render('Editorial', [
                'siteContent' => app(EditorialContent::class)->chrome($content), 'editorial' => $page,
                'sitePreview' => true, 'sitePreviewBase' => $base, 'metadata' => $metadata,
                'commerceEnabled' => false, 'testOrderPreparationEnabled' => false, 'testCheckoutEnabled' => false,
            ])->withViewData(['metadata' => $metadata])->toResponse($request));
        }
        $images = app(SiteImagePresentation::class);
        $metadata = app(StorefrontMetadata::class)->forPage(null, $content, $images->share($content, true));
        $metadata['robots'] = 'noindex, nofollow';

        return SitePreviewPrivacy::protect(Inertia::render('Storefront', app(PublicCatalog::class)->page($request) + [
            'siteContent' => app(EditorialContent::class)->chrome($content), 'siteImages' => $images->storefront($content, true),
            'sitePreview' => true, 'sitePreviewBase' => $base, 'selectedTrack' => null,
            'commerceEnabled' => false, 'testOrderPreparationEnabled' => false, 'testCheckoutEnabled' => false,
            'metadata' => $metadata,
        ])->withViewData(['metadata' => $metadata])->toResponse($request));
    }
}
