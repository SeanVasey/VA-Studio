<?php

namespace App\Http\Controllers;

use App\Domain\Catalog\PublicCatalog;
use App\Domain\Commerce\Checkout\CheckoutPolicy;
use App\Domain\Commerce\Orders\OrderPolicy;
use App\Domain\Customers\CustomerAccessPolicy;
use App\Domain\SiteBuilder\EditorialContent;
use App\Domain\SiteBuilder\SiteContent;
use App\Domain\SiteBuilder\SiteImagePresentation;
use App\Support\StorefrontMetadata;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StorefrontController extends Controller
{
    public function index(Request $request, ?string $slug = null): Response
    {
        $service = app(PublicCatalog::class);
        $selected = $slug === null ? null : $service->track($slug);
        $catalog = $service->page($request);
        $selectedTrack = $selected['tracks'][0] ?? null;
        $catalog['licenseTiers'] = collect(array_merge($catalog['licenseTiers'], $selected['licenseTiers'] ?? []))->unique('id')->values()->all();
        $siteContent = app(SiteContent::class)->current();
        $images = app(SiteImagePresentation::class);
        $metadata = app(StorefrontMetadata::class)->forPage($selectedTrack, $siteContent, $images->share($siteContent));

        return Inertia::render('Storefront', $catalog + ['siteContent' => app(EditorialContent::class)->chrome($siteContent), 'siteImages' => $images->storefront($siteContent),
            'selectedTrack' => $selectedTrack, 'selectedTrackSlug' => $slug, 'commerceEnabled' => false,
            'testOrderPreparationEnabled' => app(OrderPolicy::class)->enabled(),
            'customerAccountEnabled' => app(CustomerAccessPolicy::class)->enabled(),
            'testCheckoutEnabled' => app(CheckoutPolicy::class)->enabled(), 'metadata' => $metadata])
            ->withViewData(['metadata' => $metadata]);
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
