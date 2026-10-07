<?php

namespace App\Domain\Commerce\Readiness;

use App\Domain\Commerce\Checkout\CheckoutPolicy;
use App\Domain\Commerce\Payments\StripeSdkCheckoutGateway;
use App\Domain\Commerce\ProductionCheckout\ExecutionContextV1;
use Composer\InstalledVersions;
use Illuminate\Support\Facades\DB;
use PDO;
use Stripe\Stripe;
use Stripe\Util\ApiVersion;
use Throwable;

/**
 * Redacted Stripe configuration and pin preflight. Default: no provider I/O.
 * A probe is only attempted when explicitly requested, provider I/O is enabled and the
 * caller passed the explicit confirmation flag. It never authorizes activation or payment.
 */
final class StripeCapabilityPreflight
{
    public const VERSION = 'stripe-capability-preflight-v1';

    public const EXPECTED_SDK_VERSION = '21.3.2';

    public const MANIFEST = 'docs/verification/operative-checkout-new-20261007/provider-source/dahlia-receipt.json';

    public const MANIFEST_SPEC = 'docs/verification/operative-checkout-new-20261007/provider-source/stripe-openapi-dahlia-30d3391c.json.gz';

    private const FLAGS = ['http_enabled', 'fresh_checkout_enabled', 'reconciliation_enabled', 'provider_io_enabled',
        'exemption_authoring_enabled', 'committed_read_receipts_enabled'];

    public function __construct(private readonly StripeCapabilityProbe $probe) {}

    /** @return array<string, mixed> */
    public function collect(bool $probe = false, bool $confirmed = false): array
    {
        $checks = [];
        $add = static function (string $id, string $category, string $status, string $message) use (&$checks): void {
            $checks[] = ['id' => $id, 'category' => $category, 'status' => $status, 'message' => $message];
        };

        $mode = config('production_checkout.funds_mode');
        $account = config('production_checkout.account_id');
        $origin = config('production_checkout.return_origin');
        $lifetime = config('production_checkout.review_lifetime_seconds');
        $secret = config('production_checkout.secret_key');
        $webhook = config('payments.stripe.webhook_secret');

        $modeValid = in_array($mode, ['test', 'live'], true);
        $accountValid = is_string($account) && preg_match('/\Aacct_[A-Za-z0-9]{1,64}\z/D', $account) === 1;
        $originValid = ProductionCommerceReadiness::httpsOrigin($origin);
        $lifetimeValid = is_int($lifetime) && $lifetime >= 30 && $lifetime <= 3600;
        // The same rule ExecutionContextV1::make applies: test funds only in local/testing.
        $modeEnvironment = $modeValid && ($mode !== 'test' || app()->environment(['local', 'testing']));
        $add('funds_mode', 'configuration', $modeValid ? 'pass' : 'blocked', 'PRODUCTION_CHECKOUT_FUNDS_MODE must be exactly test or live (Sean decision).');
        $add('funds_mode_environment', 'configuration', $modeEnvironment ? 'pass' : 'blocked', 'Test funds are admitted only when APP_ENV is local or testing, as production checkout (ExecutionContextV1) requires.');
        $add('account_id_shape', 'configuration', $accountValid ? 'pass' : 'blocked', 'PRODUCTION_CHECKOUT_STRIPE_ACCOUNT_ID must be an own-account acct_ identifier (shape only; ownership unverified).');
        $add('return_origin_https', 'configuration', $originValid ? 'pass' : 'blocked', 'PRODUCTION_CHECKOUT_RETURN_ORIGIN must be a bounded https origin without credentials, path, query or fragment.');
        $add('review_lifetime_int', 'configuration', $lifetimeValid ? 'pass' : 'blocked', 'PRODUCTION_CHECKOUT_REVIEW_LIFETIME_SECONDS must resolve to an integer from 30 to 3600.');

        // References only: presence and prefix class, never the value or its length.
        $secretPresent = is_string($secret) && $secret !== '';
        $secretShape = $secretPresent && $modeValid && preg_match('/\Ask_'.$mode.'_[A-Za-z0-9]{1,240}\z/D', $secret) === 1;
        $add('secret_key_reference', 'configuration', ! $secretPresent ? 'absent' : ($secretShape ? 'present' : 'blocked'),
            'PRODUCTION_CHECKOUT_STRIPE_SECRET_KEY is reported by presence only; a present key must be sk_<funds_mode>_ shaped. Restricted or foreign-mode keys are refused.');
        $webhookPresent = is_string($webhook) && $webhook !== '';
        $add('webhook_secret_reference', 'configuration', ! $webhookPresent ? 'absent'
            : (preg_match('/\Awhsec_[A-Za-z0-9]{8,200}\z/D', $webhook) === 1 ? 'present' : 'blocked'),
            'STRIPE_WEBHOOK_SECRET is reported by presence only. It belongs to the test-only payments.stripe receiver.');
        $add('production_webhook_receiver', 'boundary', 'blocked',
            'No production webhook secret or live receiver exists. VerifyStripeWebhook accepts only test-mode events outside production; production checkout reconciles by authoritative retrieval. A live receiver is unimplemented work, not configuration.');

        $flags = [];
        foreach (self::FLAGS as $flag) {
            $flags[$flag] = config('production_checkout.'.$flag) === true;
        }
        $add('default_off_flags', 'configuration', in_array(true, $flags, true) ? 'reference' : 'pass',
            'Production checkout flags are reported strictly; any enabled flag requires Sean authorization recorded in the activation packet.');

        foreach ($this->pins() as [$id, $ok, $message]) {
            $add($id, 'pin', $ok ? 'pass' : 'blocked', $message);
        }

        $shapeValid = $modeEnvironment && $accountValid && $originValid && $lifetimeValid && (! $secretPresent || $secretShape);
        $observation = null;
        $probeStatus = 'not_requested';
        $probeReason = null;
        if ($probe) {
            $probeReason = match (true) {
                ! $confirmed => 'confirmation_flag_missing',
                $flags['provider_io_enabled'] !== true => 'provider_io_disabled',
                ! $shapeValid => 'configuration_shape_invalid',
                ! $secretShape => 'secret_key_absent_or_malformed',
                app()->environment('testing') && ! $this->probe->usesFixture() => 'fixture_transport_required_in_testing',
                $this->openTransaction() => 'open_database_transaction',
                default => null,
            };
            if ($probeReason !== null) {
                $probeStatus = 'refused';
            } else {
                try {
                    $observation = $this->probe->observe($mode, $account, $secret);
                    $capable = $observation['account_matches_configuration'] === true && ($mode !== 'live'
                        || ($observation['charges_enabled'] === true && ($observation['capabilities']['card_payments'] ?? null) === 'active'));
                    $probeStatus = $capable ? 'pass' : 'blocked';
                } catch (Throwable) {
                    $probeStatus = 'failed';
                    $probeReason = 'provider_request_failed';
                }
            }
        }
        $add('capability_probe', 'probe', $probeStatus, match ($probeStatus) {
            'not_requested' => 'No provider request was made. Pass --probe with --i-understand-this-calls-stripe and enabled provider I/O to request read-only account/capability observation.',
            'refused' => 'Probe refused before any transport was used: '.$probeReason.'.',
            'failed' => 'Read-only probe failed; provider error details are deliberately discarded.',
            'blocked' => 'Observed account does not match configuration or lacks live charges/card_payments capability.',
            default => 'Observed account matches configuration'.($mode === 'live' ? ' with charges and card_payments active.' : '.'),
        });

        $pinsValid = ! in_array('blocked', array_column(array_filter($checks, fn (array $c): bool => $c['category'] === 'pin'), 'status'), true);

        return [
            'schema_version' => 1, 'contract_version' => self::VERSION, 'scope' => 'production_checkout_stripe_preparation',
            'activation_authorized' => false, 'live_payments_authorized' => false,
            'provider_io_performed' => $observation !== null || $probeStatus === 'failed',
            'evidence_origin' => $observation['evidence_origin'] ?? 'configuration_only',
            'configuration_shape_valid' => $shapeValid, 'pins_valid' => $pinsValid,
            'funds_mode' => $modeValid ? $mode : null, 'flags' => $flags,
            'probe' => ['status' => $probeStatus, 'reason' => $probeReason, 'observation' => $observation],
            'counts' => array_count_values(array_column($checks, 'status')), 'checks' => $checks,
        ];
    }

