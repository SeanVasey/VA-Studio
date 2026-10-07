<?php

namespace App\Http\Controllers;

use App\Domain\SupportAttachments\AttachmentException;
use App\Domain\SupportAttachments\SupportAttachments;
use App\Http\Responses\SupportAttachmentResponse as PrivateResponse;
use App\Support\SupportAttachmentRequestIdentity;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class SupportAttachmentPageController
{
    public function __invoke(Request $request, string $source): Response
    {
        try {
            $actor = app(SupportAttachmentRequestIdentity::class)->actor($request);
            $kind = $request->route('support_kind');
            app(SupportAttachments::class)->list($kind, $source, $actor);
            Inertia::encryptHistory();
            $request->attributes->set('_support_attachment_page', true);

            return PrivateResponse::protect(Inertia::render('PrivateSupportAttachments', [
                'sourceKind' => $kind, 'sourceId' => $source, 'audience' => $actor->audience,
                'renderScope' => bin2hex(random_bytes(16)),
            ])->toResponse($request), true);
        } catch (AttachmentException $error) {
            return PrivateResponse::error($error->status);
        } catch (\Throwable $error) {
            return PrivateResponse::failure($error);
        }
    }
}
