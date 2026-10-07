<?php

namespace Tests\Feature;

use App\Domain\Commerce\ProductionCheckout\ExecutionContextV1;
use App\Domain\Commerce\Readiness\StripeCapabilityPreflight;
use App\Domain\Commerce\Readiness\StripeCapabilityProbe;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

/** Synthetic configuration and transport only. No key is real and no test reaches the network. */
class StripeCapabilityPreflightTest extends TestCase
{
    private const SECRET = 'sk_test_SYNTHETICPREFLIGHTSECRETMARKER';

    private const WEBHOOK = 'whsec_SYNTHETICWEBHOOKSECRETMARKER';

    private const ACCOUNT = 'acct_SYNTHETICPREFLIGHT';

    protected function setUp(): void
    {
        parent::setUp();
        config(['production_checkout.funds_mode' => null, 'production_checkout.account_id' => null,
            'production_checkout.return_origin' => null, 'production_checkout.review_lifetime_seconds' => null,
            'production_checkout.secret_key' => null, 'production_checkout.provider_io_enabled' => false,
            'payments.stripe.webhook_secret' => null]);
    }

    private function shaped(array $overrides = []): void
    {
        config([...['production_checkout.funds_mode' => 'test', 'production_checkout.account_id' => self::ACCOUNT,
            'production_checkout.return_origin' => 'https://review.invalid', 'production_checkout.review_lifetime_seconds' => 600], ...$overrides]);
    }

    private function fixture(array $responses = [], bool $throws = false): StripePreflightHttpFixture
    {
        $fixture = new StripePreflightHttpFixture($responses, $throws);
        $this->app->instance(StripeCapabilityProbe::class, new StripeCapabilityProbe($fixture));

        return $fixture;
    }

    private function preflight(array $options = []): array
    {
        $exit = Artisan::call('vasey:stripe-preflight', ['--json' => true, ...$options]);
        $output = Artisan::output();
        foreach ([self::SECRET, self::WEBHOOK, 'sk_live_SYNTHETIC'] as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }

        return [$exit, json_decode($output, true, 64, JSON_THROW_ON_ERROR)];
    }

    private function check(array $report, string $id): string
    {
        foreach ($report['checks'] as $check) {
            if ($check['id'] === $id) {
                return $check['status'];
            }
        }
        $this->fail('Missing check '.$id);
    }

    private static function account(array $fields = []): array
    {
        return [...['object' => 'account', 'id' => self::ACCOUNT, 'charges_enabled' => true, 'payouts_enabled' => true,
            'details_submitted' => true, 'default_currency' => 'usd', 'email' => 'private-merchant@example.invalid',
            'capabilities' => ['transfers' => 'inactive', 'card_payments' => 'active']], ...$fields];
    }

    /** The own-account response with a different card_payments capability status. */
    private static function accountWithCardPayments(string $status): array
    {
        return self::account(['capabilities' => ['transfers' => 'inactive', 'card_payments' => $status]]);
    }

    public function test_default_configuration_reports_blocked_shape_and_valid_pins_without_any_provider_io(): void
    {
        $fixture = $this->fixture();
        [$exit, $report] = $this->preflight();
        $this->assertSame(1, $exit);
        $this->assertSame(StripeCapabilityPreflight::VERSION, $report['contract_version']);
        $this->assertFalse($report['activation_authorized']);
        $this->assertFalse($report['live_payments_authorized']);
        $this->assertFalse($report['provider_io_performed']);
        $this->assertSame('configuration_only', $report['evidence_origin']);
        $this->assertFalse($report['configuration_shape_valid']);
        $this->assertTrue($report['pins_valid']);
        $this->assertSame(array_fill_keys(['http_enabled', 'fresh_checkout_enabled', 'reconciliation_enabled', 'provider_io_enabled',
            'exemption_authoring_enabled', 'committed_read_receipts_enabled'], false), $report['flags']);
        foreach (['funds_mode', 'account_id_shape', 'return_origin_https', 'review_lifetime_int', 'production_webhook_receiver'] as $id) {
            $this->assertSame('blocked', $this->check($report, $id));
        }
        $this->assertSame('absent', $this->check($report, 'secret_key_reference'));
        $this->assertSame('absent', $this->check($report, 'webhook_secret_reference'));
        $this->assertSame(['status' => 'not_requested', 'reason' => null, 'observation' => null], $report['probe']);
        $this->assertSame([], $fixture->calls);
    }

