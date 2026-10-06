<?php

namespace Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class BulkLicenseDraftSourceBrowserEvidenceTest extends TestCase
{
    private string $directory;

    private array $environment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = realpath(sys_get_temp_dir()).'/vasey-browser-'.bin2hex(random_bytes(12));
        mkdir($this->directory, 0700);
        foreach (['framework/views', 'framework/sessions', 'framework/cache/data', 'logs', 'app/private'] as $child) {
            mkdir($this->directory.'/'.$child, 0700, true);
        }
        touch($this->directory.'/database.sqlite');
        chmod($this->directory.'/database.sqlite', 0600);
        $this->environment = [
            'APP_ENV' => 'local', 'APP_DEBUG' => 'false', 'APP_URL' => 'http://127.0.0.1:8173', 'ASSET_URL' => '',
            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'APP_CONFIG_CACHE' => $this->directory.'/config.php',
            'APP_ROUTES_CACHE' => $this->directory.'/routes.php', 'APP_EVENTS_CACHE' => $this->directory.'/events.php',
            'LARAVEL_STORAGE_PATH' => $this->directory, 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $this->directory.'/database.sqlite',
            'DB_URL' => '', 'DB_FOREIGN_KEYS' => 'true', 'SESSION_DRIVER' => 'file', 'SESSION_ENCRYPT' => 'true',
            'CACHE_STORE' => 'file', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'LOG_CHANNEL' => 'single',
            'FILESYSTEM_DISK' => 'local', 'STRIPE_MODE' => 'test', 'STRIPE_ACCOUNT_ID' => 'acct_SYNTHETICONLY',
            'STRIPE_WEBHOOK_ENABLED' => 'false', 'STRIPE_TEST_CHECKOUT_ENABLED' => 'false',
            'STRIPE_TEST_PAYMENT_PROCESSING_ENABLED' => 'false', 'STRIPE_TEST_FINALIZATION_ENABLED' => 'false',
            'STRIPE_TEST_SECRET_KEY' => '', 'STRIPE_WEBHOOK_SECRET' => '', 'VASEY_TEST_UNPAID_RELEASE_ENABLED' => 'false',
            'VASEY_TEST_UNPAID_RELEASE_POLICY' => '', 'VASEY_BROWSER_DIRECTORY' => $this->directory,
            'VASEY_BROWSER_EXCEPTION_MARKER' => bin2hex(random_bytes(32)),
        ];
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_actual_helper_checks_every_selected_row_audit_and_unchanged_purchased_graph(): void
    {
        $this->worker('seed');
        $prepared = $this->helper('prepare');
        $this->assertCount(2, $prepared['versionIds']);
        $this->assertGreaterThan($prepared['versionIds'][1], $prepared['unselectedId']);
        $path = $this->directory.'/bulk-license-source-chromium-desktop.json';
        $this->assertSame(0600, fileperms($path) & 0777);
        $manifest = file_get_contents($path);
        $before = hash_file('sha256', $this->directory.'/database.sqlite');
        $this->assertSame(0, $this->helper('verify', 'prepared')['updates']);
        $this->assertSame($before, hash_file('sha256', $this->directory.'/database.sqlite'));
        $this->assertRefused($this->process('prepare'));
        $this->assertSame($manifest, file_get_contents($path));
        foreach (['winner' => 1, 'recovered' => 3, 'uncertain' => 5] as $phase => $count) {
            $this->worker($phase);
            $receipt = $this->helper('verify', $phase);
            $this->assertSame($count, $receipt['updates']);
            $this->assertSame($prepared['versionIds'], $receipt['versionIds']);
            $this->assertCount($count, $receipt['auditIds']);
            $this->assertTrue($receipt['originalsUnchanged']);
            $this->assertTrue($receipt['guardsUnchanged']);
            $before = hash_file('sha256', $this->directory.'/database.sqlite');
            $this->assertSame($receipt, $this->helper('verify', $phase));
            $this->assertSame($before, hash_file('sha256', $this->directory.'/database.sqlite'));
            if ($phase === 'recovered') {
                $this->worker('noop');
                $this->assertSame($before, hash_file('sha256', $this->directory.'/database.sqlite'));
                $this->assertSame($receipt, $this->helper('verify', $phase));
            }
        }
        $this->worker('unrelated');
        $changed = hash_file('sha256', $this->directory.'/database.sqlite');
        $this->assertRefused($this->process('verify', 'uncertain'));
        $this->assertSame($changed, hash_file('sha256', $this->directory.'/database.sqlite'));
        $this->assertSame($manifest, file_get_contents($path));
    }

