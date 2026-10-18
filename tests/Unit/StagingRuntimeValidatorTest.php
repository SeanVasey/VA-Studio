<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Vasey\Ops\CapabilityBoundary;

// Data providers run before setUpBeforeClass(), so the shared boundary class is loaded here.
require_once dirname(__DIR__, 2).'/scripts/ops/capability-boundary.php';

class StagingRuntimeValidatorTest extends TestCase
{
    /**
     * The ten capability flags and the two stated beside them (batches K2 and K3). Written out here on purpose: removing one from
     * scripts/ops/capability-boundary.php must fail a test, not silently shrink what the test checks.
     */
    private const STAGED_FLAGS = [
        'VASEY_TEST_SERVICE_PROJECTS_ENABLED', 'VASEY_TEST_FREE_GRANTS_ENABLED', 'VASEY_TEST_CUSTOMER_CONSENT_ENABLED',
        'VASEY_TEST_UNPAID_RELEASE_ENABLED', 'VASEY_TEST_REFUND_RESOLUTION_ENABLED', 'VASEY_TEST_TRANSACTIONAL_NOTIFICATIONS_ENABLED',
        'CONTACT_TEST_ORDER_INQUIRIES_ENABLED', 'CONTACT_INQUIRY_OPERATOR_NOTIFICATIONS_ENABLED',
        'VASEY_OPERATIVE_FREE_GRANTS_ENABLED', 'VASEY_SUPPORT_ATTACHMENTS_FIXTURE_ENABLED',
        'DISCOVERY_SITEMAP_ENABLED', 'VASEY_LISTENING_V2_PROMOTION_ENABLED',
    ];

    /** No environment gate protects these two: the template line and this deploy gate are the only guards on staging. */
    private const UNGATED_FLAGS = ['VASEY_OPERATIVE_FREE_GRANTS_ENABLED', 'CONTACT_INQUIRY_OPERATOR_NOTIFICATIONS_ENABLED'];

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/vasey-runtime-test-'.bin2hex(random_bytes(12));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    private function profile(): string
    {
        return implode("\n", [
            'APP_ENV=local', 'APP_DEBUG=false', 'APP_URL=https://staging.synthetic.invalid',
            'APP_KEY=base64:'.str_repeat('A', 43).'=', 'APP_MAINTENANCE_DRIVER=file',
            'SESSION_DRIVER=database', 'SESSION_SECURE_COOKIE=true', 'SESSION_HTTP_ONLY=true',
            'CACHE_STORE=database', 'QUEUE_CONNECTION=database', 'DB_QUEUE_RETRY_AFTER=1200',
            'DB_CONNECTION=mysql', 'DB_HOST=127.0.0.1', 'DB_DATABASE=vasey_staging',
            'DB_USERNAME=vasey_app', 'DB_PASSWORD=SyntheticPrivateRuntimeMarker',
            'FILESYSTEM_DISK=local', 'MAIL_MAILER=log', 'STRIPE_MODE=test', 'VASEY_PHP_CLI_BINARY='.PHP_BINARY, '',
        ]);
    }

    private function validate(string $profile, ?string $previous = null, string $expected = 'local'): Process
    {
        $file = $this->directory.'/candidate.env';
        file_put_contents($file, $profile);
        $arguments = [PHP_BINARY, dirname(__DIR__, 2).'/ops/staging/validate-runtime.php', $file,
            $expected, 'staging.synthetic.invalid', 'vasey_staging'];
        if ($previous !== null) {
            $previousFile = $this->directory.'/previous.env';
            file_put_contents($previousFile, $previous);
            $arguments[] = $previousFile;
        }
        $environment = [];
        foreach (array_unique([...array_keys(getenv()), ...array_keys($_ENV), ...array_keys($_SERVER)]) as $name) {
            if (is_string($name) && $name !== '') {
                $environment[$name] = false;
            }
        }
        $environment['PATH'] = getenv('PATH');
        $process = new Process($arguments, dirname(__DIR__, 2), $environment);
        $process->setTimeout(30);
        $process->run();
        $this->assertStringNotContainsString('SyntheticPrivateRuntimeMarker', $process->getOutput().$process->getErrorOutput());
        $this->assertStringNotContainsString($file, $process->getOutput().$process->getErrorOutput());

        return $process;
    }

    public function test_valid_profile_is_checked_against_real_configuration_without_provider_io(): void
    {
        $process = $this->validate($this->profile(), $this->profile());
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('PASS runtime.profile', $process->getOutput());
    }