    public function test_complete_shape_without_a_key_passes_and_secrets_are_references_only(): void
    {
        $fixture = $this->fixture();
        $this->shaped();
        [$exit, $report] = $this->preflight();
        $this->assertSame(0, $exit);
        $this->assertTrue($report['configuration_shape_valid']);
        $this->assertSame('test', $report['funds_mode']);
        $this->assertSame('absent', $this->check($report, 'secret_key_reference'));

        config(['production_checkout.secret_key' => self::SECRET, 'payments.stripe.webhook_secret' => self::WEBHOOK]);
        [$exit, $report] = $this->preflight();
        $this->assertSame(0, $exit);
        $this->assertSame('present', $this->check($report, 'secret_key_reference'));
        $this->assertSame('present', $this->check($report, 'webhook_secret_reference'));
        $this->assertStringNotContainsString('SYNTHETICPREFLIGHT', json_encode($report['checks'], JSON_THROW_ON_ERROR));

        Artisan::call('vasey:stripe-preflight');
        $table = Artisan::output();
        $this->assertStringContainsString('authorizes no activation', $table);
        $this->assertStringNotContainsString(self::SECRET, $table);
        $this->assertStringNotContainsString(self::WEBHOOK, $table);
        $this->assertSame([], $fixture->calls);
    }

    public static function malformed(): array
    {
        return [
            'mode case' => [['production_checkout.funds_mode' => 'TEST'], 'funds_mode'],
            'mode other' => [['production_checkout.funds_mode' => 'sandbox'], 'funds_mode'],
            'account prefix' => [['production_checkout.account_id' => 'acc_SYNTHETIC'], 'account_id_shape'],
            'account empty suffix' => [['production_checkout.account_id' => 'acct_'], 'account_id_shape'],
            'account punctuation' => [['production_checkout.account_id' => "acct_SYNTH\nETIC"], 'account_id_shape'],
            'origin http' => [['production_checkout.return_origin' => 'http://review.invalid'], 'return_origin_https'],
            'origin path' => [['production_checkout.return_origin' => 'https://review.invalid/return'], 'return_origin_https'],
            'origin credentials' => [['production_checkout.return_origin' => 'https://user:pass@review.invalid'], 'return_origin_https'],
            'lifetime string' => [['production_checkout.review_lifetime_seconds' => '600'], 'review_lifetime_int'],
            'lifetime low' => [['production_checkout.review_lifetime_seconds' => 29], 'review_lifetime_int'],
            'lifetime high' => [['production_checkout.review_lifetime_seconds' => 3601], 'review_lifetime_int'],
            'key foreign mode' => [['production_checkout.secret_key' => 'sk_live_SYNTHETIC'], 'secret_key_reference'],
            'restricted key' => [['production_checkout.secret_key' => 'rk_test_SYNTHETIC'], 'secret_key_reference'],
            'webhook shape' => [['payments.stripe.webhook_secret' => 'not-a-webhook-secret'], 'webhook_secret_reference'],
        ];
    }

    #[DataProvider('malformed')]
    public function test_each_malformed_value_is_blocked_without_echoing_it(array $overrides, string $check): void
    {
        $fixture = $this->fixture();
        $this->shaped($overrides);
        [$exit, $report] = $this->preflight(['--probe' => true, '--i-understand-this-calls-stripe' => true]);
        $this->assertSame('blocked', $this->check($report, $check));
        $this->assertSame([], $fixture->calls);
        if ($check !== 'webhook_secret_reference') {
            $this->assertSame(1, $exit);
            $this->assertFalse($report['configuration_shape_valid']);
            $this->assertSame('refused', $report['probe']['status']);
        }
        $this->assertStringNotContainsString('user:pass', json_encode($report, JSON_THROW_ON_ERROR));
    }

