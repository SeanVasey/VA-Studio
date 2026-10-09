<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Runs the real scripts/ops/validate-test-commerce-profile.php in a scrubbed process, so only the
 * profile under test (never the PHPUnit testing environment or a host .env) reaches the policies.
 */
class TestCommerceProfileValidatorTest extends TestCase
{
    private const TEMPLATE = 'ops/staging/test-commerce/env.test-commerce.example';

    /** Synthetic, deliberately different from the validator's own --template values. */
    private const FILLED = [
        '<STAGING_HOST>' => 'staging.synthetic.invalid',
        '<acct_ID>' => 'acct_StagingSyntheticOnly',
        '<sk_test_KEY>' => 'sk_test_SyntheticValidatorKey123456',
        '<whsec_SECRET>' => 'whsec_SyntheticValidatorSecret123456',
        '<SELLER_LEGAL_NAME>' => 'Synthetic Staging Seller',
        '<ASSENT_TEXT>' => 'I accept these synthetic test-only terms.',
    ];

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/va-profile-validator-'.bin2hex(random_bytes(8));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function filled(): string
    {
        return strtr((string) file_get_contents(self::root().'/'.self::TEMPLATE), self::FILLED);
    }

    /** Replace one KEY=value line of the profile. */
    private static function set(string $profile, string $key, string $value): string
    {
        $count = 0;
        $profile = preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $key.'='.$value, $profile, 1, $count);