    /** @return list<array{string, bool, string}> */
    private function pins(): array
    {
        $lock = $this->lockedSdkVersion();
        $installed = null;
        try {
            $installed = ltrim((string) InstalledVersions::getPrettyVersion('stripe/stripe-php'), 'v');
        } catch (Throwable) {
        }
        $manifest = $this->json(self::MANIFEST);
        $apiVersions = [ApiVersion::CURRENT, ExecutionContextV1::API_VERSION, CheckoutPolicy::API_VERSION,
            StripeSdkCheckoutGateway::API_VERSION, $manifest['implemented_pinned_api_version'] ?? null, $manifest['spec_api_version'] ?? null];
        $spec = base_path(self::MANIFEST_SPEC);
        $specHash = is_file($spec) && ! is_link($spec) ? hash_file('sha256', $spec) : null;

        return [
            ['sdk_lock_installed_runtime_equal', $lock !== null && $lock === Stripe::VERSION && $lock === $installed,
                'composer.lock, installed Composer metadata and Stripe::VERSION must name the same stripe/stripe-php release.'],
            ['sdk_matches_pinned_manifest', $lock === self::EXPECTED_SDK_VERSION && ($manifest['locked_sdk_version'] ?? null) === self::EXPECTED_SDK_VERSION,
                'The locked SDK must equal the pinned provider-source manifest ('.self::EXPECTED_SDK_VERSION.').'],
            ['api_version_pins_equal', count(array_unique($apiVersions, SORT_REGULAR)) === 1 && $apiVersions[0] === ExecutionContextV1::API_VERSION,
                'SDK ApiVersion::CURRENT, production/test checkout constants and the pinned manifest must all name '.ExecutionContextV1::API_VERSION.'.'],
            ['provider_source_manifest_hash', is_string($specHash) && hash_equals((string) ($manifest['gzip_sha256'] ?? ''), $specHash),
                'The retained official OpenAPI snapshot must match the gzip SHA-256 recorded in its manifest.'],
        ];
    }

    private function lockedSdkVersion(): ?string
    {
        foreach ($this->json('composer.lock')['packages'] ?? [] as $package) {
            if (($package['name'] ?? null) === 'stripe/stripe-php' && is_string($package['version'] ?? null)) {
                return ltrim($package['version'], 'v');
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function json(string $relative): array
    {
        $path = base_path($relative);
        try {
            $decoded = is_file($path) && ! is_link($path) && filesize($path) <= 4194304
                ? json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR) : null;
        } catch (Throwable) {
            $decoded = null;
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function openTransaction(): bool
    {
        foreach (DB::getConnections() as $connection) {
            // A transaction begun on the raw PDO leaves the framework depth at zero, so ask both.
            // Only an already-open handle is inspected: getPdo() would connect a lazy connection
            // just to answer the guard, and a disconnected connection holds no PDO at all.
            $pdo = $connection->getRawPdo();
            if ($connection->transactionLevel() !== 0 || ($pdo instanceof PDO && $pdo->inTransaction())) {
                return true;
            }
        }

        return false;
    }
}
