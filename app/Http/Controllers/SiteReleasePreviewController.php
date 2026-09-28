<?php

namespace App\Http\Controllers;

use App\Domain\Catalog\PublicCatalog;
use App\Domain\SiteBuilder\Models\SiteRelease;
use App\Domain\SiteBuilder\SiteContent;
use App\Support\StorefrontMetadata;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class SiteReleasePreviewController extends Controller
{
    public function __invoke(Request $request, SiteRelease $release): Response
    {
        // Authorization is refreshed by the service, in addition to panel authentication/MFA middleware.
        $content = app(SiteContent::class)->preview($release->id, $request->user());
        $metadata = app(StorefrontMetadata::class)->forPage(null, $content);
        $metadata['robots'] = 'noindex, nofollow';

        return Inertia::render('Storefront', app(PublicCatalog::class)->page($request) + [
            'siteContent' => $content, 'sitePreview' => true, 'selectedTrack' => null,
            'commerceEnabled' => false, 'testOrderPreparationEnabled' => false, 'testCheckoutEnabled' => false,
            'metadata' => $metadata,
        ])->withViewData(['metadata' => $metadata])->toResponse($request)->withHeaders([
            'Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
