<?php

namespace Tests\Feature;

use App\Domain\Media\BoundedMediaProcess;
use App\Domain\Media\MediaFailure;
use App\Support\Diagnostics\InstallationReport;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

class InstallationReportTest extends TestCase
{
    use RefreshDatabase;

    /** Actual six-flag encoder rows; descriptions alone must never establish a capability. */
    private const ENCODER_ROWS = [
        'libmp3lame' => ' A....D libmp3lame           libmp3lame MP3 (codec mp3)',
        'pcm_s16le' => ' A....D pcm_s16le            PCM signed 16-bit little-endian',
        'png' => ' VF...D png                  PNG (Portable Network Graphics) image',
        'mjpeg' => ' VFS... mjpeg                MJPEG (Motion JPEG)',
        'libwebp' => ' V....D libwebp              libwebp WebP image (codec webp)',
    ];

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
        $exit = Artisan::call('vasey:doctor', ['--json' => true]);
        $output = Artisan::output();
        $this->assertSame(0, $exit, $output);
        $report = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertTrue($report['foundation_ready']);
        $this->assertSame(1, $report['schema_version']);
        $status = array_column($report['checks'], 'status', 'id');
        foreach (['media_tools', 'media_encoders', 'media_scanner', 'media_scanner_limits', 'seller_tag', 'media_queue', 'mail_transport'] as $id) {
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
        $this->assertStringNotContainsString($key, $output);
        $this->assertStringNotContainsString($this->directory, $output);
    }

    /** Executes the real bounded runner; only FFmpeg's capability listing and failure modes are scripted. */
    private function encoderListing(array $rows = self::ENCODER_ROWS, string $mode = 'ok'): void
    {
        $script = <<<'SH'
#!/bin/sh
directory=$(dirname "$0")
printf '%s\n' "$@" > "$directory/encoder-argv"
cat "$directory/encoder-list"
case "$(cat "$directory/encoder-mode")" in
    fail) printf 'PRIVATE-ENCODER-TOKEN in %s\n' "$directory" >&2; exit 2 ;;
    flood) yes PRIVATE-ENCODER-TOKEN | head -c 300000 ;;
