<?php

namespace App\Http\Controllers;

use App\Domain\Services\Catalog\ServiceCatalog;
use App\Domain\SiteBuilder\EditorialContent;
use App\Domain\SiteBuilder\SiteContent;
use App\Domain\SiteBuilder\SiteImagePresentation;
use App\Support\StorefrontMetadata;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public service pages (BL-64), default off. While the catalog flag is off every path answers the ordinary 404 before
 * any content is read. When on, only staff-approved, unwithdrawn services appear; no price is invented and nothing here
 * takes payment: the request button opens the ordinary contact inquiry.
 */
final class ServiceCatalogController extends Controller
{
    private const INTRO = 'Custom work from VASEY.AUDIO. Request a service and Sean will reply with a written quote; nothing is charged by sending a request.';

    public function index(ServiceCatalog $catalog): Response
    {
        abort_unless($catalog->enabled(), 404);

        return $this->render('Services', self::INTRO, '/services', $catalog->all(), null);
    }

    public function show(string $slug, ServiceCatalog $catalog): Response
    {
        abort_unless($catalog->enabled(), 404);
        $service = $catalog->find($slug);
        abort_if($service === null, 404);

        return $this->render($service['title'], $service['description'] !== '' ? $service['description'] : self::INTRO, $service['path'], [], $service);
    }

    private function render(string $title, string $description, string $path, array $services, ?array $service): Response
    {
        $content = app(SiteContent::class)->current();
        $metadata = app(StorefrontMetadata::class)->forEditorial(
            ['section' => 'services', 'kind' => $service === null ? 'collection' : 'entry', 'path' => $path, 'title' => $title, 'description' => $description],
            app(SiteImagePresentation::class)->share($content));

        return Inertia::render('ServiceCatalog', [
            'siteContent' => app(EditorialContent::class)->chrome($content), 'services' => $services, 'service' => $service,
            'intro' => self::INTRO, 'metadata' => $metadata,
        ])->withViewData(['metadata' => $metadata]);
    }
}
