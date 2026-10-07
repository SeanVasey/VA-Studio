<?php

namespace App\Http\Controllers;

use App\Domain\SupportAttachments\AttachmentActor;
use App\Domain\SupportAttachments\AttachmentActorResolver;
use App\Domain\SupportAttachments\AttachmentException;
use App\Domain\SupportAttachments\RehearsalAttachmentActors;
use App\Domain\SupportAttachments\SupportAttachments;
use App\Http\Requests\SupportAttachmentRequest;
use App\Http\Responses\SupportAttachmentResponse as PrivateResponse;
use App\Support\InquiryOwner;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

final class SupportAttachmentController
{
    public function __invoke(Request $request, string $source, ?string $attachment = null): Response
    {
        $temporary = null;
        try {
            $audience = $request->route('support_audience');
            $kind = $request->route('support_kind');
            $action = $request->route('support_action');
            $actor = match ($audience) {
                'visitor' => AttachmentActor::visitor(app(InquiryOwner::class)->forRequest($request), $request->user()),
                'operator' => $request->user() === null ? throw new AttachmentException(403) : AttachmentActor::operator($request->user()),
                'customer' => $this->customer($request, $kind, $source),
                default => throw new AttachmentException(403),
            };
            $service = app(SupportAttachments::class);
            if ($action === 'list') {
                return PrivateResponse::protect(response()->json($service->list($kind, $source, $actor)));
            }
            if ($action === 'upload') {
                $body = SupportAttachmentRequest::upload($request);
                // Authorize before copying a private HTTP body, then re-prove before retaining bytes.
                $service->list($kind, $source, $actor);
                $temporary = tmpfile();
                AttachmentException::require(is_resource($temporary));
                $stream = $request->getContent(true);
                AttachmentException::require(is_resource($stream));
                $bytes = 0;
                while (! feof($stream)) {
                    $chunk = fread($stream, min(1048576, 5242881 - $bytes));
                    AttachmentException::require(is_string($chunk) && ($chunk !== '' || feof($stream)));
                    $bytes += strlen($chunk);
                    AttachmentException::require($bytes <= 5242880, 413);
                    AttachmentException::require(fwrite($temporary, $chunk) === strlen($chunk));
                }
                AttachmentException::require($bytes > 0, 422);
                fflush($temporary);
                $path = stream_get_meta_data($temporary)['uri'];
                $result = $service->intake($kind, $source, $body['sourceVersion'], $actor, $body['requestKey'], $body['name'], $path);

                return PrivateResponse::protect(response()->json($result, $result['replayed'] ? 200 : 201));
            }
            $body = SupportAttachmentRequest::command($request, $action === 'process');
            if ($action === 'process') {
                return PrivateResponse::protect(response()->json(['attachment' => $service->process($kind, $source, $body['sourceVersion'], $attachment, $body['attempt'], $actor)]));
            }
            if ($action === 'delete') {
                return PrivateResponse::protect(response()->json(['attachment' => $service->delete($kind, $source, $attachment, $actor)]));
            }
            AttachmentException::require($action === 'download');
            $prepared = $service->download($kind, $source, $attachment, $actor);
            $response = response()->stream(function () use ($prepared): void {
                $output = fopen('php://output', 'wb');
                try {
                    $prepared['stream']->writeTo($output);
                } finally {
                    if (is_resource($output)) {
                        fclose($output);
                    }
                }
            });
            $response->headers->set('Content-Type', 'application/octet-stream');
            $response->headers->set('Content-Length', (string) $prepared['file']['sizeBytes']);
            $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition('attachment', $prepared['file']['name'], 'private-attachment'));

            return PrivateResponse::protect($response);
        } catch (AttachmentException $error) {
            return PrivateResponse::error($error->status);
        } catch (\Throwable) {
            return PrivateResponse::error(503);
        } finally {
            if (is_resource($temporary)) {
                fclose($temporary);
            }
        }
    }

    private function customer(Request $request, string $kind, string $source): AttachmentActor
    {
        $resolver = app()->bound(AttachmentActorResolver::class) ? app(AttachmentActorResolver::class) : app(RehearsalAttachmentActors::class);

        return $resolver->customer($request, $kind, $source);
    }
}