    public function test_pin_checks_compare_lock_runtime_and_manifest_and_fail_on_drift(): void
    {
        $lock = json_decode(file_get_contents(base_path('composer.lock')), true, 512, JSON_THROW_ON_ERROR);
        $locked = array_values(array_filter($lock['packages'], fn (array $p): bool => $p['name'] === 'stripe/stripe-php'))[0]['version'];
        $this->assertSame('v'.StripeCapabilityPreflight::EXPECTED_SDK_VERSION, $locked);
        $this->assertSame('2026-08-26.dahlia', ExecutionContextV1::API_VERSION);

        $original = base_path();
        $temporary = sys_get_temp_dir().'/va-stripe-preflight-'.bin2hex(random_bytes(6));
        $manifest = $temporary.'/'.StripeCapabilityPreflight::MANIFEST;
        $spec = $temporary.'/'.StripeCapabilityPreflight::MANIFEST_SPEC;
        mkdir(dirname($manifest), 0700, true);
        try {
            $write = function (array $lockFile, array $manifestFile, string $specBytes) use ($temporary, $manifest, $spec): void {
                file_put_contents($temporary.'/composer.lock', json_encode($lockFile, JSON_THROW_ON_ERROR));
                file_put_contents($manifest, json_encode($manifestFile, JSON_THROW_ON_ERROR));
                file_put_contents($spec, $specBytes);
            };
            $realManifest = json_decode(file_get_contents(base_path(StripeCapabilityPreflight::MANIFEST)), true, 64, JSON_THROW_ON_ERROR);
            $realSpec = file_get_contents(base_path(StripeCapabilityPreflight::MANIFEST_SPEC));
            $this->app->setBasePath($temporary);
            $pins = function (): array {
                $report = app(StripeCapabilityPreflight::class)->collect();

                return array_column(array_filter($report['checks'], fn (array $c): bool => $c['category'] === 'pin'), 'status', 'id');
            };

            $write($lock, $realManifest, $realSpec);
            $this->assertSame(['pass'], array_values(array_unique($pins())));

            $drifted = $lock;
            foreach ($drifted['packages'] as &$package) {
                if ($package['name'] === 'stripe/stripe-php') {
                    $package['version'] = 'v21.4.0';
                }
            }
            unset($package);
            $write($drifted, $realManifest, $realSpec);
            $this->assertSame('blocked', $pins()['sdk_lock_installed_runtime_equal']);
            $this->assertSame('blocked', $pins()['sdk_matches_pinned_manifest']);

            $write($lock, [...$realManifest, 'implemented_pinned_api_version' => '2026-09-30.endive'], $realSpec);
            $this->assertSame('blocked', $pins()['api_version_pins_equal']);

            $write($lock, $realManifest, $realSpec.'x');
            $this->assertSame('blocked', $pins()['provider_source_manifest_hash']);
        } finally {
            $this->app->setBasePath($original);
            foreach ([$spec, $manifest, $temporary.'/composer.lock'] as $file) {
                @unlink($file);
            }
            for ($dir = dirname($spec); str_starts_with($dir, $temporary); $dir = dirname($dir)) {
                @rmdir($dir);
            }
        }
    }

