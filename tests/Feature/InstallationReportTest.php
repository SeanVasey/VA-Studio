<?php

namespace Tests\Feature;

use App\Support\Diagnostics\InstallationReport;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

class InstallationReportTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = storage_path('framework/testing/doctor-'.Str::uuid());
        $files = new Filesystem;
        $files->makeDirectory($this->directory.'/public/build/assets', 0700, true);
        $files->makeDirectory($this->directory.'/private', 0700, true);
        file_put_contents($this->directory.'/public/build/assets/app.js', '// synthetic build fixture');
        file_put_contents($this->directory.'/public/build/manifest.json', json_encode(['resources/js/app.tsx' => ['file' => 'assets/app.js']]));
        $this->app->usePublicPath($this->directory.'/public');
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'filesystems.disks.local.root' => $this->directory.'/private',
            'media.ffmpeg' => '/missing-synthetic-ffmpeg', 'media.ffprobe' => '/missing-synthetic-ffprobe',
            'media.prlimit' => '/missing-synthetic-prlimit', 'media.clamscan' => '/missing-synthetic-scanner',
            'media.tag_path' => null, 'media.tag_sha256' => null, 'mail.default' => 'log', 'queue.default' => 'sync',
        ]);
        Http::preventStrayRequests();
        $this->beforeApplicationDestroyed(fn () => $files->deleteDirectory($this->directory));
    }

    private function statuses(): array
    {
        return array_column(app(InstallationReport::class)->collect()['checks'], 'status', 'id');
    }

    public function test_missing_optional_configuration_is_actionable_without_failing_the_foundation_or_mutating_it(): void
    {
        LicenseFixtures::admin();
        $key = config('app.key');
        DB::enableQueryLog();
        $this->assertSame(0, Artisan::call('vasey:doctor', ['--json' => true]));
        $report = json_decode(Artisan::output(), true, 32, JSON_THROW_ON_ERROR);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertTrue($report['foundation_ready']);
        $this->assertSame(1, $report['schema_version']);
        $status = array_column($report['checks'], 'status', 'id');
        foreach (['media_tools', 'media_scanner', 'seller_tag', 'media_queue', 'mail_transport'] as $id) {
            $this->assertSame('warn', $status[$id]);
        }
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/\A\s*(insert|update|delete|create|alter|drop|replace)\b/i', $query['query']);
        }
        $this->assertSame($key, config('app.key'));
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('audit_events', 0);
        $this->assertDirectoryIsReadable($this->directory.'/private');
        $this->assertSame([], (new Filesystem)->files($this->directory.'/private'));
        $this->assertStringNotContainsString($key, Artisan::output());
        $this->assertStringNotContainsString($this->directory, Artisan::output());
    }

    public function test_missing_key_operator_and_build_fail_without_automatic_repairs(): void
    {
        config(['app.key' => '']);
        unlink($this->directory.'/public/build/assets/app.js');
        $this->artisan('vasey:doctor')->expectsOutputToContain('vasey:create-admin')->assertExitCode(1);
        $status = $this->statuses();
        foreach (['application_key', 'operator', 'frontend_build'] as $id) {
            $this->assertSame('fail', $status[$id]);
        }
        $this->assertSame('', config('app.key'));
        $this->assertDatabaseCount('users', 0);
        $this->assertFileDoesNotExist($this->directory.'/public/build/assets/app.js');
    }

    public function test_pending_migrations_and_public_media_storage_are_reported_as_failures(): void
    {
        LicenseFixtures::admin();
        DB::table('migrations')->where('migration', '2026_09_09_000008_track_metadata_and_public_urls')->delete();
        config(['filesystems.disks.local.root' => public_path('build')]);
        $status = $this->statuses();
        $this->assertSame('fail', $status['migrations']);
        $this->assertSame('fail', $status['private_storage']);
    }

    public function test_database_failures_do_not_expose_credentials_or_connection_details(): void
    {
        config(['database.connections.doctor_unavailable' => ['driver' => 'sqlite', 'database' => '/PRIVATE-PASSWORD/database.sqlite', 'prefix' => '', 'foreign_key_constraints' => true]]);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('doctor_unavailable');
        try {
            $this->assertSame(1, Artisan::call('vasey:doctor', ['--json' => true]));
            $output = Artisan::output();
            $report = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
            $this->assertSame('fail', array_column($report['checks'], 'status', 'id')['database']);
            $this->assertStringNotContainsString('PRIVATE-PASSWORD', $output);
            $this->assertStringNotContainsString('SQLSTATE', $output);
        } finally {
            DB::purge('doctor_unavailable');
            DB::setDefaultConnection($original);
        }
    }

    public function test_production_settings_require_https_secure_cookies_and_debug_off(): void
    {
        $this->app->instance('env', 'production');
        config(['app.debug' => true, 'app.url' => 'http://example.test', 'session.secure' => false]);
        $this->assertSame('fail', $this->statuses()['production_settings']);
        config(['app.debug' => false, 'app.url' => 'https://example.test', 'session.secure' => true]);
        $this->assertSame('pass', $this->statuses()['production_settings']);
    }
}
