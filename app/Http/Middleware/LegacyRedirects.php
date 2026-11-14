<?php

namespace App\Http\Middleware;

use App\Domain\Migration\LegacyRedirects\LegacyRedirectResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global and default-off (FP-100). Unless `legacy-redirects.enabled` is the literal true it returns immediately, reading
 * no file and matching no route. It runs before the web group, so a redirect starts no session and sets no cookie.
 */
final class LegacyRedirects
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('legacy-redirects.enabled') === true) {
            $redirect = app(LegacyRedirectResolver::class)->respond($request);
            if ($redirect !== null) {
                return $redirect;
            }
        }

        return $next($request);
    }
}
