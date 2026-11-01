<?php

namespace App\Http\Middleware;

use App\Support\Http\TrustedProxyList;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Replaces the framework's TrustProxies in the global stack (bootstrap/app.php). The framework middleware trusts every proxy
 * when no list is configured and the host ends in `.on-forge.com` or `.on-vapor.com`, or on Laravel Cloud; this one never does.
 * It trusts exactly the reviewed list in `trusted-proxies.proxies` (none by default: the Forge/nginx kit serves PHP-FPM over
 * FastCGI on the same host, so REMOTE_ADDR is already the client) and honours only X-Forwarded-For, -Proto and -Port. A forwarded
 * host or prefix is never trusted, so a client cannot choose the origin that URLs, Origin checks and signed links are built on.
 */
final class ExplicitTrustProxies
{
    public const HEADERS = Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO;

    public function handle(Request $request, Closure $next): Response
    {
        // A malformed list trusts nothing; the production validator refuses it before a deploy.
        Request::setTrustedProxies(TrustedProxyList::parse(config('trusted-proxies.proxies')) ?? [], self::HEADERS);

        return $next($request);
    }
}
