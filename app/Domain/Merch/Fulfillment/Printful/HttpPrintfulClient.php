<?php

namespace App\Domain\Merch\Fulfillment\Printful;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;

/**
 * Printful API v2 over Laravel's HTTP client.
 *
 * Endpoint shapes are taken from https://developers.printful.com/docs/v2-beta/ (read 2026-11-10): POST /v2/orders (creates a
 * draft; carries `external_id`), POST /v2/orders/{id}/confirmation, GET /v2/orders/@{external_id} and POST /v2/shipping-rates.
 * They were not exercised against the live API (no credentials exist in this repository or its environments), so each call
 * stays behind PrintfulClient and every response is read defensively.
 *
 * There is deliberately no retry here: an order create that is repeated blindly could duplicate an order. The caller
 * reconciles by external id instead (SubmitMerchFulfillment). Error bodies are never logged or kept: they can echo the
 * recipient's address.
 */
final class HttpPrintfulClient implements PrintfulClient
{
    public const BASE_URL = 'https://api.printful.com';

    private const CONNECT_TIMEOUT = 5;

    private const TIMEOUT = 20;

    public function __construct(
        #[SensitiveParameter] private readonly ?string $apiKey,
        private readonly ?string $storeId = null,
        private readonly string $baseUrl = self::BASE_URL,
    ) {}

    public function createDraftOrder(string $externalId, array $recipient, array $items, string $shipping): PrintfulOrder
    {
        $this->requireIdentifier($externalId);
        $response = $this->send('post', '/v2/orders', [
            'external_id' => $externalId, 'shipping' => $shipping, 'recipient' => $recipient, 'order_items' => $items,
        ]);

        return $this->order($response);
    }

    public function confirmOrder(string $printfulOrderId): PrintfulOrder
    {
        $this->requireNumeric($printfulOrderId);

        return $this->order($this->send('post', '/v2/orders/'.$printfulOrderId.'/confirmation', null));
    }

    public function findOrderByExternalId(string $externalId): ?PrintfulOrder
    {
        $this->requireIdentifier($externalId);
        try {
            return $this->order($this->send('get', '/v2/orders/@'.$externalId, null));
        } catch (PrintfulException $exception) {
            if ($exception->status === 404) {
                return null;
            }
            throw $exception;
        }
    }

    public function shippingRates(array $recipient, array $items, string $currency): array
    {
        $response = $this->send('post', '/v2/shipping-rates', ['recipient' => $recipient, 'order_items' => $items, 'currency' => $currency]);
        $rows = $response->json('data');
        if (! is_array($rows)) {
            throw new PrintfulException('PRINTFUL_RESPONSE_INVALID');
        }
        $rates = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['shipping'] ?? null) || ! is_string($row['rate'] ?? null)
                || ! is_string($row['currency'] ?? null)
                || preg_match('/\A\d{1,9}(\.\d{1,4})?\z/D', $row['rate']) !== 1) {
                throw new PrintfulException('PRINTFUL_RESPONSE_INVALID');
            }
            $rates[] = new PrintfulShippingRate($row['shipping'], is_string($row['shipping_method_name'] ?? null) ? $row['shipping_method_name'] : $row['shipping'],
                $row['rate'], $row['currency']);
        }

        return $rates;
    }

    /** @param  array<string, mixed>|null  $body */
    private function send(string $method, string $path, ?array $body): Response
    {
        if (! is_string($this->apiKey) || $this->apiKey === '') {
            // Refuse before a request is built: an unconfigured environment (every test, every checkout) never reaches the network.
            throw new PrintfulException('PRINTFUL_NOT_CONFIGURED');
        }
        $request = Http::baseUrl($this->baseUrl)->withToken($this->apiKey)->acceptJson()
            ->connectTimeout(self::CONNECT_TIMEOUT)->timeout(self::TIMEOUT)->withoutRedirecting();
        if (is_string($this->storeId) && $this->storeId !== '') {
            $request = $request->withHeaders(['X-PF-Store-Id' => $this->storeId]);
        }
        try {
            $response = $this->dispatch($request, $method, $path, $body);
        } catch (ConnectionException|RequestException) {
            // The request may or may not have arrived.
            throw new PrintfulException('PRINTFUL_UNAVAILABLE', true);
        }
        if ($response->successful()) {
            return $response;
        }
        $status = $response->status();
        if ($status === 429 || $status >= 500) {
            throw new PrintfulException('PRINTFUL_UNAVAILABLE', true, $status);
        }
        throw new PrintfulException($status === 401 || $status === 403 ? 'PRINTFUL_UNAUTHORIZED' : 'PRINTFUL_REJECTED', false, $status);
    }

    /** @param  array<string, mixed>|null  $body */
    private function dispatch(PendingRequest $request, string $method, string $path, ?array $body): Response
    {
        return $method === 'get' ? $request->get($path) : $request->post($path, $body ?? []);
    }

    private function order(Response $response): PrintfulOrder
    {
        $data = $response->json('data');
        if (! is_array($data) || ! (is_int($data['id'] ?? null) || is_string($data['id'] ?? null))
            || ! is_string($data['status'] ?? null)) {
            throw new PrintfulException('PRINTFUL_RESPONSE_INVALID', true);
        }

        return new PrintfulOrder((string) $data['id'], is_string($data['external_id'] ?? null) ? $data['external_id'] : '', $data['status']);
    }

    private function requireIdentifier(string $value): void
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/D', $value) !== 1) {
            throw new PrintfulException('PRINTFUL_REQUEST_INVALID');
        }
    }

    private function requireNumeric(string $value): void
    {
        if (preg_match('/\A[0-9]{1,20}\z/D', $value) !== 1) {
            throw new PrintfulException('PRINTFUL_REQUEST_INVALID');
        }
    }
}