        return $count === 1 ? $profile : $profile.$key.'='.$value."\n";
    }

    /** @return array{int, string, array<string, bool>} */
    private function validate(string $profile, array $arguments = [], array $exports = []): array
    {
        $file = $this->dir.'/staging.env';
        file_put_contents($file, $profile);

        return $this->validateFile($file, $arguments, $exports);
    }

    /** @return array{int, string, array<string, bool>} */
    private function validateFile(string $file, array $arguments = [], array $exports = []): array
    {
        $environment = [];
        foreach (array_unique([...array_keys((array) getenv()), ...array_keys($_ENV), ...array_keys($_SERVER)]) as $name) {
            if (is_string($name) && $name !== '') {
                $environment[$name] = false;
            }
        }
        $environment['PATH'] = (string) getenv('PATH');
        $environment = array_replace($environment, $exports);
        $process = new Process([PHP_BINARY, self::root().'/scripts/ops/validate-test-commerce-profile.php', ...$arguments, $file],
            self::root(), $environment);
        $process->setTimeout(120);
        $process->run();
        $checks = [];
        foreach (explode("\n", $process->getOutput()) as $line) {
            if (preg_match('/\A(PASS|FAIL) ([a-z_.0-9]+) - /', $line, $match)) {
                $checks[$match[2]] = $match[1] === 'PASS';
            }
        }

        return [$process->getExitCode(), $process->getOutput().$process->getErrorOutput(), $checks];
    }

    private function assertNoSecretPrinted(string $output): void
    {
        foreach (['sk_test_Synthetic', 'whsec_Synthetic', 'acct_StagingSynthetic', 'sk_test_Template', 'whsec_Template', 'sk_live_'] as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }
    }

    public function test_the_committed_template_has_a_valid_shape_with_synthetic_placeholders(): void
    {
        [$exit, $output, $checks] = $this->validateFile(self::root().'/'.self::TEMPLATE, ['--template']);

        $this->assertSame(0, $exit, $output);
        $this->assertCount(35, $checks);
        $this->assertNotContains(false, $checks);
        $this->assertStringContainsString('RESULT: PASS (0 of 35 checks failed; template mode', $output);
        $this->assertNoSecretPrinted($output);
    }

    public function test_the_committed_template_is_refused_until_every_placeholder_is_replaced(): void
    {
        [$exit, $output, $checks] = $this->validateFile(self::root().'/'.self::TEMPLATE);

        $this->assertSame(1, $exit);
        $this->assertFalse($checks['profile.placeholders_replaced']);
        $this->assertFalse($checks['runtime.boot']);
        $this->assertStringContainsString('APP_URL, STRIPE_ACCOUNT_ID, STRIPE_TEST_SECRET_KEY, STRIPE_WEBHOOK_SECRET, VASEY_TEST_PRICING_POLICY, VASEY_TEST_ORDER_POLICY, VASEY_TEST_CHECKOUT_POLICY', $output);
    }

    public function test_a_complete_synthetic_profile_passes_every_real_policy_without_printing_values(): void
    {
        [$exit, $output, $checks] = $this->validate(self::filled());

        $this->assertSame(0, $exit, $output);
        $this->assertCount(35, $checks);
        $this->assertNotContains(false, $checks);
        $this->assertStringContainsString('no Stripe request made', $output);
        $this->assertNoSecretPrinted($output);
        $this->assertStringNotContainsString('Synthetic Staging Seller', $output);
    }

    public static function maskedFileValues(): array
    {
        return [
            'environment' => ['APP_ENV', 'production', 'local', 'runtime.app_env_local'],
            'debug' => ['APP_DEBUG', 'true', 'false', 'runtime.app_debug_off'],
            'account' => ['STRIPE_ACCOUNT_ID', 'invalid-file-account', self::FILLED['<acct_ID>'], 'stripe.account'],
            'secret key' => ['STRIPE_TEST_SECRET_KEY', 'invalid-file-key', self::FILLED['<sk_test_KEY>'], 'stripe.secret_key_test'],
            'origin' => ['APP_URL', 'https://different.synthetic.invalid', 'https://staging.synthetic.invalid', 'runtime.app_url_equals_return_origin'],
            'production boundary' => ['PRODUCTION_CHECKOUT_PROVIDER_IO_ENABLED', 'true', 'false', 'boundary.out_of_profile_families_off'],
        ];
    }

    #[DataProvider('maskedFileValues')]
    public function test_exported_safe_values_cannot_mask_an_unsafe_file_or_authorize_a_probe(string $name, string $unsafe, string $exported, string $check): void
    {
        [$exit, $output, $checks] = $this->validate(self::set(self::filled(), $name, $unsafe),
            ['--probe', '--i-understand-this-calls-stripe'], [$name => $exported]);

        $this->assertSame(1, $exit, $output);
        $this->assertFalse($checks[$check], $output);
        $this->assertFalse($checks['stripe.probe_account']);
        $this->assertStringContainsString('Probe not attempted', $output);
        $this->assertNoSecretPrinted($output);
    }

    public function test_the_profile_is_the_only_application_environment_source(): void
    {
        [$exit, $output, $checks] = $this->validate(self::filled(), [], [
            'APP_ENV' => 'production', 'APP_DEBUG' => 'true', 'STRIPE_MODE' => 'live',
            'PRODUCTION_CHECKOUT_ENABLED' => 'true', 'STRIPE_ACCOUNT_ID' => 'invalid-export-account',
        ]);
        $this->assertSame(0, $exit, $output);
        $this->assertNotContains(false, $checks);
        $this->assertNoSecretPrinted($output);
    }

    public static function brokenProfiles(): array
    {
        $json = static fn (string $from, string $to): \Closure => static fn (string $p): string => str_replace($from, $to, $p);
        $set = static fn (string $key, string $value): \Closure => static fn (string $p): string => self::set($p, $key, $value);

        return [
            'production environment' => [$set('APP_ENV', 'production'), 'runtime.app_env_local'],
            'testing environment on a host' => [$set('APP_ENV', 'testing'), 'runtime.app_env_local'],
            'debug on' => [$set('APP_DEBUG', 'true'), 'runtime.app_debug_off'],
            'app url differs from return origin' => [$set('APP_URL', 'https://other.synthetic.invalid'), 'runtime.app_url_equals_return_origin'],
            'loopback http origin' => [static fn (string $p): string => str_replace('https://staging.synthetic.invalid', 'http://localhost', $p), 'commerce.checkout_https_origin'],
            'sync queue' => [$set('QUEUE_CONNECTION', 'sync'), 'runtime.queue_database'],
            'insecure session cookie' => [$set('SESSION_SECURE_COOKIE', 'false'), 'runtime.session_secure_cookie'],
            'live stripe mode' => [$set('STRIPE_MODE', 'live'), 'stripe.mode_test'],
            'restricted key' => [$set('STRIPE_TEST_SECRET_KEY', 'rk_test_SyntheticValidatorKey123456'), 'stripe.secret_key_test'],
            'live key anywhere' => [$set('STRIPE_TEST_SECRET_KEY', 'sk_'.'live_'.'SyntheticValidatorKey123456') /* split so secret scanning does not read a synthetic value as a live key */, 'profile.no_live_keys'],
            'malformed webhook secret' => [$set('STRIPE_WEBHOOK_SECRET', 'whsec_x'), 'stripe.webhook_receiver'],
            'webhook disabled' => [$set('STRIPE_WEBHOOK_ENABLED', 'false'), 'stripe.webhook_receiver'],
            'non-zero test tax' => [$json('"rate_bps":0', '"rate_bps":750'), 'commerce.pricing_zero_test_tax'],
            'pricing for another account' => [$json('"account":"acct_StagingSyntheticOnly"', '"account":"acct_OtherSynthetic"'), 'commerce.pricing_zero_test_tax'],
            'pricing window closed' => [$json('"effective_until":"2027-01-01T00:00:00Z"', '"effective_until":"2026-10-02T00:00:00Z"'), 'commerce.pricing'],
            'short inventory hold' => [$json('"ttl_seconds":900', '"ttl_seconds":60'), 'commerce.inventory_ttl_covers_quote'],
            'exclusive selection enabled' => [$set('VASEY_TEST_EXCLUSIVE_SELECTION_POLICY', '\'{"schema_version":1,"purpose":"test_exclusive_selection","non_exclusive_cutoff":"block_while_reserved","existing_pending":"retain_until_verified_resolution","discounts":"explicit_revision_only"}\''), 'commerce.exclusive_selection_off'],
            'order policy extra field' => [$json('"buyer_identity":"unverified_guest"}\'', '"buyer_identity":"unverified_guest","extra":1}\''), 'commerce.order'],
            'checkout disabled' => [$set('STRIPE_TEST_CHECKOUT_ENABLED', 'false'), 'commerce.checkout'],
            'checkout lifetime changed' => [$json('"provider_lifetime_seconds":3600', '"provider_lifetime_seconds":1800'), 'commerce.checkout'],
            'processing disabled' => [$set('STRIPE_TEST_PAYMENT_PROCESSING_ENABLED', 'false'), 'payments.processing'],
            'finalization policy changed' => [$json('"grant_effective_time":"finalization_time"', '"grant_effective_time":"payment_time"'), 'payments.finalization'],
            'retained v1 contract policy' => [$json('"version":"test-contract-issuance-v2","profile":"test-buyer-pdf-v2"', '"version":"test-contract-issuance-v1","profile":"test-buyer-pdf-v1"'), 'contracts.issuance_v2'],
            'activation grants downloads' => [$json('"download_access":"disabled"', '"download_access":"enabled"'), 'delivery.activation'],
            'longer delivery authorization' => [$json('"authorization_ttl_seconds":60', '"authorization_ttl_seconds":120'), 'delivery.access'],
            'identity sends mail' => [$set('VASEY_TEST_CUSTOMER_IDENTITY_TRANSPORT', 'mail'), 'customer.identity_private_capture'],
            'production checkout flag' => [$set('PRODUCTION_CHECKOUT_PROVIDER_IO_ENABLED', 'true'), 'boundary.out_of_profile_families_off'],
            'free grants enabled' => [$set('VASEY_TEST_FREE_GRANTS_ENABLED', 'true'), 'boundary.out_of_profile_families_off'],
            'production checkout key' => [$set('PRODUCTION_CHECKOUT_STRIPE_SECRET_KEY', 'sk_test_SyntheticValidatorKey123456'), 'boundary.no_production_checkout_key'],
            'duplicate key' => [static fn (string $p): string => $p."STRIPE_MODE=test\n", 'profile.unique_keys'],
            'single quote inside json' => [$json('I accept these', 'I\'m accepting these'), 'profile.parse'],
        ];
    }

    #[DataProvider('brokenProfiles')]
    public function test_a_broken_profile_is_refused_with_the_responsible_check(\Closure $break, string $check): void
    {
        [$exit, $output, $checks] = $this->validate($break(self::filled()));

        $this->assertSame(1, $exit, $output);
        $this->assertArrayHasKey($check, $checks, $output);
        $this->assertFalse($checks[$check], $output);
        $this->assertStringContainsString('RESULT: FAIL', $output);
        $this->assertNoSecretPrinted($output);
    }

    public function test_a_probe_is_never_attempted_while_any_check_fails(): void
    {
        [$exit, $output, $checks] = $this->validate(self::set(self::filled(), 'APP_DEBUG', 'true'), ['--probe', '--i-understand-this-calls-stripe']);

        $this->assertSame(1, $exit);
        $this->assertFalse($checks['stripe.probe_account']);
        $this->assertStringContainsString('Probe not attempted', $output);
    }

    public function test_usage_errors_exit_two_without_checks(): void
    {
        $file = $this->dir.'/staging.env';
        file_put_contents($file, self::filled());
        foreach ([['--probe'], ['--i-understand-this-calls-stripe'], ['--template', '--probe', '--i-understand-this-calls-stripe'], ['--unknown']] as $arguments) {
            [$exit, $output, $checks] = $this->validateFile($file, $arguments);
            $this->assertSame(2, $exit, implode(' ', $arguments));
            $this->assertSame([], $checks);
        }
        [$exit] = $this->validateFile($this->dir.'/missing.env');
        $this->assertSame(2, $exit);
        symlink($file, $this->dir.'/link.env');
        [$exit] = $this->validateFile($this->dir.'/link.env');
        $this->assertSame(2, $exit);
    }
}
