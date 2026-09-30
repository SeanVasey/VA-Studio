<?php

namespace App\Http\Controllers;

use App\Domain\SiteBuilder\Models\SiteImageVariant;
use App\Domain\SiteBuilder\SiteImageFiles;
use Symfony\Component\HttpFoundation\Response;

/** Staff-only view of a processed site image, private and uncacheable whether it succeeds or not. */
final class SiteImagePreviewController extends Controller
{
    private const HEADERS = ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex, nofollow', 'Referrer-Policy' => 'no-referrer'];

    public function __invoke(string $variant): Response
    {
        $record = ctype_digit($variant) ? SiteImageVariant::query()->find((int) $variant) : null;
        $bytes = $record === null ? null : app(SiteImageFiles::class)->verifiedBytes($record);
        if ($bytes === null) {
            return response('', 404, self::HEADERS);
        }

        return response($bytes, 200, self::HEADERS + ['Content-Type' => $record->mimeType()]);
    }
}
