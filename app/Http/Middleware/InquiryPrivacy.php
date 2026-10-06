<?php

namespace App\Http\Middleware;

use App\Http\Responses\InquiryResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class InquiryPrivacy
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('contact/inquiries', 'contact/inquiries/*')) {
            $history = $request->is('contact/inquiries/history', 'contact/inquiries/history/*');
            $conversation = $request->is('contact/inquiries/*');
            $allowed = $history ? ['GET'] : ($conversation ? ['GET', 'POST'] : ['POST']);
            if (! in_array($request->getRealMethod(), $allowed, true)) {
                return InquiryResponse::error(405, headers: ['Allow' => implode(', ', $allowed)]);
            }
            if ($request->query->count() || $request->server->get('QUERY_STRING', '') !== '') {
                return InquiryResponse::error(422);
            }
            if ($request->headers->has('Range') || $request->headers->has('If-Range')) {
                return InquiryResponse::error(422);
            }
            if ($request->getRealMethod() === 'GET') {
                $input = $request->getContent(true);
                if (! is_resource($input) || stream_get_contents($input, 1) !== '' || $request->headers->has('X-HTTP-Method-Override')) {
                    return InquiryResponse::error(422);
                }
            } else {
                if (! in_array(strtolower($request->header('Content-Encoding', 'identity')), ['', 'identity'], true)
                    || preg_match('~\Aapplication/json(?:\s*;\s*charset=(?:utf-8|"utf-8"))?\z~iD', $request->header('Content-Type', '')) !== 1) {
                    return InquiryResponse::error(415);
                }
                $length = $request->header('Content-Length');
                if ($length !== null && (! ctype_digit($length) || (float) $length > 16384)) {
                    return InquiryResponse::error(413);
                }
                $input = $request->getContent(true);
                $body = is_resource($input) ? stream_get_contents($input, 16385) : false;
                if ($body === false) {
                    return InquiryResponse::error(503);
                }
                if (strlen($body) > 16384) {
                    return InquiryResponse::error(413);
                }
                $request->attributes->set('_inquiry_body', $body);
                $envelope = json_decode($body, false, 3);
                if ($envelope instanceof \stdClass && property_exists($envelope, '_method')) {
                    return InquiryResponse::error(422);
                }
                if ($request->headers->has('X-HTTP-Method-Override')) {
                    return InquiryResponse::error(405);
                }
                if ($request->getRealMethod() !== $request->method()) {
                    return InquiryResponse::error(405);
                }
            }
            $origin = $request->header('Origin');
            if (($origin !== null && $origin !== $request->getSchemeAndHttpHost()) || strtolower($request->header('Sec-Fetch-Site', '')) === 'cross-site') {
                return InquiryResponse::error(403);
            }
        }
        $response = $next($request);

        return InquiryResponse::matches($request) ? InquiryResponse::protect($response) : $response;
    }
}