    public function test_actual_helper_rejects_extra_audit_context_without_readback_effects_or_private_text(): void
    {
        $this->worker('seed');
        $this->helper('prepare');
        foreach (['winner', 'recovered', 'uncertain'] as $phase) {
            $this->worker($phase);
        }
        $this->assertSame(5, $this->helper('verify', 'uncertain')['updates']);
        $this->worker('audit-canary');
        $before = hash_file('sha256', $this->directory.'/database.sqlite');
        $this->assertRefused($this->process('verify', 'uncertain'));
        $this->assertSame($before, hash_file('sha256', $this->directory.'/database.sqlite'));
    }

    public function test_complete_selected_row_receipt_detects_a_timestamp_only_write_for_native_noop_and_replay_comparisons(): void
    {
        $this->worker('seed');
        $this->helper('prepare');
        foreach (['winner', 'recovered'] as $phase) {
            $this->worker($phase);
        }
        $before = $this->helper('verify', 'recovered');
        $this->assertCount(2, $before['rowHashes']);
        foreach ($before['rowHashes'] as $hash) {
            $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $hash);
        }
        $this->worker('timestamp-canary');
        $after = $this->helper('verify', 'recovered');
        $this->assertNotSame($before['rowHashes'][0], $after['rowHashes'][0]);
        $this->assertSame($before['rowHashes'][1], $after['rowHashes'][1]);
        unset($before['rowHashes'], $after['rowHashes']);
        $this->assertSame($before, $after);
    }

    public function test_unsafe_selection_configuration_and_symlinks_refuse_without_effects_or_private_diagnostics(): void
    {
        $this->worker('seed');
        $before = hash_file('sha256', $this->directory.'/database.sqlite');
        foreach ([['APP_ENV' => 'production'], ['APP_DEBUG' => 'true'], ['DB_URL' => 'sqlite:///private-canary'],
            ['APP_URL' => 'https://private-canary.invalid'], ['STRIPE_TEST_SECRET_KEY' => 'private-canary'],
            ['VASEY_BROWSER_EXCEPTION_MARKER' => str_repeat('A', 64)], ['VASEY_TEST_UNPAID_RELEASE_ENABLED' => 'true']] as $changes) {
            $this->assertRefused($this->process('prepare', overrides: $changes));
            $this->assertSame($before, hash_file('sha256', $this->directory.'/database.sqlite'));
        }
        $this->assertRefused($this->process('prepare', project: 'private-canary'));
        $this->assertRefused($this->process('verify', 'private-canary'));
        $this->assertFileDoesNotExist($this->directory.'/bulk-license-source-chromium-desktop.json');
        $target = $this->directory.'/retained-canary.json';
        file_put_contents($target, 'private-canary');
        chmod($target, 0600);
        symlink($target, $this->directory.'/bulk-license-source-chromium-desktop.json');
        $this->assertRefused($this->process('prepare'));
        $this->assertSame('private-canary', file_get_contents($target));
        $this->assertTrue(is_link($this->directory.'/bulk-license-source-chromium-desktop.json'));
        $this->assertSame($before, hash_file('sha256', $this->directory.'/database.sqlite'));
    }

    private function assertRefused(Process $process): void
    {
        $this->assertSame(1, $process->getExitCode());
        $this->assertSame('', $process->getOutput());
        $this->assertSame("Isolated bulk-license source evidence refused.\n", $process->getErrorOutput());
    }

    private function helper(string $mode, ?string $phase = null): array
    {
        $process = $this->process($mode, $phase);
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        return json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
    }

    private function process(string $mode, ?string $phase = null, array $overrides = [], string $project = 'chromium-desktop'): Process
    {
        $process = new Process([PHP_BINARY, 'tests/browser/prepare-bulk-license-draft-source.php', $mode, $project, ...($phase === null ? [] : [$phase])],
            dirname(__DIR__, 2), array_replace($this->environment, $overrides), null, 30);
        $process->run();

        return $process;
    }

    private function worker(string $mode): void
    {
        // Deliberately synthetic provider/renderer/scanner inputs. Native bootstrap still requires genuine ClamAV.
        $source = <<<'PHP'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app->detectEnvironment(fn () => 'testing');
$directory = getenv('VASEY_BROWSER_DIRECTORY');
if ($argv[1] === 'seed') {
    if (Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]) !== 0) throw new RuntimeException('Synthetic migration failed.');
    $actor = App\Models\User::factory()->create(['name' => 'Synthetic Browser Operator', 'email' => 'browser-operator@example.test',
        'is_admin' => true, 'email_verified_at' => now()]);
    $actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
    $customer = App\Models\User::factory()->create(['is_admin' => false, 'email_verified_at' => now()]);
    Tests\Support\CustomerFixtures::configure();
    app(App\Domain\Customers\CustomerAccounts::class)->provision($customer);
    $fixture = Tests\Support\CustomerFixtures::ready($customer, 'BULKSOURCEEVIDENCE', true);
    $files = ['fixtures.json' => ['chromium-desktop' => [], 'webkit-mobile' => []],
        'customer-fixtures.json' => ['projects' => ['chromium-desktop' => ['orderId' => $fixture['order']->public_id]]],
        'exception-inspection-fixture-marker.json' => ['purpose' => 'retained-exception-native',
            'marker' => getenv('VASEY_BROWSER_EXCEPTION_MARKER'), 'database' => $directory.'/database.sqlite',
            'origin' => 'http://127.0.0.1:8173', 'baseOperatorId' => 1, 'account' => 'acct_SYNTHETICONLY']];
    foreach ($files as $name => $data) {
        if (file_exists($directory.'/'.$name)) throw new RuntimeException('Synthetic evidence already exists.');
        file_put_contents($directory.'/'.$name, json_encode($data, JSON_THROW_ON_ERROR));
        chmod($directory.'/'.$name, 0600);
    }
} elseif ($argv[1] === 'unrelated') {
    App\Models\User::where('id', 2)->update(['name' => 'Synthetic unrelated change']);
} else {
    $evidence = json_decode(file_get_contents($directory.'/bulk-license-source-chromium-desktop.json'), true, 64, JSON_THROW_ON_ERROR);
    $actor = App\Models\User::findOrFail(1);
    $versions = array_map(fn ($id) => App\Domain\Rights\Models\LicenseVersion::findOrFail($id), $evidence['versionIds']);
    if ($argv[1] === 'winner') {
        $command = app(App\Domain\Rights\ReviewedLicenseDraft::class);
        $review = $command->review($versions[0], $actor);
        $data = array_intersect_key($review['display'], array_flip(App\Domain\Rights\ReviewedLicenseDraft::FIELDS));
        $command->updateReviewed($review, [...$data, 'authored_source' => $evidence['sources']['winner']], $actor);
    } elseif ($argv[1] === 'timestamp-canary') {
        Illuminate\Support\Facades\DB::table('license_versions')->where('id', $evidence['versionIds'][0])->update(['updated_at' => '2040-01-02 03:04:05']);
    } elseif ($argv[1] === 'audit-canary') {
        $audit = App\Support\Audit\AuditEvent::where('action', 'rights.license.draft_source_bulk_updated')->firstOrFail();
        Illuminate\Support\Facades\DB::table('audit_events')->where('id', $audit->id)->update(['context' => json_encode([...$audit->context, 'private-canary' => 'PRIVATE-SOURCE-CANARY'], JSON_THROW_ON_ERROR)]);
    } else {
        $command = app(App\Domain\Rights\BulkReplaceLicenseDraftSource::class);
        $phase = $argv[1] === 'noop' ? 'recovered' : $argv[1];
        $review = $command->review($versions, $evidence['sources'][$phase], $actor);
        $result = $command->applyReviewed($review, $actor);
        $expected = $argv[1] === 'noop' ? ['changed_ids' => [], 'unchanged_ids' => $evidence['versionIds']]
            : ['changed_ids' => $evidence['versionIds'], 'unchanged_ids' => []];
        if ($result !== $expected) throw new RuntimeException('Unexpected synthetic batch result.');
    }
}
PHP;
        $process = new Process([PHP_BINARY, '-r', $source, $mode], dirname(__DIR__, 2), $this->environment, null, 30);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
    }
}