    public static function unsafeProfiles(): array
    {
        return [
            'quoted true' => ['PRODUCTION_CHECKOUT_ENABLED="true"', 'runtime.production_fresh_checkout_enabled'],
            'single-quoted true' => ["PRODUCTION_CHECKOUT_PROVIDER_IO_ENABLED='true'", 'runtime.production_provider_io_enabled'],
            'parenthesized true' => ['PRODUCTION_CHECKOUT_HTTP_ENABLED=(true)', 'runtime.production_http_enabled'],
            'interpolated true' => ["BAD_FLAG=true\nPRODUCTION_CHECKOUT_ENABLED=\"\${BAD_FLAG}\"", 'runtime.production_fresh_checkout_enabled'],
            'duplicates' => ['APP_DEBUG=true', 'runtime.unique_keys'],
            'database URL override' => ['DB_URL=mysql://SyntheticPrivateRuntimeMarker@foreign.invalid/foreign', 'runtime.database'],
            'socket override' => ['DB_SOCKET=/tmp/synthetic-foreign.sock', 'runtime.database'],
            'config cache override' => ['APP_CONFIG_CACHE=/tmp/synthetic-foreign.php', 'runtime.no_cache_override'],
            'production key' => ['PRODUCTION_CHECKOUT_STRIPE_SECRET_KEY=sk_test_'.'SyntheticPrivateRuntimeMarker', 'runtime.production_credentials'],
            'live funds' => ['PRODUCTION_CHECKOUT_FUNDS_MODE=live', 'runtime.production_credentials'],
            'live key value' => ['UNRELATED_KEY=sk_'.'live_'.'SyntheticPrivateRuntimeMarker', 'runtime.no_live_keys'],
        ];
    }

