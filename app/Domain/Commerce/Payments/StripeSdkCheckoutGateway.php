<?php

namespace App\Domain\Commerce\Payments;

use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SensitiveParameter;
use Stripe\ApiRequestor;
use Stripe\BaseStripeClient;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Stripe\Stripe;
use Stripe\StripeClient;
use Throwable;

final class StripeSdkCheckoutGateway implements StripeCheckoutGateway, StripePaymentGateway
{
    // Pin the retained request contract independently of mutable global SDK settings.
    public const API_VERSION = '2026-08-26.dahlia';

    public const CONNECT_TIMEOUT_SECONDS = 3;

    public const REQUEST_TIMEOUT_SECONDS = 10;

    public function __construct(private readonly ?ClientInterface $httpClient = null) {}

    public function account(): array
    {
        return $this->withClient(fn (StripeClient $client, string $account): array => $this->verifyAccount($client, $account));
    }

    public function create(#[SensitiveParameter] array $params, #[SensitiveParameter] string $idempotencyKey): array
    {
        if (! preg_match('/\A[A-Za-z0-9_:\-]{1,255}\z/', $idempotencyKey)
            || ($params['mode'] ?? null) !== 'payment'
            || array_intersect(['api_key', 'stripe_account', 'stripe_context'], array_keys($params)) !== []
            || (is_array($params['payment_intent_data'] ?? null)
                && array_intersect(['application_fee_amount', 'on_behalf_of', 'transfer_data'], array_keys($params['payment_intent_data'])) !== [])) {
            throw new RuntimeException('STRIPE_CHECKOUT_UNAVAILABLE');
        }

        return $this->withClient(function (StripeClient $client, string $account) use ($params, $idempotencyKey): array {
            $this->verifyAccount($client, $account);

            return $this->session($client, $client->checkout->sessions->create($params, [
                'idempotency_key' => $idempotencyKey,
            ])->toArray());
        });
    }

    public function retrieve(string $sessionId): array
    {
        if (! $this->isTestSessionId($sessionId)) {
            throw new RuntimeException('STRIPE_CHECKOUT_UNAVAILABLE');
        }

        return $this->withClient(function (StripeClient $client, string $account) use ($sessionId): array {
            $this->verifyAccount($client, $account);
            $session = $client->checkout->sessions->retrieve($sessionId, ['expand' => ['line_items']])->toArray();
            if (($session['id'] ?? null) !== $sessionId) {
                throw new RuntimeException('STRIPE_CHECKOUT_UNAVAILABLE');
            }

            return $this->session($client, $session);
        });
    }

    public function paymentIntent(string $paymentIntentId): array
    {
        if (preg_match('/\Api_[A-Za-z0-9]{1,240}\z/', $paymentIntentId) !== 1) {
            throw new RuntimeException('STRIPE_CHECKOUT_UNAVAILABLE');
        }

        return $this->withClient(function (StripeClient $client, string $account) use ($paymentIntentId): array {
            $this->verifyAccount($client, $account);
            $payment = $client->paymentIntents->retrieve($paymentIntentId, [])->toArray();
            if (($payment['object'] ?? null) !== 'payment_intent'
                || ($payment['id'] ?? null) !== $paymentIntentId
                || ($payment['livemode'] ?? null) !== false) {
                throw new RuntimeException('STRIPE_CHECKOUT_UNAVAILABLE');
            }
            foreach (['account', 'context', 'application', 'application_fee_amount', 'on_behalf_of', 'transfer_data', 'transfer_group'] as $field) {
                if (($payment[$field] ?? null) !== null) {
                    throw new RuntimeException('STRIPE_CHECKOUT_UNAVAILABLE');
                }
            }
            // The server reconciles provider state; it never needs a client-side secret.
            // Domain validation separately binds amount, currency, status and metadata.
            unset($payment['client_secret']);

            return $payment;
        });
    }

    private function withClient(#[SensitiveParameter] Closure $operation): array
    {
        try {
            $account = config('payments.stripe.account_id');
            $secret = config('payments.stripe.secret_key');
            if (! app()->environment('local', 'testing')
                || config('payments.stripe.mode') !== 'test'
                || ! is_string($account) || ! preg_match('/\Aacct_[A-Za-z0-9]{1,64}\z/', $account)
                || ! is_string($secret) || ! preg_match('/\Ask_test_[A-Za-z0-9]{8,200}\z/', $secret)
                || Stripe::$accountId !== null || Stripe::getVerifySslCerts() !== true) {
                throw new RuntimeException('STRIPE_CHECKOUT_UNAVAILABLE');
            }
            // Include every already-open Laravel connection, not only the default one.
            // No provider request is permitted while an application transaction is held.
            foreach (DB::getConnections() as $connection) {
                if ($connection->transactionLevel() !== 0) {
                    throw new RuntimeException('STRIPE_CHECKOUT_UNAVAILABLE');
                }
            }

            $client = new StripeClient([
                'api_key' => $secret,
                'api_base' => BaseStripeClient::DEFAULT_API_BASE,
                'stripe_version' => self::API_VERSION,
                'stripe_account' => null,
                'stripe_context' => null,
                'max_network_retries' => 0,
            ]);
            $transport = $this->httpClient ?? (new CurlClient())
                ->setConnectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->setTimeout(self::REQUEST_TIMEOUT_SECONDS);
            // stripe-php exposes transport only through ApiRequestor's static slot.
            // Scope it to this synchronous call and restore it even on malformed responses.
            $previous = ApiRequestor::httpClient();
            ApiRequestor::setHttpClient($transport);
            try {
                return $operation($client, $account);
            } finally {
                ApiRequestor::setHttpClient($previous);
            }
        } catch (Throwable) {
            // SDK exceptions can retain request bodies, secret keys, URLs and buyer details.
            // Never chain or log them. The durable caller treats this as an uncertain result.
            throw new RuntimeException('STRIPE_CHECKOUT_UNAVAILABLE');
        }
    }

    private function verifyAccount(StripeClient $client, string $expected): array
    {
        // Omitting the ID uses GET /v1/account, proving which account owns the credential.
        // GET /v1/accounts/{configured ID} would instead be a Connect lookup.
        $account = $client->accounts->retrieve()->toArray();
        if (($account['object'] ?? null) !== 'account' || ($account['id'] ?? null) !== $expected) {
            throw new RuntimeException('STRIPE_CHECKOUT_UNAVAILABLE');
        }

        return ['id' => $account['id'], 'object' => 'account'];
    }

    private function session(StripeClient $client, #[SensitiveParameter] array $session): array
    {
        if (($session['object'] ?? null) !== 'checkout.session'
            || ! $this->isTestSessionId($session['id'] ?? null)
            || ($session['livemode'] ?? null) !== false
            || ($session['account'] ?? null) !== null || ($session['context'] ?? null) !== null
            || ! is_array($session['line_items'] ?? null)) {
            throw new RuntimeException('STRIPE_CHECKOUT_UNAVAILABLE');
        }
        // Expanded sessions contain only the first handful of items. One bounded list
        // request covers the supported cart; never silently validate a partial list.
        if (($session['line_items']['has_more'] ?? null) === true) {
            $session['line_items'] = $client->checkout->sessions->allLineItems($session['id'], ['limit' => 100])->toArray();
        }
        $items = $session['line_items'];
        if (($items['object'] ?? null) !== 'list' || ($items['has_more'] ?? null) !== false
            || ! is_array($items['data'] ?? null) || ! array_is_list($items['data']) || count($items['data']) > 100) {
            throw new RuntimeException('STRIPE_CHECKOUT_UNAVAILABLE');
        }

        return $session;
    }

    private function isTestSessionId(mixed $id): bool
    {
        return is_string($id) && preg_match('/\Acs_test_[A-Za-z0-9]{1,240}\z/', $id) === 1;
    }
}