esac
SH;
        file_put_contents($this->directory.'/ffmpeg', $script."\n");
        chmod($this->directory.'/ffmpeg', 0700);
        file_put_contents($this->directory.'/encoder-list', "Encoders:\n V..... = Video\n A..... = Audio\n S..... = Subtitle\n ------\n".implode("\n", $rows)."\n");
        file_put_contents($this->directory.'/encoder-mode', $mode);
        config(['media.ffmpeg' => $this->directory.'/ffmpeg', 'media.prlimit' => '/usr/bin/prlimit']);
    }

    public function test_required_media_encoders_are_reported_without_mutating_the_installation(): void
    {
        $this->encoderListing();
        LicenseFixtures::admin();
        $key = config('app.key');
        DB::enableQueryLog();
        $exit = Artisan::call('vasey:doctor', ['--json' => true]);
        $output = Artisan::output();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $report = json_decode($output, true, 32, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, $output);
        $this->assertTrue($report['foundation_ready']);
        $this->assertSame('pass', array_column($report['checks'], 'status', 'id')['media_encoders']);
        $this->assertSame(['-hide_banner', '-encoders'], file($this->directory.'/encoder-argv', FILE_IGNORE_NEW_LINES));
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/\A\s*(insert|update|delete|create|alter|drop|replace)\b/i', $query['query']);
        }
        $this->assertSame($key, config('app.key'));
        $this->assertDatabaseCount('audit_events', 0);
        $this->assertSame([], (new Filesystem)->allFiles($this->directory.'/private'));
        $this->assertDirectoryDoesNotExist($this->directory.'/private/processing');
        $this->assertStringNotContainsString($key, $output);
        $this->assertStringNotContainsString($this->directory, $output);
    }

    public static function missingRequiredEncoders(): array
    {
        return array_combine(array_keys(self::ENCODER_ROWS), array_map(fn (string $encoder): array => [$encoder], array_keys(self::ENCODER_ROWS)));
    }

    #[DataProvider('missingRequiredEncoders')]
    public function test_each_missing_required_encoder_is_an_optional_warning(string $encoder): void
    {
        $rows = self::ENCODER_ROWS;
        unset($rows[$encoder]);
        $this->encoderListing($rows);
        LicenseFixtures::admin();
        $report = app(InstallationReport::class)->collect();

        $this->assertTrue($report['foundation_ready']);
        $this->assertSame('warn', array_column($report['checks'], 'status', 'id')['media_encoders']);
    }

    public static function misleadingEncoderRows(): array
    {
        return [
            'description only' => [' A....D alternative          Uses libmp3lame internally'],
            'identifier prefix' => [' A....D libmp3lame_extra     Different encoder'],
            'punctuation suffix' => [' A....D libmp3lame!          Not an exact identifier'],
            'unanchored row' => ['Warning: A....D libmp3lame   Not an encoder row'],
            'too many flags' => [' A....DX libmp3lame          Seven flags'],
            'wrong flag alphabet' => [' A----D libmp3lame           Invalid flags'],
            'wrong flag position' => [' AD.... libmp3lame           Invalid flag position'],
        ];
    }

    #[DataProvider('misleadingEncoderRows')]
    public function test_descriptions_prefixes_and_malformed_rows_cannot_confirm_an_encoder(string $replacement): void
    {
        $rows = self::ENCODER_ROWS;
        $rows['libmp3lame'] = $replacement;
        $this->encoderListing($rows);

        $this->assertSame('warn', $this->statuses()['media_encoders']);
    }

    public static function missingEncoderTools(): array
    {
        return ['ffmpeg missing' => ['media.ffmpeg'], 'prlimit missing' => ['media.prlimit']];
    }

    #[DataProvider('missingEncoderTools')]
    public function test_encoder_diagnostics_do_not_run_without_the_executable_and_limiter(string $key): void
    {
        $this->encoderListing();
        config([$key => '/missing-PRIVATE-ENCODER-TOKEN']);

        $this->assertSame('warn', $this->statuses()['media_encoders']);
        $this->assertFileDoesNotExist($this->directory.'/encoder-argv');
    }

    public static function failedEncoderListings(): array
    {
        return ['nonzero exit despite complete listing' => ['fail'], 'captured output limit' => ['flood']];
    }

    #[DataProvider('failedEncoderListings')]
    public function test_failed_bounded_encoder_commands_warn_without_exposing_their_output(string $mode): void
    {
        $this->encoderListing(mode: $mode);
        LicenseFixtures::admin();
        $this->assertSame(0, Artisan::call('vasey:doctor', ['--json' => true]));
        $output = Artisan::output();
        $report = json_decode($output, true, 32, JSON_THROW_ON_ERROR);

        $this->assertTrue($report['foundation_ready']);
        $this->assertSame('warn', array_column($report['checks'], 'status', 'id')['media_encoders']);
        $this->assertStringNotContainsString('PRIVATE-ENCODER-TOKEN', $output);
        $this->assertStringNotContainsString($this->directory, $output);
        $this->assertSame([], (new Filesystem)->allFiles($this->directory.'/private'));
        $this->assertDirectoryDoesNotExist($this->directory.'/private/processing');
    }

    public function test_encoder_command_timeout_is_bounded_and_remains_a_redacted_optional_warning(): void
    {
        $this->encoderListing();
        LicenseFixtures::admin();
        $runner = new class extends BoundedMediaProcess
        {
            public array $calls = [];

            public function run(array $arguments, string $cwd, int $timeout = 0, bool $ignoreErrorOutput = false): string
            {
                $this->calls[] = [$arguments, $cwd, $timeout, $ignoreErrorOutput];
                throw new MediaFailure('processor_timeout', 'PRIVATE-ENCODER-TOKEN timed out.');
            }
        };
        app()->instance(BoundedMediaProcess::class, $runner);
        $this->assertSame(0, Artisan::call('vasey:doctor', ['--json' => true]));
        $output = Artisan::output();
        $report = json_decode($output, true, 32, JSON_THROW_ON_ERROR);

        $this->assertTrue($report['foundation_ready']);
        $this->assertSame('warn', array_column($report['checks'], 'status', 'id')['media_encoders']);
        $this->assertSame([[[$this->directory.'/ffmpeg', '-hide_banner', '-encoders'], base_path(), 15, false]], $runner->calls);
        $this->assertStringNotContainsString('PRIVATE-ENCODER-TOKEN', $output);
        $this->assertStringNotContainsString($this->directory, $output);
        $this->assertFileDoesNotExist($this->directory.'/encoder-argv');
    }

    public function test_encoder_diagnostics_disable_inherited_ffmpeg_reports_without_changing_the_parent_environment(): void
    {
        LicenseFixtures::admin();
        config(['media.ffmpeg' => '/usr/bin/ffmpeg', 'media.prlimit' => '/usr/bin/prlimit']);
        $path = $this->directory.'/unexpected-ffmpeg-report.log';
        $previous = getenv('FFREPORT');
        $inherited = 'file='.$path.':level=32';
        putenv('FFREPORT='.$inherited);
        try {
            $this->assertSame(0, Artisan::call('vasey:doctor', ['--json' => true]));
            $output = Artisan::output();
            $report = json_decode($output, true, 32, JSON_THROW_ON_ERROR);

            $this->assertSame('pass', array_column($report['checks'], 'status', 'id')['media_encoders']);
            $this->assertFileDoesNotExist($path);
            $this->assertSame($inherited, getenv('FFREPORT'));
            $this->assertStringNotContainsString($this->directory, $output);
            $this->assertStringNotContainsString('FFREPORT', $output);
        } finally {
            putenv($previous === false ? 'FFREPORT' : 'FFREPORT='.$previous);
        }
    }

    /**
     * A scripted scanner that prints a version and answers every file the way $answer says: refuse, as a daemon that alerts does, or
     * skip. The paths it is asked to scan, other than for its version, are noted in the file asked, beside the script.
     */
    private function scanner(string $name, string $answer = 'skip'): void
    {
        $script = $this->directory.'/'.$name;
        $verdict = $answer === 'refuse' ? 'echo "$path: Heuristics.Limits.Exceeded.MaxFileSize FOUND"; exit 1' : 'echo "$path: OK"';
        file_put_contents($script, "#!/bin/sh\nfor arg; do path=\$arg; done\nif [ \"\$1\" = --version ]; then echo 'ClamAV 1.5.4'; exit 0; fi\nprintf '%s\\n' \"\$path\" >> \"\$(dirname \"\$0\")/asked\"\n".$verdict."\n");
        chmod($script, 0700);
        config(['media.clamscan' => $script, 'media.prlimit' => '/usr/bin/prlimit']);
    }

    /** The paths the scripted scanner was asked to scan. */
    private function asked(): array
    {
        return is_file($this->directory.'/asked') ? file($this->directory.'/asked', FILE_IGNORE_NEW_LINES) : [];
    }

    public function test_a_daemon_that_alerts_on_files_over_its_limits_passes_and_leaves_nothing_behind(): void
    {
        $this->scanner('clamdscan', 'refuse');

        $status = $this->statuses();

        $this->assertSame('pass', $status['media_scanner_limits']);
        // The canary was made where scans make theirs, in a workspace of private storage, and not in the temporary directory.
        $asked = $this->asked();
        $this->assertCount(1, $asked);
        $this->assertMatchesRegularExpression('~\A'.preg_quote(realpath($this->directory.'/private'), '~').'/processing/[0-9a-f-]{36}/\.limit-canary-[0-9a-f]{16}\z~', $asked[0]);
        // It is gone, with its workspace and the processing directory that the doctor had to make: no trace, for a worker to trip over.
        $this->assertFileDoesNotExist($asked[0]);
        $this->assertDirectoryDoesNotExist($this->directory.'/private/processing');
    }

    public function test_a_processing_directory_that_was_there_stays_and_its_workspace_does_not(): void
    {
        $this->scanner('clamdscan', 'refuse');
        mkdir($this->directory.'/private/processing', 0700);

        $this->assertSame('pass', $this->statuses()['media_scanner_limits']);

        $this->assertDirectoryExists($this->directory.'/private/processing');
        // Empty, hidden files included: the workspace and the canary in it are gone.
        $this->assertSame([], array_values(array_diff(scandir($this->directory.'/private/processing'), ['.', '..'])));
        $this->assertCount(1, $this->asked());
    }

    public function test_the_scanner_limits_check_makes_nothing_where_private_storage_does_not_exist(): void
    {
        $this->scanner('clamdscan', 'refuse');
        // A directory that the doctor made would belong to whoever ran it, and the worker could not use it.
        config(['filesystems.disks.local.root' => $this->directory.'/not-there']);

        $this->assertSame('warn', $this->statuses()['media_scanner_limits']);

        $this->assertDirectoryDoesNotExist($this->directory.'/not-there');
        $this->assertSame([], $this->asked());
    }

    public static function usersWhoDoNotOwnPrivateStorage(): array
    {
        return [
            // Root under sudo, for one, on the storage of the worker's user.
            'another user' => [fn (int $owner): ?int => $owner + 1],
            'a user that cannot be told, for want of the posix extension' => [fn (int $owner): ?int => null],
        ];
    }

    #[DataProvider('usersWhoDoNotOwnPrivateStorage')]
    public function test_the_scanner_limits_check_makes_nothing_for_a_user_who_does_not_own_private_storage(\Closure $user): void
    {
        $this->scanner('clamdscan', 'refuse');
        // A run that a signal ends skips its cleanup, and what it leaves belongs to whoever ran it, so a worker of another user could
        // not make its own workspace in a processing/ that root made: the check is not run, and leaves nothing to clean up.
        $owner = fileowner($this->directory.'/private');
        app()->instance(InstallationReport::class, new InstallationReport(effectiveUserId: fn () => $user($owner)));

        $check = collect(app(InstallationReport::class)->collect()['checks'])->firstWhere('id', 'media_scanner_limits');

        $this->assertSame('warn', $check['status']);
        $this->assertStringContainsString('Run vasey:doctor as the user that owns private storage, the worker\'s.', $check['message']);
        $this->assertSame([], $this->asked());
        $this->assertSame(['.', '..'], scandir($this->directory.'/private'));
    }

    public function test_a_workspace_that_cannot_be_made_leaves_no_processing_directory_that_the_check_made(): void
    {
        $this->scanner('clamdscan', 'refuse');
        // The workspace is made below processing/, which the check has just made, and a name whose parent is not there stands for a
        // directory that cannot be made (a full disk, a quota): the check must not leave a processing/ that it made for nothing.
        Str::createUuidsUsing(fn () => 'no/such/parent');
        try {
            $status = $this->statuses()['media_scanner_limits'];
        } finally {
            Str::createUuidsNormally();
        }

        $this->assertSame('warn', $status);
        $this->assertDirectoryDoesNotExist($this->directory.'/private/processing');
        $this->assertSame([], $this->asked());
    }

    public function test_a_daemon_that_skips_files_over_its_limits_is_a_warning_that_names_the_fix(): void
    {
        $this->scanner('clamdscan', 'skip');

        $check = collect(app(InstallationReport::class)->collect()['checks'])->firstWhere('id', 'media_scanner_limits');

        $this->assertSame('warn', $check['status']);
        $this->assertStringContainsString('MaxFileSize and MaxScanSize to 1280M and AlertExceedsMax yes', $check['message']);
        $this->assertCount(1, $this->asked());
        $this->assertFileDoesNotExist($this->asked()[0]);
        $this->assertDirectoryDoesNotExist($this->directory.'/private/processing');
    }

    public function test_clamscan_carries_its_own_limits_and_passes_the_scanner_limits_check(): void
    {
        $this->scanner('clamscan');

        $this->assertSame('pass', $this->statuses()['media_scanner_limits']);
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
