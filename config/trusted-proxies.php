<?php

/*
 * Reverse proxies whose X-Forwarded-For, -Proto and -Port headers are honoured (App\Http\Middleware\ExplicitTrustProxies).
 * Comma-separated IPv4/IPv6 addresses or CIDR ranges (no wider than /8 or /16); App\Support\Http\TrustedProxyList refuses a
 * wildcard, `REMOTE_ADDR`, a hostname or any malformed entry. Blank trusts no proxy, which is right for the Forge/nginx kit
 * (nginx hands requests to PHP-FPM over FastCGI on the same host). A CDN in front needs a reviewed address list here and the
 * checks in docs/ops/monitoring-and-host-baseline.md ("CDN and proxy"). The forwarded host is never trusted.
 */
return [
    'proxies' => env('TRUSTED_PROXIES', ''),
];
