<?php

namespace App\Domain\Commerce\ProductionCheckout;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;
use Stripe\ApiRequestor;
use Stripe\BaseStripeClient;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Stripe\Stripe;
use Stripe\StripeClient;
use Throwable;

/** Production-capable own-account SDK code. Injectable HTTP fixtures are confined to rehearsal. */
final class OwnAccountStripeGateway implements ProviderGateway
{
    public function __construct(private readonly ?ClientInterface $fixtureTransport = null) {}

    public function provenance(ExecutionContextV1 $context): string
    {
        CheckoutException::require($this->fixtureTransport === null || ($context->fundsMode === 'test' && app()->environment('testing')), 'provider', 503);

        return $context->fundsMode === 'test' ? 'synthetic_rehearsal' : 'own_account_sdk';
    }

    public function account(ExecutionContextV1 $context): array
    {
        return $this->withClient($context, fn (StripeClient $client): array => $this->ownAccount($client, $context));
    }

    public function create(ExecutionContextV1 $context, #[SensitiveParameter] array $params, #[SensitiveParameter] string $key): array
    {
        CheckoutException::require(preg_match('/\Ava-production-checkout-v1-[a-f0-9-]{36}\z/D', $key) === 1
            && ($params['mode'] ?? null) === 'payment'
            && array_intersect(['api_key', 'stripe_account', 'stripe_context', 'customer_account', 'managed_payments'], array_keys($params)) === []
            && is_array($params['payment_intent_data'] ?? null)
            && array_intersect(['application_fee_amount', 'on_behalf_of', 'transfer_data', 'transfer_group'], array_keys($params['payment_intent_data'])) === [], 'provider', 503);

        return $this->withClient($context, function (StripeClient $client) use ($context, $params, $key): array {
            $this->ownAccount($client, $context);
            // The frozen expiry must still be valid when the first POST reaches this boundary.
            // Never extend a retained request or its idempotency window to obtain a new session.
            $now = CarbonImmutable::now('UTC')->timestamp;
            CheckoutException::require(is_int($params['expires_at'] ?? null)
                && $params['expires_at'] >= $now + 1800 && $params['expires_at'] <= $now + 86400, 'provider', 503);

            return $this->session($client, $context, $client->checkout->sessions->create($params, ['idempotency_key' => $key])->toArray());
        });
    }

    public function retrieve(ExecutionContextV1 $context, string $sessionId): array
    {
        CheckoutException::require(self::sessionId($sessionId, $context->fundsMode), 'provider', 503);

        return $this->withClient($context, function (StripeClient $client) use ($context, $sessionId): array {
            $this->ownAccount($client, $context);
            $session = $client->checkout->sessions->retrieve($sessionId, ['expand' => ['line_items.data.price.product']])->toArray();
            CheckoutException::require(($session['id'] ?? null) === $sessionId, 'provider', 503);

            return $this->session($client, $context, $session);
        });
    }

    public function paymentIntent(ExecutionContextV1 $context, string $paymentId): array
    {
        CheckoutException::require(preg_match('/\Api_[A-Za-z0-9]{1,120}\z/D', $paymentId) === 1, 'provider', 503);

        return $this->withClient($context, function (StripeClient $client) use ($context, $paymentId): array {
            $this->ownAccount($client, $context);
            $payment = $client->paymentIntents->retrieve($paymentId, [])->toArray();
            CheckoutException::require(($payment['object'] ?? null) === 'payment_intent' && ($payment['id'] ?? null) === $paymentId
                && ($payment['livemode'] ?? null) === ($context->fundsMode === 'live'), 'provider', 503);
            foreach (['account', 'context', 'application', 'application_fee_amount', 'on_behalf_of', 'transfer_data', 'transfer_group'] as $field) {
                CheckoutException::require(($payment[$field] ?? null) === null, 'provider', 503);
            }
            unset($payment['client_secret']);

            return $payment;
        });
    }

    private function withClient(ExecutionContextV1 $context, Closure $operation): array
    {
        try {
            $this->provenance($context);
            $secret = config('production_checkout.secret_key');
            CheckoutException::require(config('production_checkout.provider_io_enabled') === true
                && config('production_checkout.account_id') === $context->accountId
                && config('production_checkout.funds_mode') === $context->fundsMode
                && is_string($secret) && preg_match('/\Ask_'.$context->fundsMode.'_[A-Za-z0-9]{1,240}\z/D', $secret) === 1
                && Stripe::$accountId === null && Stripe::$verifySslCerts === true && Stripe::$logger === null, 'provider', 503);
            foreach (DB::getConnections() as $connection) {
                CheckoutException::require($connection->transactionLevel() === 0 && ! $connection->getPdo()->inTransaction(), 'provider', 503);
            }
            $client = new StripeClient(['api_key' => $secret, 'api_base' => BaseStripeClient::DEFAULT_API_BASE,
                'stripe_version' => ExecutionContextV1::API_VERSION, 'stripe_account' => null, 'stripe_context' => null, 'max_network_retries' => 0]);
            $transport = $this->fixtureTransport ?? (new CurlClient)->setConnectTimeout(3)->setTimeout(10);
            $previous = ApiRequestor::httpClient();
            ApiRequestor::setHttpClient($transport);
            try {
                return $operation($client);
            } finally {
                ApiRequestor::setHttpClient($previous);
            }
        } catch (Throwable) {
            // SDK exceptions retain headers, URLs and bodies. Do not chain or log them.
            throw new CheckoutException('provider', 503);
        }
    }

    private function ownAccount(StripeClient $client, ExecutionContextV1 $context): array
    {
        $account = $client->accounts->retrieve()->toArray();
        CheckoutException::require(($account['object'] ?? null) === 'account' && ($account['id'] ?? null) === $context->accountId, 'provider', 503);
        CheckoutException::require($context->fundsMode !== 'live' || (($account['charges_enabled'] ?? null) === true
            && ($account['capabilities']['card_payments'] ?? null) === 'active'), 'provider', 503);

        return ['object' => 'account', 'id' => $account['id'], 'evidence_origin' => $this->provenance($context), 'funds_mode' => $context->fundsMode];
    }

    private function session(StripeClient $client, ExecutionContextV1 $context, #[SensitiveParameter] array $session): array
    {
        CheckoutException::require(($session['object'] ?? null) === 'checkout.session' && self::sessionId($session['id'] ?? null, $context->fundsMode)
            && ($session['livemode'] ?? null) === ($context->fundsMode === 'live') && ($session['account'] ?? null) === null
            && ($session['context'] ?? null) === null && is_array($session['line_items'] ?? null), 'provider', 503);
        if (($session['line_items']['has_more'] ?? null) === true) {
            $session['line_items'] = $client->checkout->sessions->allLineItems($session['id'], ['limit' => 100, 'expand' => ['data.price.product']])->toArray();
        }
        $items = $session['line_items'];
        CheckoutException::require(($items['object'] ?? null) === 'list' && ($items['has_more'] ?? null) === false
            && is_array($items['data'] ?? null) && array_is_list($items['data']) && count($items['data']) <= 10, 'provider', 503);

        return $session;
    }

    public static function sessionId(mixed $id, string $mode): bool
    {
        return is_string($id) && in_array($mode, ['test', 'live'], true) && preg_match('/\Acs_'.$mode.'_[A-Za-z0-9]{1,120}\z/D', $id) === 1;
    }
}
