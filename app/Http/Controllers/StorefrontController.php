<?php

namespace App\Http\Controllers;

use App\Domain\Catalog\PublicCatalog;
use App\Domain\Commerce\Checkout\CheckoutPolicy;
use App\Domain\Commerce\Orders\OrderPolicy;
use App\Domain\Customers\CustomerAccessPolicy;
use App\Domain\SiteBuilder\EditorialContent;
use App\Domain\SiteBuilder\FamilyEntryPoints;
use App\Domain\SiteBuilder\SiteContent;
use App\Domain\SiteBuilder\SiteImagePresentation;
use App\Support\PublicStructuredData;
use App\Support\StorefrontMetadata;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class StorefrontController extends Controller
{
    public function index(Request $request, ?string $slug = null): Response|SymfonyResponse
    {
        $service = app(PublicCatalog::class);
        $direct = $slug === null ? null : $service->direct($slug);
        $selected = $direct['projection'] ?? null;
        $unlisted = $direct['unlisted'] ?? false;
        $catalog = $service->page($request);
        $selectedTrack = $selected['tracks'][0] ?? null;
        $catalog['licenseTiers'] = collect(array_merge($catalog['licenseTiers'], $selected['licenseTiers'] ?? []))->unique('id')->values()->all();
        $siteContent = app(SiteContent::class)->current();
        $images = app(SiteImagePresentation::class);
        $metadata = app(StorefrontMetadata::class)->forPage($selectedTrack, $siteContent, $images->share($siteContent), $unlisted);
        $structured = app(PublicStructuredData::class);
        // An unlisted page is reachable but not promoted: no structured data, so no rich result can describe it.
        $metadata['structuredData'] = $selectedTrack === null ? $structured->forHome($metadata) : ($unlisted ? null : $structured->forTrack($selectedTrack, $metadata));

        $response = Inertia::render('Storefront', $catalog + ['siteContent' => app(EditorialContent::class)->chrome($siteContent), 'siteImages' => $images->storefront($siteContent),
            'selectedTrack' => $selectedTrack, 'selectedTrackSlug' => $slug, 'commerceEnabled' => false,
            'testOrderPreparationEnabled' => app(OrderPolicy::class)->enabled(),
            'customerAccountEnabled' => app(CustomerAccessPolicy::class)->enabled(),
            'testCheckoutEnabled' => app(CheckoutPolicy::class)->enabled(), 'metadata' => $metadata,
            // Entry points of default-off families appear only while their own policy is on (BL-50).
            'familyLinks' => app(FamilyEntryPoints::class)->links()])
            ->withViewData(['metadata' => $metadata]);

        // The header repeats the page's robots meta so crawlers that read only headers also skip it.
        return $unlisted ? $response->toResponse($request)->header('X-Robots-Tag', 'noindex, nofollow') : $response;
    }

    public function json(Request $request): JsonResponse
    {
        return response()->json(app(PublicCatalog::class)->page($request) + ['commerceEnabled' => false]);
    }

    public function license(string $slug, string $revision): JsonResponse
    {
        return response()->json(app(PublicCatalog::class)->license($slug, $revision))
            ->header('Cache-Control', 'private, no-store');
    }

    public function selections(Request $request): JsonResponse
    {
        $input = $request->validate([
            'trackIds' => ['required', 'array', 'min:1', 'max:10'],
            'trackIds.*' => ['required', 'integer', 'min:1', 'max:9007199254740991', 'distinct'],
        ]);

        return response()->json(app(PublicCatalog::class)->selections($input['trackIds']))
            ->header('Cache-Control', 'private, no-store');
    }
}
