<?php

namespace App\Http\Controllers;

use App\Domain\Catalog\PublicCatalog;
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
        $metadata = app(StorefrontMetadata::class)->forPage($selectedTrack);

        return Inertia::render('Storefront', $catalog + ['selectedTrack' => $selectedTrack, 'selectedTrackSlug' => $slug, 'commerceEnabled' => false, 'metadata' => $metadata])
            ->withViewData(['metadata' => $metadata]);
    }

    public function json(Request $request): JsonResponse
    {
        return response()->json(app(PublicCatalog::class)->page($request) + ['commerceEnabled' => false]);
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
