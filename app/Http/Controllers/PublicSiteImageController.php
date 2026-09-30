<?php

namespace App\Http\Controllers;

use App\Domain\SiteBuilder\Models\SiteImageVariant;
use App\Domain\SiteBuilder\SiteImageFiles;
use Illuminate\Database\Query\Builder;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a prepared site image by its content hash once a release using it has been live (D-25). Anything else, missing or
 * never live, gets the same uncacheable 404, so the route reveals nothing about private images.
 */
final class PublicSiteImageController extends Controller
{
    public function __invoke(string $file): Response
    {
        if (preg_match('/\A([a-f0-9]{64})\.(jpg|webp)\z/D', $file, $match) === 1) {
            // Identical outputs can share a hash, so any live candidate with verified bytes will do. Every candidate is tried, in id
            // order and a chunk at a time: a damaged copy must not hide an intact one, and only staff can add candidates.
            $candidates = SiteImageVariant::query()->where('sha256', $match[1])->where('format', $match[2] === 'webp' ? 'webp' : 'jpeg')
                ->whereExists(fn (Builder $live): Builder => $live->selectRaw('1')->from('site_release_images')
                    ->join('site_publication_revisions', 'site_publication_revisions.release_id', '=', 'site_release_images.site_release_id')
                    ->whereColumn('site_release_images.site_image_id', 'site_image_variants.site_image_id'))
                ->lazyById(20);
            foreach ($candidates as $variant) {
                $bytes = app(SiteImageFiles::class)->verifiedBytes($variant);
                if ($bytes !== null) {
                    return response($bytes, 200, [
                        'Content-Type' => $variant->mimeType(), 'Cache-Control' => 'public, max-age=31536000, immutable', 'X-Content-Type-Options' => 'nosniff',
                    ]);
                }
            }
        }

        return response('', 404, ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
