<?php

namespace Tests\Support;

use LogicException;
use Stripe\HttpClient\ClientInterface;

/**
 * Loopback transport for the real StripeSdkBillingWriteGateway: serves canned bodies by "METHOD /path" and records method, URL,
 * headers and parameters. No network. Any request without a route is a test failure.
 */
final class BillingWriteHttpFixture implements ClientInterface
{
    public array $calls = [];

    /** @param array<string, array|\Throwable> $routes "GET /v1/account" => response body */
    public function __construct(private array $routes) {}

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->calls[] = ['method' => strtoupper($method), 'url' => $absUrl, 'headers' => $headers, 'params' => $params, 'max_network_retries' => $maxNetworkRetries];
        $route = strtoupper($method).' '.(parse_url($absUrl, PHP_URL_PATH) ?? '');
        if (! array_key_exists($route, $this->routes)) {
            throw new LogicException('Unrouted billing write fixture request: '.$route);
        }
        $response = $this->routes[$route];
        if ($response instanceof \Throwable) {
            throw $response;
        }

        return [json_encode($response, JSON_THROW_ON_ERROR), 200, []];
    }

    /** @return list<string> "METHOD /path" in call order */
    public function routes(): array
    {
        return array_map(fn (array $call): string => $call['method'].' '.parse_url($call['url'], PHP_URL_PATH), $this->calls);
    }

    public function header(int $call, string $name): ?string
    {
        foreach ($this->calls[$call]['headers'] as $header) {
            if (str_starts_with(strtolower($header), strtolower($name).':')) {
                return trim(substr($header, strlen($name) + 1));
            }
        }

        return null;
    }
}
