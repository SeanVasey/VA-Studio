<?php

namespace App\Domain\Memberships\Billing;

use Closure;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Stripe\Stripe;
use Stripe\StripeClient;
use Throwable;

/**
 * The write side of Billing's SDK use (BL-69), apart from the retrieval-only StripeSdkBillingGateway. It calls exactly three
 * endpoints: prices retrieve, checkout sessions create and billing portal sessions create, each after proving the key's account
 * is the configured own account. Same guards as the read gateway: pinned SDK and wire version, test-mode own-account key, no SDK
 * retries, no open application transaction, and an injectable transport confined to test mode in the testing environment. A write
 * parameter outside BillingCheckoutParameters' allowlist, or a key not shaped like ours, is refused before any request.
 */
final class StripeSdkBillingWriteGateway implements BillingProviderWriteGateway
{
    public function __construct(private readonly ?ClientInterface $fixtureTransport = null) {}

    public function provenance(): string
    {
        $configuration = (new BillingPolicy)->current();
        BillingException::require($this->fixtureTransport === null || ($configuration['mode'] === 'test' && app()->environment('testing')), 'provider_transport');

        return $configuration['provenance'];
    }

    public function retrievePrice(string $priceRef): array
    {
        BillingException::require(BillingValues::is('price', $priceRef), 'invalid_value');

        return $this->withClient(function (StripeClient $client) use ($priceRef): array {
            $price = $client->prices->retrieve($priceRef, [])->toArray();
            BillingException::require(($price['object'] ?? null) === 'price' && ($price['id'] ?? null) === $priceRef, 'provider_inconsistent');
            $recurring = is_array($price['recurring'] ?? null) ? $price['recurring'] : null;

            return ['id' => $priceRef, 'livemode' => $price['livemode'] ?? null, 'active' => $price['active'] ?? null,
                'currency' => $price['currency'] ?? null, 'unit_amount' => $price['unit_amount'] ?? null, 'type' => $price['type'] ?? null,
                'billing_scheme' => $price['billing_scheme'] ?? null, 'tiers_mode' => $price['tiers_mode'] ?? null,
                'custom_unit_amount' => $price['custom_unit_amount'] ?? null, 'transform_quantity' => $price['transform_quantity'] ?? null,
                'recurring' => $recurring === null ? null : ['interval' => $recurring['interval'] ?? null, 'interval_count' => $recurring['interval_count'] ?? null,
                    'usage_type' => $recurring['usage_type'] ?? null]];
        });
    }

    public function createSubscriptionCheckoutSession(#[SensitiveParameter] array $params, #[SensitiveParameter] string $idempotencyKey): array
    {
        BillingCheckoutParameters::assertSubscription($params);
        BillingCheckoutParameters::assertKey($idempotencyKey, BillingCheckoutParameters::CHECKOUT_KEY);

        return $this->withClient(function (StripeClient $client) use ($params, $idempotencyKey): array {
            $session = $client->checkout->sessions->create($params, ['idempotency_key' => $idempotencyKey])->toArray();
            BillingException::require(($session['object'] ?? null) === 'checkout.session' && is_string($session['id'] ?? null)
                && preg_match('/\Acs_test_[A-Za-z0-9]{1,120}\z/D', $session['id']) === 1
                && ($session['account'] ?? null) === null && ($session['context'] ?? null) === null, 'provider_inconsistent');
            $metadata = is_array($session['metadata'] ?? null) ? $session['metadata'] : [];

            return ['id' => $session['id'], 'livemode' => $session['livemode'] ?? null, 'mode' => $session['mode'] ?? null,
                'status' => $session['status'] ?? null, 'url' => $session['url'] ?? null, 'client_reference_id' => $session['client_reference_id'] ?? null,
                'customer' => BillingValues::ref($session['customer'] ?? null), 'currency' => $session['currency'] ?? null,
                'amount_total' => $session['amount_total'] ?? null, 'expires_at' => $session['expires_at'] ?? null,
                'metadata' => array_filter($metadata, 'is_string')];
        });
    }

    public function createPortalSession(#[SensitiveParameter] array $params, #[SensitiveParameter] string $idempotencyKey): array
    {
        BillingCheckoutParameters::assertPortal($params);
        BillingCheckoutParameters::assertKey($idempotencyKey, BillingCheckoutParameters::PORTAL_KEY);

        return $this->withClient(function (StripeClient $client) use ($params, $idempotencyKey): array {
            $session = $client->billingPortal->sessions->create($params, ['idempotency_key' => $idempotencyKey])->toArray();
            BillingException::require(($session['object'] ?? null) === 'billing_portal.session' && is_string($session['id'] ?? null)
                && preg_match('/\Abps_[A-Za-z0-9]{1,120}\z/D', $session['id']) === 1, 'provider_inconsistent');

            return ['id' => $session['id'], 'livemode' => $session['livemode'] ?? null, 'customer' => BillingValues::ref($session['customer'] ?? null),
                'url' => $session['url'] ?? null, 'return_url' => $session['return_url'] ?? null,
                'configuration' => BillingValues::ref($session['configuration'] ?? null)];
        });
    }

    /**
     * Configuration refusals keep their reason. Anything raised by the SDK or transport becomes provider_unavailable without
     * chaining: SDK exceptions can carry URLs, headers, bodies and keys. The key's account must be the configured own account
     * before any write is sent.
     */
    private function withClient(Closure $operation): array
    {
        BillingProviderPin::assertInstalled();
        $policy = new BillingPolicy;
        $configuration = $policy->providerIo();
        BillingException::require($this->fixtureTransport === null || ($configuration['mode'] === 'test' && app()->environment('testing')), 'provider_transport');
        BillingException::require(Stripe::$accountId === null && Stripe::getVerifySslCerts() === true && Stripe::$logger === null, 'provider_transport');
        // Provider I/O never runs while any application transaction is held.
        foreach (DB::getConnections() as $connection) {
            BillingException::require($connection->transactionLevel() === 0, 'transaction_open');
        }
        try {
            $client = new StripeClient(BillingProviderPin::clientOptions($policy->secret()));
            $transport = $this->fixtureTransport ?? (new CurlClient)->setConnectTimeout(3)->setTimeout(10);
            $previous = ApiRequestor::httpClient();
            ApiRequestor::setHttpClient($transport);
            try {
                $account = BillingProjection::project('account', $client->accounts->retrieve()->toArray());
                BillingException::require($account['id'] === $configuration['account_ref'], 'account_mismatch');
                $result = $operation($client);
            } finally {
                ApiRequestor::setHttpClient($previous);
            }
        } catch (BillingException $error) {
            throw $error;
        } catch (Throwable) {
            throw new BillingException('provider_unavailable');
        }
        $policy->proveConfiguration($configuration);

        return $result;
    }
}
