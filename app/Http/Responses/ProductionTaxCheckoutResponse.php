<?php

namespace App\Http\Responses;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ProductionTaxCheckoutResponse
{
    public static function matches(Request $request): bool
    {
        return $request->is('production/tax-checkout', 'production/tax-checkout/*');
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

    public static function error(int $status): Response
    {
        return self::protect(response()->json(['code' => 'PRODUCTION_TAX_CHECKOUT_UNAVAILABLE',
            'message' => 'Checkout could not be confirmed. Retry the same request or check the saved order.'],
            in_array($status, [400, 403, 404, 405, 409, 413, 415, 419, 422, 429], true) ? $status : 503));
    }
}