    #[DataProvider('unsafeProfiles')]
    public function test_unsafe_profile_is_refused_without_secret_or_path_projection(string $extra, string $failure): void
    {
        $process = $this->validate($this->profile().$extra."\n");
        $this->assertSame(1, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('FAIL '.$failure, $process->getErrorOutput());
        $this->assertStringNotContainsString('PASS runtime.profile', $process->getOutput());
    }

    public static function stagedFlags(): array
    {
        return array_combine(self::STAGED_FLAGS, array_map(static fn (string $flag): array => [$flag], self::STAGED_FLAGS));
    }

    public function test_the_shared_boundary_lists_exactly_the_twelve_flags(): void
    {
        $this->assertSame(self::STAGED_FLAGS, array_keys(CapabilityBoundary::stagedFlags()));
        $this->assertSame(10, count(CapabilityBoundary::CAPABILITY_FLAGS));
        foreach (self::UNGATED_FLAGS as $flag) {
            $this->assertArrayHasKey($flag, CapabilityBoundary::CAPABILITY_FLAGS);
        }
    }

    public function test_absent_flag_lines_are_allowed_because_every_config_default_is_false(): void
    {
        foreach (self::STAGED_FLAGS as $flag) {
            $this->assertStringNotContainsString($flag, $this->profile());
        }
        $process = $this->validate($this->profile());
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    #[DataProvider('stagedFlags')]
    public function test_each_flag_enabled_is_refused_by_exactly_its_own_check(string $flag): void
    {
        $process = $this->validate($this->profile().$flag."=true\n");
        $this->assertSame(1, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame('FAIL runtime.flag_off_'.strtolower($flag)."\n", $process->getErrorOutput());
        $this->assertStringNotContainsString('PASS runtime.profile', $process->getOutput());
    }

    public static function enabledSpellings(): array
    {
        $spellings = ['true', '"true"', "'true'", '(true)', 'TRUE', 'True', '1', 'yes', 'on', '', '(null)', 'null', 'empty', '"1"', '0', 'no'];

        return array_combine($spellings, array_map(static fn (string $spelling): array => [$spelling], $spellings));
    }

    /**
     * The deploy gate is the only guard on staging for these two. Any value that is not exactly false is refused: not just `true`,
     * but a blank, numeric, "yes" or null value, which the profile does not state and the policy classes never read as intentional.
     */
    #[DataProvider('enabledSpellings')]
    public function test_the_two_flags_no_policy_gates_are_refused_in_every_spelling_but_false(string $spelling): void
    {
        foreach (self::UNGATED_FLAGS as $flag) {
            $process = $this->validate($this->profile().$flag.'='.$spelling."\n");
            $this->assertSame(1, $process->getExitCode(), $flag.'='.$spelling.PHP_EOL.$process->getErrorOutput());
            $this->assertSame('FAIL runtime.flag_off_'.strtolower($flag)."\n", $process->getErrorOutput(), $flag.'='.$spelling);
        }
    }

    public static function falseSpellings(): array
    {
        return ['false' => ['false'], 'parenthesized' => ['(false)'], 'upper case' => ['FALSE'], 'quoted' => ['"false"']];
    }

    #[DataProvider('falseSpellings')]
    public function test_every_flag_stated_false_in_any_accepted_spelling_passes(string $spelling): void
    {
        $lines = array_map(static fn (string $flag): string => $flag.'='.$spelling, self::STAGED_FLAGS);
        $process = $this->validate($this->profile().implode("\n", $lines)."\n");
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('PASS runtime.profile', $process->getOutput());
    }

    public function test_all_twelve_enabled_at_once_are_each_named_once_and_nothing_else_fails(): void
    {
        $lines = array_map(static fn (string $flag): string => $flag.'=true', self::STAGED_FLAGS);
        $process = $this->validate($this->profile().implode("\n", $lines)."\n");
        $this->assertSame(1, $process->getExitCode());
        $expected = array_map(static fn (string $flag): string => 'FAIL runtime.flag_off_'.strtolower($flag), self::STAGED_FLAGS);
        $this->assertSame($expected, array_values(array_filter(explode("\n", $process->getErrorOutput()))));
    }

    public function test_the_capability_flags_are_refused_on_a_staging_environment_as_on_local(): void
    {
        $staging = str_replace('APP_ENV=local', 'APP_ENV=staging', $this->profile());
        $this->assertSame(0, $this->validate($staging, null, 'staging')->getExitCode());
        foreach (self::UNGATED_FLAGS as $flag) {
            $process = $this->validate($staging.$flag."=true\n", null, 'staging');
            $this->assertSame(1, $process->getExitCode(), $flag);
            $this->assertSame('FAIL runtime.flag_off_'.strtolower($flag)."\n", $process->getErrorOutput());
        }
    }

    /** The real configuration passes the lane check: the five named lanes exist and none has an enabled or mounted flag set. */
    public function test_the_real_production_lanes_are_closed_and_named(): void
    {
        $lanes = CapabilityBoundary::productionLanes(dirname(__DIR__, 2).'/config', static fn (string $stem): array => require dirname(__DIR__, 2).'/config/'.$stem.'.php');
        $this->assertSame([], $lanes['open']);
        $this->assertSame([], $lanes['missing']);
        $this->assertGreaterThanOrEqual(16, count($lanes['scanned']));
    }

    /** @param array<string, array<mixed>> $configs */
    private function lanes(array $configs): array
    {
        $directory = $this->directory.'/config-'.bin2hex(random_bytes(4));
        mkdir($directory, 0700);
        foreach (array_keys($configs) as $stem) {
            file_put_contents($directory.'/'.$stem.'.php', '<?php return [];');
        }
        $result = CapabilityBoundary::productionLanes($directory, static fn (string $stem): array => $configs[$stem]);
        array_map('unlink', glob($directory.'/*.php'));
        rmdir($directory);

        return $result;
    }

    private static function closedLanes(): array
    {
        return [
            'paid-grants' => ['rehearsal_enabled' => false, 'operative_enabled' => false, 'http_mounted' => false, 'delivery_policy' => null],
            'paid-tax-grants' => ['rehearsal_enabled' => false, 'operative_enabled' => false, 'http_mounted' => false, 'downloads_enabled' => false],
            'production-tax-checkout' => ['enabled' => false, 'http_mounted' => false, 'committed_read_receipts_enabled' => false],
            'production-customer-identity' => ['enabled' => false, 'notifications_enabled' => false],
            'production-free-grants' => ['enabled' => false],
            'member-grants' => ['enabled' => false, 'authoring_enabled' => false],
            'production_checkout' => ['http_enabled' => false, 'secret_key' => null],
        ];
    }

    public static function openLanes(): array
    {
        return [
            'Paid252 mounted' => ['paid-grants', 'http_mounted', 'paid-grants.http_mounted'],
            'Paid252 operative' => ['paid-grants', 'operative_enabled', 'paid-grants.operative_enabled'],
            'Tax255 rehearsal' => ['paid-tax-grants', 'rehearsal_enabled', 'paid-tax-grants.rehearsal_enabled'],
            'Tax255 downloads' => ['paid-tax-grants', 'downloads_enabled', 'paid-tax-grants.downloads_enabled'],
            'Tax checkout enabled' => ['production-tax-checkout', 'enabled', 'production-tax-checkout.enabled'],
            'Tax checkout receipts' => ['production-tax-checkout', 'committed_read_receipts_enabled', 'production-tax-checkout.committed_read_receipts_enabled'],
            'production identity' => ['production-customer-identity', 'enabled', 'production-customer-identity.enabled'],
            'production identity notices' => ['production-customer-identity', 'notifications_enabled', 'production-customer-identity.notifications_enabled'],
            'production free grants' => ['production-free-grants', 'enabled', 'production-free-grants.enabled'],
            'member grants' => ['member-grants', 'authoring_enabled', 'member-grants.authoring_enabled'],
            'underscore lane' => ['production_checkout', 'http_enabled', 'production_checkout.http_enabled'],
        ];
    }

    #[DataProvider('openLanes')]
    public function test_a_true_enabled_or_mounted_flag_in_any_production_lane_is_reported_by_name(string $stem, string $key, string $reported): void
    {
        $configs = self::closedLanes();
        $this->assertSame(['open' => [], 'missing' => [], 'scanned' => ['member-grants', 'paid-grants', 'paid-tax-grants', 'production-customer-identity',
            'production-free-grants', 'production-tax-checkout', 'production_checkout']], $this->lanes($configs));
        $configs[$stem][$key] = true;
        $lanes = $this->lanes($configs);
        $this->assertSame([$reported], $lanes['open']);
        $this->assertSame([], $lanes['missing']);
    }

    public function test_lane_flags_nested_deeper_in_a_lane_file_are_found(): void
    {
        $configs = self::closedLanes();
        $configs['production-free-grants']['grants'] = ['issuance' => ['enabled' => true]];
        $this->assertSame(['production-free-grants.grants.issuance.enabled'], $this->lanes($configs)['open']);
    }

    public function test_only_a_strict_true_in_an_enabled_or_mounted_key_of_a_lane_file_counts(): void
    {
        $configs = self::closedLanes();
        $configs['paid-grants']['http_mounted'] = 'true';          // policy classes compare with === true; a string is inert
        $configs['paid-grants']['operative_enabled'] = 1;
        $configs['paid-grants']['delivery_policy'] = true;         // not an enabled or mounted key
        $configs['free-grants'] = ['test_enabled' => true];        // not a lane file: the capability flags cover it
        $configs['services'] = ['enabled' => true];
        $configs['productions'] = ['enabled' => true];             // no separator after "production": not a lane
        $this->assertSame([], $this->lanes($configs)['open']);
    }

    public static function namedLanes(): array
    {
        return array_combine(CapabilityBoundary::NAMED_PRODUCTION_LANES, array_map(static fn (string $lane): array => [$lane], CapabilityBoundary::NAMED_PRODUCTION_LANES));
    }

    #[DataProvider('namedLanes')]
    public function test_a_renamed_or_removed_named_lane_is_reported_missing(string $lane): void
    {
        $configs = self::closedLanes();
        unset($configs[$lane]);
        $lanes = $this->lanes($configs);
        $this->assertSame([$lane], $lanes['missing']);
        $this->assertSame([], $lanes['open']);
    }

    public static function notOff(): array
    {
        return ['true' => [true], 'null (renamed or removed key)' => [null], 'blank' => [''], 'zero' => [0], 'one' => [1],
            'the string false' => ['false'], 'empty array' => [[]]];
    }

    #[DataProvider('notOff')]
    public function test_a_flag_whose_effective_value_is_not_exactly_false_is_reported(mixed $value): void
    {
        foreach (CapabilityBoundary::stagedFlags() as $environmentKey => $configKey) {
            $reader = static fn (string $key): mixed => $key === $configKey ? $value : false;
            $this->assertSame([$environmentKey], CapabilityBoundary::flagsNotOff($reader), $environmentKey);
        }
        $this->assertSame([], CapabilityBoundary::flagsNotOff(static fn (string $key): bool => false));
    }

    public function test_a_byte_order_mark_is_refused_with_its_own_clear_check(): void
    {
        $process = $this->validate("\xEF\xBB\xBF".$this->profile());
        $this->assertSame(1, $process->getExitCode());
        $this->assertSame("FAIL runtime.byte_order_mark\nFAIL runtime.unavailable\n", $process->getErrorOutput());
        // A mark anywhere else is not the case this names; the clean profile still passes.
        $this->assertSame(0, $this->validate($this->profile())->getExitCode());
    }

    public static function cliBinaries(): array
    {
        return [
            'unset' => [''],
            'relative' => ['php8.4'],
            'missing' => ['/nonexistent/synthetic/php8.4'],
            'not executable' => ['/etc/hostname'],
        ];
    }

    /** M-16: a renderer child started inside an FPM request needs a genuine CLI PHP of the running version. */
    #[DataProvider('cliBinaries')]
    public function test_an_unusable_cli_binary_for_renderer_children_is_refused(string $binary): void
    {
        $process = $this->validate(str_replace('VASEY_PHP_CLI_BINARY='.PHP_BINARY, 'VASEY_PHP_CLI_BINARY='.$binary, $this->profile()));
        $this->assertSame(1, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame("FAIL runtime.php_cli_binary\n", $process->getErrorOutput());
    }

    public function test_replacing_the_served_key_is_refused_before_configuration_changes(): void
    {
        $candidate = str_replace(str_repeat('A', 43), str_repeat('B', 43), $this->profile());
        $process = $this->validate($candidate, $this->profile());
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('FAIL runtime.key_custody', $process->getErrorOutput());
    }
}
