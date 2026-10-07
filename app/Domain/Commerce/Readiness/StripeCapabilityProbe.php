<?php

namespace App\Domain\Commerce\Readiness;

use App\Domain\Commerce\ProductionCheckout\ExecutionContextV1;
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

/**
 * Read-only own-account observation: GET /v1/account and GET /v1/accounts/{id}/capabilities only.
 * Uses the same SDK transport seam as OwnAccountStripeGateway, so tests inject a synthetic
 * ClientInterface. Never creates, updates or deletes provider objects.
 */
final class StripeCapabilityProbe
{
    public const ENDPOINTS = ['GET /v1/account', 'GET /v1/accounts/{account}/capabilities'];

    private const STATUSES = ['active', 'inactive', 'pending', 'unrequested', 'disabled'];

    /**
     * @param  ?Closure(): ClientInterface  $realTransport  Builds the real network transport; the default is a bounded
     *                                                      CurlClient. Only a test may observe whether it is invoked.
     */
    public function __construct(private readonly ?ClientInterface $fixtureTransport = null, private readonly ?Closure $realTransport = null) {}

    public function usesFixture(): bool
    {
        return $this->fixtureTransport !== null;
    }

    /** @return array<string, mixed> redacted observation; never the key, raw body or provider error */
    public function observe(string $mode, string $accountId, #[SensitiveParameter] string $secret): array
    {
        try {
            self::require(in_array($mode, ['test', 'live'], true)
                && preg_match('/\Ask_'.$mode.'_[A-Za-z0-9]{1,240}\z/D', $secret) === 1
                && preg_match('/\Aacct_[A-Za-z0-9]{1,64}\z/D', $accountId) === 1
                // Fixture only in testing, and in testing only a fixture: the real transport is never built there.
                && ($this->fixtureTransport === null) !== app()->environment('testing')
                && Stripe::$accountId === null && Stripe::$verifySslCerts === true && Stripe::$logger === null);
            foreach (DB::getConnections() as $connection) {
                // Framework depth and the raw PDO both: a caller's own beginTransaction() is invisible to the first.
                self::require($connection->transactionLevel() === 0 && ! $connection->getPdo()->inTransaction());
            }
            $client = new StripeClient(['api_key' => $secret, 'api_base' => BaseStripeClient::DEFAULT_API_BASE,
                'stripe_version' => ExecutionContextV1::API_VERSION, 'stripe_account' => null, 'stripe_context' => null,
                'max_network_retries' => 0]);
            $transport = $this->fixtureTransport ?? ($this->realTransport !== null ? ($this->realTransport)()
                : (new CurlClient)->setConnectTimeout(3)->setTimeout(10));
            self::require($transport instanceof ClientInterface);
            $previous = ApiRequestor::httpClient();
            ApiRequestor::setHttpClient($transport);
            try {
                $account = $client->accounts->retrieve()->toArray();
                self::require(($account['object'] ?? null) === 'account' && is_string($account['id'] ?? null));
                $matches = hash_equals($accountId, $account['id']);
                $capabilities = [];
                if ($matches) {
                    $list = $client->accounts->allCapabilities($accountId, ['limit' => 100])->toArray();
                    self::require(($list['object'] ?? null) === 'list' && is_array($list['data'] ?? null) && array_is_list($list['data']));
                    foreach ($list['data'] as $capability) {
                        $name = $capability['id'] ?? null;
                        $status = $capability['status'] ?? null;
                        self::require(($capability['object'] ?? null) === 'capability' && is_string($name)
                            && preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $name) === 1 && in_array($status, self::STATUSES, true)
                            && ($capability['account'] ?? $accountId) === $accountId);
                        $capabilities[$name] = $status;
                    }
                    ksort($capabilities, SORT_STRING);
                }
            } finally {
                ApiRequestor::setHttpClient($previous);
            }
        } catch (Throwable) {
            // SDK exceptions carry URLs, headers and bodies. Never chain, log or echo them.
            throw new RuntimeException('Stripe capability probe failed.');
        }

        $bool = static fn (mixed $value): ?bool => is_bool($value) ? $value : null;

        return [
            'evidence_origin' => $this->fixtureTransport === null ? 'provider_observed' : 'synthetic_fixture',
            'endpoints' => self::ENDPOINTS,
            'account_matches_configuration' => $matches,
            'charges_enabled' => $matches ? $bool($account['charges_enabled'] ?? null) : null,
            'payouts_enabled' => $matches ? $bool($account['payouts_enabled'] ?? null) : null,
            'details_submitted' => $matches ? $bool($account['details_submitted'] ?? null) : null,
            'default_currency_is_usd' => $matches && is_string($account['default_currency'] ?? null) ? $account['default_currency'] === 'usd' : null,
            'capabilities' => $capabilities,
        ];
    }

    private static function require(bool $condition): void
    {
        if (! $condition) {
            throw new RuntimeException('Stripe capability probe precondition failed.');
        }
    }
}
