<?php

namespace App\Http\Controllers;

use App\Domain\SiteBuilder\EditorialContent;
use App\Domain\SiteBuilder\SiteContent;
use App\Support\StorefrontMetadata;
use Inertia\Inertia;
use Inertia\Response;

final class EditorialController extends Controller
{
    public function __invoke(string $section, ?string $slug = null): Response
    {
        // One verified snapshot supplies the page, navigation and metadata together.
        $content = app(SiteContent::class)->current();
        $page = app(EditorialContent::class)->page($content, $section, $slug);
        abort_if($page === null, 404);
        $metadata = app(StorefrontMetadata::class)->forEditorial($page);

        return Inertia::render('Editorial', [
            'siteContent' => app(EditorialContent::class)->chrome($content), 'editorial' => $page,
            'sitePreview' => false, 'sitePreviewBase' => null, 'metadata' => $metadata,
            'commerceEnabled' => false, 'testOrderPreparationEnabled' => false, 'testCheckoutEnabled' => false,
        ])->withViewData(['metadata' => $metadata]);
    }
}