    public static function refusals(): array
    {
        return [
            'confirmation missing' => [['--probe' => true], [], 'confirmation_flag_missing'],
            'provider io disabled' => [['--probe' => true, '--i-understand-this-calls-stripe' => true], ['production_checkout.provider_io_enabled' => false], 'provider_io_disabled'],
            'provider io truthy string' => [['--probe' => true, '--i-understand-this-calls-stripe' => true], ['production_checkout.provider_io_enabled' => 'true'], 'provider_io_disabled'],
            'shape invalid' => [['--probe' => true, '--i-understand-this-calls-stripe' => true], ['production_checkout.return_origin' => 'http://review.invalid'], 'configuration_shape_invalid'],
            'key absent' => [['--probe' => true, '--i-understand-this-calls-stripe' => true], ['production_checkout.secret_key' => null], 'secret_key_absent_or_malformed'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_probe_refusals_happen_before_any_transport_use(array $options, array $config, string $reason): void
    {
        $fixture = $this->fixture([self::account()]);
        $this->shaped(['production_checkout.secret_key' => self::SECRET, 'production_checkout.provider_io_enabled' => true, ...$config]);
        $previous = ApiRequestor::httpClient();
        [$exit, $report] = $this->preflight($options);
        $this->assertSame(1, $exit);
        $this->assertSame(['status' => 'refused', 'reason' => $reason, 'observation' => null], $report['probe']);
        $this->assertFalse($report['provider_io_performed']);
        $this->assertSame([], $fixture->calls);
        $this->assertSame($previous, ApiRequestor::httpClient());
    }

    public function test_testing_environment_refuses_the_real_transport_and_open_transactions(): void
    {
        $this->shaped(['production_checkout.secret_key' => self::SECRET, 'production_checkout.provider_io_enabled' => true]);
        $this->assertFalse(app(StripeCapabilityProbe::class)->usesFixture());
        [$exit, $report] = $this->preflight(['--probe' => true, '--i-understand-this-calls-stripe' => true]);
        $this->assertSame(1, $exit);
        $this->assertSame('fixture_transport_required_in_testing', $report['probe']['reason']);

        $fixture = $this->fixture([self::account()]);
        DB::beginTransaction();
        try {
            $report = app(StripeCapabilityPreflight::class)->collect(true, true);
        } finally {
            DB::rollBack();
        }
        $this->assertSame('open_database_transaction', $report['probe']['reason']);
        $this->assertSame([], $fixture->calls);

        // A transaction opened on the raw PDO is invisible to transactionLevel(); it must still refuse.
        $pdo = DB::connection()->getPdo();
        $pdo->beginTransaction();
        try {
            $this->assertSame(0, DB::connection()->transactionLevel());
            $report = app(StripeCapabilityPreflight::class)->collect(true, true);
            $this->assertSame('open_database_transaction', $report['probe']['reason']);
            try {
                app(StripeCapabilityProbe::class)->observe('test', self::ACCOUNT, self::SECRET);
                $this->fail('observe() ran inside a raw PDO transaction');
            } catch (RuntimeException $error) {
                $this->assertStringNotContainsString(self::SECRET, $error->getMessage());
            }
        } finally {
            $pdo->rollBack();
        }
        $this->assertSame([], $fixture->calls);
    }

    public function test_confirmed_probe_reads_only_the_own_account_with_pinned_headers(): void
    {
        $fixture = $this->fixture([self::account()]);
        $this->shaped(['production_checkout.secret_key' => self::SECRET, 'production_checkout.provider_io_enabled' => true]);
        $previous = ApiRequestor::httpClient();
        [$exit, $report] = $this->preflight(['--probe' => true, '--i-understand-this-calls-stripe' => true]);
        $this->assertSame(0, $exit);
        $this->assertSame($previous, ApiRequestor::httpClient());
        $this->assertTrue($report['provider_io_performed']);
        $this->assertSame('synthetic_fixture', $report['evidence_origin']);
        $this->assertSame('pass', $report['probe']['status']);
        $this->assertSame(['evidence_origin' => 'synthetic_fixture', 'endpoints' => StripeCapabilityProbe::ENDPOINTS,
            'account_matches_configuration' => true, 'charges_enabled' => true, 'payouts_enabled' => true, 'details_submitted' => true,
            'default_currency_is_usd' => true, 'capabilities' => ['card_payments' => 'active', 'transfers' => 'inactive']], $report['probe']['observation']);
        $this->assertStringNotContainsString('private-merchant@example.invalid', json_encode($report, JSON_THROW_ON_ERROR));

        // One read only: the own-account response carries the capabilities hash; no Connect endpoint is touched.
        $this->assertCount(1, $fixture->calls);
        $this->assertSame(['get', 'https://api.stripe.com/v1/account'], [$fixture->calls[0]['method'], $fixture->calls[0]['url']]);
        foreach ($fixture->calls as $call) {
            $headers = implode("\n", $call['headers']);
            $this->assertStringContainsString('Stripe-Version: '.ExecutionContextV1::API_VERSION, $headers);
            $this->assertStringNotContainsString('Stripe-Account:', $headers);
            $this->assertStringNotContainsString('Stripe-Context:', $headers);
            $this->assertStringNotContainsString('Idempotency-Key:', $headers);
            $this->assertSame(0, $call['maxNetworkRetries']);
        }
    }

    public function test_live_mode_requires_charges_and_active_card_payments_from_observed_evidence(): void
    {
        $live = ['production_checkout.funds_mode' => 'live', 'production_checkout.secret_key' => 'sk_live_SYNTHETIC', 'production_checkout.provider_io_enabled' => true];
        $this->fixture([self::account(['charges_enabled' => false])]);
        $this->shaped($live);
        [$exit, $report] = $this->preflight(['--probe' => true, '--i-understand-this-calls-stripe' => true]);
        $this->assertSame([1, 'blocked'], [$exit, $report['probe']['status']]);

        $this->fixture([self::accountWithCardPayments('pending')]);
        [$exit, $report] = $this->preflight(['--probe' => true, '--i-understand-this-calls-stripe' => true]);
        $this->assertSame([1, 'blocked'], [$exit, $report['probe']['status']]);

        $this->fixture([self::account()]);
        [$exit, $report] = $this->preflight(['--probe' => true, '--i-understand-this-calls-stripe' => true]);
        $this->assertSame([0, 'pass'], [$exit, $report['probe']['status']]);
        $this->assertFalse($report['live_payments_authorized']);
        $this->assertFalse($report['activation_authorized']);
    }

    public function test_foreign_account_is_blocked_without_requesting_its_capabilities(): void
    {
        $fixture = $this->fixture([self::account(['id' => 'acct_FOREIGNSYNTHETIC'])]);
        $this->shaped(['production_checkout.secret_key' => self::SECRET, 'production_checkout.provider_io_enabled' => true]);
        [$exit, $report] = $this->preflight(['--probe' => true, '--i-understand-this-calls-stripe' => true]);
        $this->assertSame([1, 'blocked'], [$exit, $report['probe']['status']]);
        $this->assertFalse($report['probe']['observation']['account_matches_configuration']);
        $this->assertNull($report['probe']['observation']['charges_enabled']);
        $this->assertSame([], $report['probe']['observation']['capabilities']);
        $this->assertCount(1, $fixture->calls);
        $this->assertStringNotContainsString('acct_FOREIGNSYNTHETIC', json_encode($report, JSON_THROW_ON_ERROR));
    }

    public static function failures(): array
    {
        return [
            'transport exception' => [[], true],
            'not an account' => [[['object' => 'customer', 'id' => self::ACCOUNT]], false],
            'unknown capability status' => [[self::account(['capabilities' => ['card_payments' => 'PRIVATE-STATUS']])], false],
            'capabilities not a hash' => [[self::account(['capabilities' => 'active'])], false],
        ];
    }

    #[DataProvider('failures')]
    public function test_provider_failures_are_reported_without_details_and_transport_is_restored(array $responses, bool $throws): void
    {
        $this->fixture($responses, $throws);
        $this->shaped(['production_checkout.secret_key' => self::SECRET, 'production_checkout.provider_io_enabled' => true]);
        $previous = ApiRequestor::httpClient();
        [$exit, $report] = $this->preflight(['--probe' => true, '--i-understand-this-calls-stripe' => true]);
        $this->assertSame(1, $exit);
        $this->assertSame(['status' => 'failed', 'reason' => 'provider_request_failed', 'observation' => null], $report['probe']);
        $this->assertTrue($report['provider_io_performed']);
        $this->assertSame($previous, ApiRequestor::httpClient());
        $encoded = json_encode($report, JSON_THROW_ON_ERROR);
        foreach (['PRIVATE REQUEST', 'PRIVATE-STATUS', 'acct_OTHER'] as $private) {
            $this->assertStringNotContainsString($private, $encoded);
        }
    }

    public function test_probe_class_itself_never_builds_the_real_transport_in_testing(): void
    {
        // R-1: the class guard, not only the command, must refuse a real transport in testing.
        $built = 0;
        $probe = new StripeCapabilityProbe(null, function () use (&$built): ClientInterface {
            $built++;

            return new StripePreflightHttpFixture([], true);
        });
        $this->assertFalse($probe->usesFixture());
        $previous = ApiRequestor::httpClient();
        try {
            $probe->observe('test', self::ACCOUNT, self::SECRET);
            $this->fail('The probe admitted a real transport in testing.');
        } catch (RuntimeException $error) {
            $this->assertNull($error->getPrevious());
        }
        $this->assertSame(0, $built);
        $this->assertSame($previous, ApiRequestor::httpClient());
    }

    public static function fundsModeEnvironments(): array
    {
        return [
            'test funds on production' => ['production', 'test', 'blocked'],
            'test funds on staging' => ['staging', 'test', 'blocked'],
            'test funds on local' => ['local', 'test', 'pass'],
            'test funds in testing' => ['testing', 'test', 'pass'],
            'live funds on production' => ['production', 'live', 'pass'],
        ];
    }

    #[DataProvider('fundsModeEnvironments')]
    public function test_funds_mode_follows_the_checkout_environment_rule(string $environment, string $mode, string $status): void
    {
        // R-2: ExecutionContextV1 refuses test funds outside local/testing; the preflight must not pass that configuration.
        $this->shaped(['production_checkout.funds_mode' => $mode]);
        $this->app->instance('env', $environment);
        try {
            $report = app(StripeCapabilityPreflight::class)->collect(true, false);
        } finally {
            $this->app->instance('env', 'testing');
        }
        $this->assertSame($status, $this->check($report, 'funds_mode_environment'));
        $this->assertSame($status === 'pass', $report['configuration_shape_valid']);
    }

    #[DataProvider('malformedCapabilities')]
    public function test_malformed_capabilities_hash_fails_closed(mixed $capabilities): void
    {
        $fixture = $this->fixture([self::account(['capabilities' => $capabilities])]);
        $this->shaped(['production_checkout.secret_key' => self::SECRET, 'production_checkout.provider_io_enabled' => true]);
        [$exit, $report] = $this->preflight(['--probe' => true, '--i-understand-this-calls-stripe' => true]);
        $this->assertSame([1, 'failed'], [$exit, $report['probe']['status']]);
        $this->assertCount(1, $fixture->calls);
    }

    public static function malformedCapabilities(): array
    {
        return [
            'not a hash' => ['active'],
            'unknown status' => [['card_payments' => 'enabled']],
            'numeric name' => [[0 => 'active']],
            'hostile name' => [['card payments; drop' => 'active']],
        ];
    }

    public function test_probe_class_refuses_fixture_transport_outside_testing(): void
    {
        $probe = new StripeCapabilityProbe(new StripePreflightHttpFixture([self::account()]));
        $this->app->instance('env', 'production');
        $this->expectException(RuntimeException::class);
        $probe->observe('test', self::ACCOUNT, self::SECRET);
    }
}

final class StripePreflightHttpFixture implements ClientInterface
{
    public array $calls = [];

    public function __construct(private array $responses, private bool $throws = false) {}

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->calls[] = ['method' => $method, 'url' => $absUrl, 'headers' => $headers, 'params' => $params, 'maxNetworkRetries' => $maxNetworkRetries];
        if ($this->throws) {
            throw new RuntimeException('PRIVATE REQUEST SECRET URL');
        }

        return [json_encode(array_shift($this->responses), JSON_THROW_ON_ERROR), 200, []];
    }
}
