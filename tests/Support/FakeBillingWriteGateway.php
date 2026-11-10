<?php

namespace Tests\Support;

use App\Domain\Memberships\Billing\BillingException;
use App\Domain\Memberships\Billing\BillingProviderWriteGateway;
use Closure;

/**
 * SYNTHETIC in-memory stand-in for the write gateway; no network. It mimics Stripe's idempotency contract: the same key with the same
 * parameters returns the original session, the same key with different parameters is refused, and a new key creates a new session.
 * Every response is synthetic; the amounts and ids are placeholders, never plan or price facts.
 */
final class FakeBillingWriteGateway implements BillingProviderWriteGateway
{
    /** @var list<array{op: string, params: array, key: ?string}> */
    public array $calls = [];

    /** @var array<string, array{hash: string, response: array}> */
    private array $remembered = [];

    public int $created = 0;

    /** @var array<string, mixed> overrides merged into the synthetic price */
    public array $price = [];

    /** @var array<string, mixed> overrides merged into every new checkout session */
    public array $session = [];

    /** @var array<string, mixed> overrides merged into every new portal session */
    public array $portal = [];

    public ?Closure $afterCreate = null;

    public function provenance(): string
    {
        return 'synthetic_rehearsal';
    }

    public function retrievePrice(string $priceRef): array
    {
        $this->calls[] = ['op' => 'price', 'params' => ['id' => $priceRef], 'key' => null];

        return [...['id' => $priceRef, 'livemode' => false, 'active' => true, 'currency' => 'usd', 'unit_amount' => 1700, 'type' => 'recurring',
            'billing_scheme' => 'per_unit', 'tiers_mode' => null, 'custom_unit_amount' => null, 'transform_quantity' => null,
            'recurring' => ['interval' => 'month', 'interval_count' => 1, 'usage_type' => 'licensed']], ...$this->price];
    }

    public function createSubscriptionCheckoutSession(array $params, string $idempotencyKey): array
    {
        return $this->once('checkout', $params, $idempotencyKey, function () use ($params): array {
            return [...['id' => 'cs_test_SYNTHETIC'.$this->created, 'livemode' => false, 'mode' => 'subscription', 'status' => 'open',
                'url' => 'https://checkout.stripe.com/c/pay/cs_test_SYNTHETIC'.$this->created, 'client_reference_id' => $params['client_reference_id'],
                'customer' => null, 'currency' => 'usd', 'amount_total' => 1700, 'expires_at' => time() + 86000, 'metadata' => $params['metadata']], ...$this->session];
        });
    }

    public function createPortalSession(array $params, string $idempotencyKey): array
    {
        return $this->once('portal', $params, $idempotencyKey, function () use ($params): array {
            return [...['id' => 'bps_SYNTHETIC'.$this->created, 'livemode' => false, 'customer' => $params['customer'],
                'url' => 'https://billing.stripe.com/p/session/test_SYNTHETIC'.$this->created, 'return_url' => $params['return_url'],
                'configuration' => $params['configuration'] ?? 'bpc_SYNTHETICDEFAULT'], ...$this->portal];
        });
    }

    /** The recorded calls of one kind. */
    public function of(string $op): array
    {
        return array_values(array_filter($this->calls, fn (array $call): bool => $call['op'] === $op));
    }

    private function once(string $op, array $params, string $key, Closure $make): array
    {
        $this->calls[] = ['op' => $op, 'params' => $params, 'key' => $key];
        $hash = hash('sha256', json_encode($params, JSON_THROW_ON_ERROR));
        if (isset($this->remembered[$key])) {
            // Stripe: a reused key with different parameters is an idempotency error.
            if ($this->remembered[$key]['hash'] !== $hash) {
                throw new BillingException('provider_unavailable');
            }

            return $this->remembered[$key]['response'];
        }
        $this->created++;
        $response = $make();
        $this->remembered[$key] = ['hash' => $hash, 'response' => $response];
        if ($this->afterCreate !== null) {
            ($this->afterCreate)();
        }

        return $response;
    }
}
