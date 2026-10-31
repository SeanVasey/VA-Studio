<?php

namespace App\Http\Controllers;

use App\Domain\SiteBuilder\EditorialContent;
use App\Domain\SiteBuilder\SiteContent;
use App\Domain\SiteBuilder\SiteImagePresentation;
use App\Support\StorefrontMetadata;
use Inertia\Inertia;
use Inertia\Response;

/** Published legal pages only: the active verified release supplies the text; drafts are reachable only in staff preview. */
final class LegalPageController extends Controller
{
    public function __invoke(string $document): Response
    {
        // One verified snapshot supplies the page, navigation and metadata together.
        $content = app(SiteContent::class)->current();
        $page = app(EditorialContent::class)->legal($content, $document);
        abort_if($page === null, 404);
        $metadata = app(StorefrontMetadata::class)->forEditorial($page, app(SiteImagePresentation::class)->share($content));

        return Inertia::render('Editorial', [
            'siteContent' => app(EditorialContent::class)->chrome($content), 'editorial' => $page,
            'contactInquiryEnabled' => false, 'contactInquiryPrivacyNotice' => null, 'contactInquiryNoticeToken' => null,
            'supportAttachmentsEnabled' => false,
            'sitePreview' => false, 'sitePreviewBase' => null, 'metadata' => $metadata,
            'commerceEnabled' => false, 'testOrderPreparationEnabled' => false, 'testCheckoutEnabled' => false,
        ])->withViewData(['metadata' => $metadata]);
    }
}
