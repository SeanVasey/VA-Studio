<?php

namespace App\Http\Responses;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class InquiryResponse
{
    public static function matches(Request $request): bool
    {
        return $request->is('contact/inquiries', 'contact/inquiries/*', 'admin/customer-inquiries', 'admin/customer-inquiries/*')
            || $request->attributes->get('_inquiry_private_admin') === true;
    }

    public static function protect(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->setVary(array_values(array_unique([...$response->getVary(), 'Cookie'])));

        return $response;
    }

    public static function error(int $status, array $errors = [], array $headers = []): Response
    {
        [$code, $message] = match ($status) {
            403 => ['INQUIRY_REQUEST_DENIED', 'This request cannot be accepted.'],
            404 => ['INQUIRY_UNAVAILABLE', 'The contact form is currently unavailable.'],
            405 => ['INQUIRY_INVALID_METHOD', 'Use the contact form to send an inquiry.'],
            409 => ['INQUIRY_REQUEST_CONFLICT', 'This request could not be confirmed. Review your message before explicitly starting a new request.'],
            413 => ['INQUIRY_TOO_LARGE', 'This inquiry is too large. Shorten the message and try again.'],
            415 => ['INQUIRY_MEDIA_TYPE', 'Use the contact form to send plain text.'],
            419 => ['INQUIRY_REQUEST_EXPIRED', 'Your session expired. Refresh the page before trying again.'],
            400, 422 => ['INQUIRY_VALIDATION_FAILED', 'Check the contact form and try again.'],
            429 => ['INQUIRY_RATE_LIMITED', 'Too many requests. Wait before trying again.'],
            default => ['INQUIRY_UNAVAILABLE', 'The inquiry could not be confirmed. Retry the same request later.'],
        };
        $body = ['code' => $code, 'message' => $message];
        if (in_array($status, [400, 422], true)) {
            $body['errors'] = (object) ($errors ?: ['form' => ['Check the contact form and try again.']]);
        }

        return self::protect(response()->json($body, $status >= 500 ? 503 : $status, $headers));
    }
}
