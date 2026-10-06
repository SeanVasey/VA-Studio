<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class TestExceptionResolutionResponse
{
    public static function matches(Request $request): bool
    {
        return $request->is('orders/*/exception-resolution', 'orders/*/exception-resolution/*');
    }

    public static function protect(Response $response): Response
    {
        $response->headers->add(['Cache-Control' => 'private, no-store', 'Vary' => 'Cookie',
            'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow']);
        foreach (['ETag', 'Last-Modified', 'Accept-Ranges'] as $header) {
            $response->headers->remove($header);
        }

        return $response;
    }

    public static function error(int $status, array $headers = []): JsonResponse
    {
        return self::protect(response()->json(['code' => 'TEST_RESOLUTION_UNAVAILABLE',
            'message' => 'This test order resolution could not be loaded.'], $status >= 500 ? 503 : $status, $headers));
    }
}
