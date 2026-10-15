<?php

namespace App\Domain\Commerce\ProductionFinancialRetrieval;

use App\Domain\Commerce\ProductionFinancialInspection\FinancialObservation;
use ReflectionProperty;
use SensitiveParameter;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

/** Exact six test GETs; response admission ends before the SDK can evaluate notice callbacks. */
final class GuardedSdkTransport implements ClientInterface
{
    private int $position = 0;

    private int $bytes = 0;

    private ?string $charge = null;

    public function __construct(private readonly ClientInterface $delegate, private readonly RetrievalPolicy $policy,
        private readonly ReadOwnership $ownership, private readonly string $payment) {}

    public function request($method, $absUrl, #[SensitiveParameter] $headers, #[SensitiveParameter] $params,
        $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->prove();
        FinancialRetrievalException::require($this->position < 6 && $method === 'get' && $hasFile === false
            && $apiMode === 'v1' && $maxNetworkRetries === 0 && is_array($headers) && is_array($params));
        $path = match ($this->position) {
            0 => 'account', 1 => 'payment_intents/'.$this->payment,
            2, 5 => 'charges/'.$this->charge, 3 => 'refunds', 4 => 'disputes',
        };
        FinancialRetrievalException::require($absUrl === 'https://api.stripe.com/v1/'.$path
            && $params === (in_array($this->position, [3, 4], true) ? ['payment_intent' => $this->payment, 'limit' => 100] : []));
        $authorization = $version = false;
        foreach ($headers as $header) {
            FinancialRetrievalException::require(is_string($header) && strlen($header) <= 1024);
            FinancialRetrievalException::require(preg_match('/\A(?:Stripe-Account|Stripe-Context|Idempotency-Key):/i', $header) !== 1);
            $authorization = $authorization || $this->policy->admitsAuthorization($header);
            $version = $version || $header === 'Stripe-Version: 2026-08-26.dahlia';
        }
        FinancialRetrievalException::require($authorization && $version);
        $index = $this->position++;
        $response = $this->delegate->request($method, $absUrl, $headers, $params, $hasFile, $apiMode, $maxNetworkRetries);
        $this->prove();
        $response = $this->admitResponse($response);
        if ($index === 1) {
            $body = json_decode($response[0], true, 12, JSON_THROW_ON_ERROR);
            FinancialRetrievalException::require(is_array($body) && ($body['object'] ?? null) === 'payment_intent'
                && ($body['id'] ?? null) === $this->payment && FinancialObservation::id($body['latest_charge'] ?? null, 'ch'));
            $this->charge = $body['latest_charge'];
        }

        return $response;
    }

    public function proveComplete(): void
    {
        $this->ownership->proveDispatchAllowed();
        $this->policy->requireOutsideTransactions();
        FinancialRetrievalException::require($this->position === 6);
    }

    private function prove(): void
    {
        $this->ownership->proveDispatchAllowed();
        $this->policy->requireOutsideTransactions();
        FinancialRetrievalException::require((new ReflectionProperty(ApiRequestor::class, '_httpClient'))->getRawValue(null) === $this);
    }

    private function admitResponse(#[SensitiveParameter] mixed $response): array
    {
        FinancialRetrievalException::require(is_array($response) && array_is_list($response) && count($response) === 3
            && is_string($response[0]) && is_int($response[1]) && $response[1] >= 200 && $response[1] < 300
            && is_array($response[2]) && count($response[2]) <= 100);
        $this->bytes += strlen($response[0]);
        FinancialRetrievalException::require($this->bytes <= 1048576);
        foreach ($response[2] as $name => $value) {
            FinancialRetrievalException::require(is_string($name) && strlen($name) <= 128
                && is_string($value) && strlen($value) <= 4096 && strtolower($name) !== 'stripe-notice');
        }
        // Bound the shape before the SDK's own decode/conversion work.
        $body = json_decode($response[0], true, 12, JSON_THROW_ON_ERROR);
        $nodes = 0;
        self::nodes($body, $nodes);

        return $response;
    }

    private static function nodes(#[SensitiveParameter] mixed $value, int &$count): void
    {
        FinancialRetrievalException::require(++$count <= 4096);
        if (is_array($value)) {
            foreach ($value as $child) {
                self::nodes($child, $count);
            }
        } else {
            FinancialRetrievalException::require($value === null || is_string($value) || is_bool($value) || is_int($value));
        }
    }

    public function __serialize(): never
    {
        throw new \LogicException('Internal SDK request custody cannot be serialized.');
    }

    public function __unserialize(array $data): never
    {
        throw new \LogicException('Internal SDK request custody cannot be reconstructed.');
    }

    public function __debugInfo(): array
    {
        return ['purpose' => 'six_test_gets_only'];
    }
}
