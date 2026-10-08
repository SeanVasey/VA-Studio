<?php

namespace Tests\Support;

use LogicException;
use Stripe\HttpClient\ClientInterface;

/**
 * Loopback transport for the real StripeSdkBillingGateway. It serves BillingStripeFixtures objects
 * (already built through the SDK classes) by exact GET path and records method, URL and headers.
 * No network. Any non-GET request is a test failure: the Billing gateway has no write calls.
 */
final class BillingHttpFixture implements ClientInterface
{
    public array $calls = [];

    /** @param array<string, array|\Throwable> $routes path (with query) => response body */
    public function __construct(private array $routes) {}

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->calls[] = ['method' => $method, 'url' => $absUrl, 'headers' => $headers, 'params' => $params, 'max_network_retries' => $maxNetworkRetries];
        if (strtolower($method) !== 'get') {
            throw new LogicException('Billing gateway attempted a provider write: '.$method.' '.$absUrl);
        }
        $parts = parse_url($absUrl);
        // The SDK hands GET parameters to the transport separately; CurlClient appends them as the query.
        $query = is_array($params) && $params !== [] ? urldecode(http_build_query($params)) : ($parts['query'] ?? null);
        $path = ($parts['path'] ?? '').($query !== null ? '?'.$query : '');
        if (! array_key_exists($path, $this->routes)) {
            throw new LogicException('Unrouted billing fixture request: '.$path);
        }
        $response = $this->routes[$path];
        if ($response instanceof \Throwable) {
            throw $response;
        }

        return [json_encode($response, JSON_THROW_ON_ERROR), 200, []];
    }
}
