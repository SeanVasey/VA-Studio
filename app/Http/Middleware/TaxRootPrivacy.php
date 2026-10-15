<?php

namespace App\Http\Middleware;

use App\Http\Responses\ProductionTaxCheckoutResponse;
use App\Http\Tax\PaidTaxRequestContext;
use App\Http\Tax\TaxHttpMount;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** First root seam: stale route tables never override literal runtime admission. */
final class TaxRootPrivacy
{
    public static function matches(Request $request): bool
    {
        return PaidTaxPrivacy::matches($request) || ProductionTaxCheckoutResponse::matches($request);
    }

    public function handle(Request $request, Closure $next): Response
    {
        $consumer = PaidTaxPrivacy::matches($request);
        if (! $consumer && ! ProductionTaxCheckoutResponse::matches($request)) {
            return $next($request);
        }
        if (! TaxHttpMount::admitted($consumer ? 'paid-tax-grants.http_mounted' : 'production-tax-checkout.http_mounted')) {
            return PaidTaxPrivacy::error(404);
        }
        try {
            if ($consumer) {
                if ($request->is('paid-tax-grants/origins/*/authorizations/*/redeem')) {
                    PaidTaxRequestContext::capture($request);
                }

                return (new PaidTaxPrivacy)->handle($request, $next);
            }

            return (new ProductionTaxCheckoutPrivacy)->handle($request, $next);
        } catch (Throwable $error) {
            return PaidTaxPrivacy::refusal($error);
        }
    }
}
