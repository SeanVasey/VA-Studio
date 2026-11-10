<?php

namespace App\Http\Controllers;

use App\Domain\Inquiries\InquiryPolicy;
use App\Domain\Services\Catalog\ServiceCatalog;
use App\Domain\SiteBuilder\EditorialContent;
use App\Domain\SiteBuilder\SiteContent;
use App\Domain\SiteBuilder\SiteImagePresentation;
use App\Support\PublicStructuredData;
use App\Support\StorefrontMetadata;
use App\Support\SupportAttachmentUi;
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
        $metadata = app(StorefrontMetadata::class)->forEditorial($page, app(SiteImagePresentation::class)->share($content));
        $collection = $slug === null ? null : app(EditorialContent::class)->page($content, $section, null, false);
        $metadata['structuredData'] = app(PublicStructuredData::class)->forEditorial($page, $metadata, $collection);

        $inquiry = $section === 'contact' ? app(InquiryPolicy::class)->publicSetup($content) : null;

        // A service page's request button opens the contact form with that service named; unknown or withdrawn names add nothing.
        $service = $section === 'contact' && $slug === null ? app(ServiceCatalog::class)->inquiryPrefill(request()->query('service')) : null;

        return Inertia::render('Editorial', [
            'siteContent' => app(EditorialContent::class)->chrome($content), 'editorial' => $page,
            'contactInquiryEnabled' => $inquiry !== null, 'contactInquiryPrivacyNotice' => $inquiry['privacyNotice'] ?? null,
            'contactInquiryNoticeToken' => $inquiry['noticeToken'] ?? null, 'contactServiceRequest' => $service,
            'supportAttachmentsEnabled' => $section === 'contact' && SupportAttachmentUi::enabled(),
            'sitePreview' => false, 'sitePreviewBase' => null, 'metadata' => $metadata,
            'commerceEnabled' => false, 'testOrderPreparationEnabled' => false, 'testCheckoutEnabled' => false,
        ])->withViewData(['metadata' => $metadata]);
    }
}
