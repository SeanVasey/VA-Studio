<?php

namespace App\Http\Controllers;

use App\Domain\SupportAttachments\AttachmentException;
use App\Domain\SupportAttachments\SupportAttachments;
use App\Http\Responses\SupportAttachmentResponse as PrivateResponse;
use App\Support\SupportAttachmentRequestContext;
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
            $context = SupportAttachmentRequestContext::capture($request);
            $kind = $request->route('support_kind');
            $service = app(SupportAttachments::class);
            Inertia::encryptHistory();
            $request->attributes->set('_support_attachment_page', true);

            $response = Inertia::render('PrivateSupportAttachments', [
                'sourceKind' => $kind, 'sourceId' => $source, 'audience' => $actor->audience,
                'renderScope' => bin2hex(random_bytes(16)),
            ])->toResponse($request);
            $service->list($kind, $source, $actor);
            $context->prove();

            return PrivateResponse::protect($response, true);
        } catch (AttachmentException $error) {
            return PrivateResponse::error($error->status);
        } catch (\Throwable $error) {
            return PrivateResponse::failure($error);
        }
    }
}
