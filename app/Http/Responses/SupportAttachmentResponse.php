<?php

namespace App\Http\Responses;

use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final class SupportAttachmentResponse
{
    public static function failure(\Throwable $error): Response
    {
        try {
            Log::error('Private attachment request failed.', ['exception_class' => $error::class]);
        } catch (\Throwable) { /* Preserve the private response. */
        }

        return self::error(503);
    }

    public static function protect(Response $response, bool $page = false): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        $html = $page && $response->getStatusCode() < 400 && str_starts_with($response->headers->get('Content-Type', ''), 'text/html');
        $response->headers->set('Content-Security-Policy', $html
            ? "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'none'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'; form-action 'self'"
            : "default-src 'none'; sandbox");
        $response->setVary(array_values(array_unique([...$response->getVary(), 'Cookie'])));

        return $response;
    }

    public static function error(int $status): Response
    {
        $message = match ($status) {
            403, 404 => 'These private attachments are unavailable.',
            409 => 'This action could not be confirmed. Reload the attachment list before an explicit retry.',
            413 => 'This file exceeds the configured technical limit.',
            415, 422 => 'Check the file and request format.',
            419 => 'Your session expired. Open this page again.',
            429 => 'Wait before trying this request again.',
            default => 'This action could not be confirmed. Check its status before retrying.',
        };

        return self::protect(response()->json(['code' => 'PRIVATE_ATTACHMENT_UNAVAILABLE', 'message' => $message], $status >= 500 ? 503 : $status));
    }
}
