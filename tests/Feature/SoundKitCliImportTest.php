<?php

namespace Tests\Feature;

use App\Console\Commands\SoundKitImport;
use App\Domain\SoundKits\Models\SoundKitDraft;
use App\Domain\SoundKits\Models\SoundKitRevision;
use App\Domain\SoundKits\SoundKitCliImport;
use App\Domain\SoundKits\SoundKitCliImportRefusal;
use App\Domain\SoundKits\SoundKitDrafts;
use App\Domain\SoundKits\SoundKitIntake;
use App\Domain\SoundKits\SoundKitProcessor;
use App\Jobs\ProcessSoundKit;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Closure;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\Support\StemsFixtures;
use Tests\Support\TestOnlyMediaScanner;
use Tests\TestCase;

/**
 * `vasey:sound-kit-import`: admission, actor and path guards before any copy or write, and a real run through the unchanged
 * kit intake and processor that must produce the same revision shape as the admin upload.
 */
class SoundKitCliImportTest extends TestCase
{
    // The intake and processor own root transactions, so no test transaction may wrap them.
    use FinalizationDatabaseMigrations;

    private const PASSWORD = 'SyntheticKitCli-Pass-4821';

    private TestOnlyMediaScanner $scanner;

    private User $actor;

    /** Files this test created outside the private disk; removed in tearDown. */
    private array $cleanup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        $this->scanner = MediaFixtures::configure();
        $this->actor = LicenseFixtures::admin();
        $this->actor->forceFill(['password' => Hash::make(self::PASSWORD)])->save();
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            if (is_link($path) || is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                @rmdir($path);
            }
        }
        parent::tearDown();
    }

    private function bytes(): string
    {
        return StemsFixtures::zip([['name' => 'Kicks/Kick.wav'], ['name' => 'Bass.wav', 'bytes' => MediaFixtures::wav(0.35, 120)]]);
    }

    private function tmp(?string $bytes = null, string $suffix = '.zip'): string
    {
        $path = '/tmp/kit-cli-import-'.Str::uuid().$suffix;
        file_put_contents($path, $bytes ?? $this->bytes());
        chmod($path, 0600); // The command refuses group- or world-writable files whatever the umask.
        $this->cleanup[] = $path;

        return $path;
    }

    private function kit(string $path, array $options = [], bool $password = true): PendingCommand
    {
        $pending = $this->artisan('vasey:sound-kit-import', ['zip' => $path] + $options + ['--actor' => $this->actor->email,
            '--title' => 'Imported kit', '--provenance' => 'Synthetic CLI fixture']);
        if ($password && ! isset($options['--check'])) {
            $pending->expectsQuestion(SoundKitImport::PASSWORD_PROMPT, self::PASSWORD)->doesntExpectOutputToContain(self::PASSWORD);
        }

        return $pending;
    }

    private function census(): array
    {
        $tables = ['users', 'audit_events', 'sound_kit_drafts', 'sound_kit_revisions'];

        return array_combine($tables, array_map(fn (string $table): int => DB::table($table)->count(), $tables))
            + ['files' => count(Storage::disk('local')->allFiles('sound-kits')), 'workspaces' => count(Storage::disk('local')->allDirectories('processing'))];
    }

    private function assertRefused(string $path, string $message, array $options = [], bool $password = false): void
    {
        $before = $this->census();
        $this->kit($path, $options, $password)->expectsOutputToContain($message)->assertExitCode(1);
        $this->assertSame($before, $this->census());
    }

    private function inEnvironment(string $detected, Closure $probe, ?string $configured = null): void
    {
        $this->app->detectEnvironment(fn (): string => $detected);
        config(['app.env' => $configured ?? $detected]);
        try {
            $probe();
        } finally {
            $this->app->detectEnvironment(fn (): string => 'testing');
            config(['app.env' => 'testing']);
        }
    }

    private function enroll(User $account): void
    {
        $provider = Filament::getPanel('admin')->getMultiFactorAuthenticationProviders()['app'];
        $this->assertInstanceOf(AppAuthentication::class, $provider);
        $account->saveAppAuthenticationSecret($provider->generateSecret());
    }

    public function test_production_and_ambiguous_environments_are_refused_for_write_and_check(): void
    {
        $path = $this->tmp();
        $this->inEnvironment('production', function () use ($path): void {
            $this->assertRefused($path, 'refused in production');
            $this->assertRefused($path, 'refused in production', ['--check' => true]);
        });
        // A cached production configuration still refuses when the running environment says staging.
        $this->enroll($this->actor);
        $this->inEnvironment('staging', fn () => $this->assertRefused($path, 'refused in production'), 'production');
        foreach ([['staging', 'testing'], ['testing', 'staging'], ['local', 'staging']] as [$detected, $configured]) {
            $this->inEnvironment($detected, function () use ($path): void {
                $this->assertRefused($path, 'running environment differ');
                $this->assertRefused($path, 'running environment differ', ['--check' => true]);
            }, $configured);
        }
        foreach (['development', 'Staging', 'staging-eu', 'prod', ''] as $environment) {
            $this->inEnvironment($environment, fn () => $this->assertRefused($path, 'runs only when APP_ENV is local, testing or staging'));
        }
        $this->assertFileExists($path);
    }

    public function test_the_actor_must_be_existing_catalog_staff_with_the_panel_mfa_rule_satisfied(): void
    {
        $path = $this->tmp();
        $this->assertRefused($path, 'Give --actor', ['--actor' => '']);
        $this->assertRefused($path, 'No staff account has that --actor email', ['--actor' => 'nobody@example.test']);
        $customer = User::factory()->create();
        $this->assertRefused($path, 'lacks catalog authority', ['--actor' => $customer->email]);
        $this->actor->forceFill(['is_admin' => false])->save();
        $this->assertRefused($path, 'lacks catalog authority');
        $this->actor->forceFill(['is_admin' => true])->save();

        // Staging requires the panel's staff MFA: an unenrolled administrator is refused, enrollment admits them.
        $this->inEnvironment('staging', function () use ($path): void {
            $this->assertRefused($path, "does not satisfy the admin panel's MFA rule");
            $this->assertRefused($path, "does not satisfy the admin panel's MFA rule", ['--check' => true]);
            $this->enroll($this->actor);
            $this->kit($path, ['--check' => true])->expectsOutputToContain('Checked only. Nothing was written.')->assertSuccessful();
        });
    }

    public function test_a_wrong_password_or_a_non_interactive_session_writes_nothing(): void
    {
        $path = $this->tmp();
        $before = $this->census();
        $this->artisan('vasey:sound-kit-import', ['zip' => $path, '--actor' => $this->actor->email, '--title' => 'T', '--provenance' => 'P'])
            ->expectsQuestion(SoundKitImport::PASSWORD_PROMPT, 'not-the-password')->expectsOutputToContain('password was not confirmed')->assertExitCode(1);
        $this->artisan('vasey:sound-kit-import', ['zip' => $path, '--actor' => $this->actor->email, '--title' => 'T', '--provenance' => 'P'])
            ->expectsQuestion(SoundKitImport::PASSWORD_PROMPT, '')->expectsOutputToContain('Staff authority was refused')->assertExitCode(1);
        $this->assertSame($before, $this->census());
        $this->artisan('vasey:sound-kit-import', ['zip' => $path, '--actor' => $this->actor->email, '--title' => 'T', '--provenance' => 'P', '--no-interaction' => true])
            ->expectsOutputToContain('requires an interactive session')->assertExitCode(1);
        $this->assertSame($before, $this->census());
    }

    public function test_path_guard_refuses_before_any_password_prompt_copy_or_write(): void
    {
        $valid = $this->tmp();
        $link = '/tmp/kit-cli-import-link-'.Str::uuid().'.zip';
        symlink($valid, $link);
        $this->cleanup[] = $link;
        $this->assertRefused($link, 'symbolic link');

        $linkedDirectory = '/tmp/kit-cli-import-dir-'.Str::uuid();
        symlink('/tmp', $linkedDirectory);
        $this->cleanup[] = $linkedDirectory;
        $this->assertRefused($linkedDirectory.'/'.basename($valid), 'canonical');
        $this->assertRefused('/tmp/../tmp/'.basename($valid), 'canonical');
        $this->assertRefused('/tmp//'.basename($valid), 'canonical');
        $this->assertRefused(basename($valid), 'absolute path');
        $this->assertRefused('', 'absolute path');

        $outside = Storage::disk('local')->path('elsewhere');
        mkdir($outside, 0700, true);
        file_put_contents($outside.'/kit.zip', $this->bytes());
        chmod($outside.'/kit.zip', 0600);
        $this->assertRefused($outside.'/kit.zip', 'direct child of /tmp or of the private kit-imports directory');
        $nested = '/tmp/kit-cli-import-nested-'.Str::uuid();
        mkdir($nested);
        $this->cleanup[] = $nested.'/kit.zip';
        $this->cleanup[] = $nested;
        file_put_contents($nested.'/kit.zip', $this->bytes());
        chmod($nested.'/kit.zip', 0600);
        $this->assertRefused($nested.'/kit.zip', 'direct child of /tmp');
        $this->assertRefused('/etc/passwd', 'direct child of /tmp');

        $directory = '/tmp/kit-cli-import-'.Str::uuid().'.zip';
        mkdir($directory);
        $this->cleanup[] = $directory;
        $this->assertRefused($directory, 'not a regular file');
        $this->assertRefused('/tmp/kit-cli-import-absent-'.Str::uuid().'.zip', 'does not exist');
        $this->assertRefused($this->tmp($this->bytes(), '.txt'), 'end in .zip');
        $this->assertRefused($this->tmp('PK'), 'complete ZIP of at most');
        config(['media.max_source_bytes' => 200]);
        $oversize = $this->tmp(str_repeat('a', 201));
        $this->assertRefused($oversize, 'complete ZIP of at most 200 bytes');
        // The oversize file is refused from its metadata, before the archive is opened or copied.
        $this->assertSame(201, filesize($oversize));
    }

    public function test_a_kit_inside_the_private_kit_imports_directory_is_admitted(): void
    {
        $directory = Storage::disk('local')->path('kit-imports');
        mkdir($directory, 0700, true);
        file_put_contents($directory.'/kit.zip', $this->bytes());
        chmod($directory.'/kit.zip', 0600);
        $this->kit(realpath($directory).'/kit.zip', ['--check' => true])->expectsOutputToContain('Checked only')->assertSuccessful();
    }

    public function test_check_validates_the_archive_and_writes_nothing(): void
    {
        $path = $this->tmp();
        $before = $this->census();
        $hash = hash_file('sha256', $path);
        $this->kit($path, ['--check' => true])->expectsOutputToContain('passes the path rules')->expectsOutputToContain('Would create a new kit draft')
            ->expectsOutputToContain('Nothing was written')->assertSuccessful();
        $this->assertSame($before, $this->census());
        $this->assertSame($hash, hash_file('sha256', $path));
        Queue::assertNothingPushed();

        $this->kit($this->tmp('PK'.str_repeat("\0", 40)), ['--check' => true])->expectsOutputToContain('does not pass the kit profile')->assertExitCode(1);
        $this->assertSame($before, $this->census());
    }

    public function test_a_valid_zip_reaches_ready_with_the_same_revision_shape_as_the_web_path_and_leaves_the_source_untouched(): void
    {
        $path = $this->tmp();
        $hash = hash_file('sha256', $path);
        $mtime = filemtime($path);
        $this->kit($path)->expectsOutputToContain('revision=1')->expectsOutputToContain('state=ready')
            ->expectsOutputToContain('not rights approval')->doesntExpectOutputToContain(dirname($path))->assertSuccessful();

        $this->assertSame($hash, hash_file('sha256', $path));
        $this->assertSame($mtime, filemtime($path));
        $this->assertSame([], Storage::disk('local')->allFiles('processing'));
        $this->assertSame([], Storage::disk('local')->allDirectories('processing'));

        $cli = SoundKitRevision::sole();
        $draft = SoundKitDraft::sole();
        $this->assertSame(2, $draft->version);
        $this->assertSame('ready', $cli->status, $cli->failure_code ?? '');
        $this->assertSame(['title' => 'Imported kit', 'description' => '', 'provenance' => 'Synthetic CLI fixture'], $cli->description_snapshot);
        $this->assertSame($this->actor->id, $cli->uploaded_by);
        $this->assertSame($this->actor->id, $cli->requested_by);
        $this->assertSame(basename($path), $cli->original_name);
        $this->assertSame($hash, $cli->source_sha256);

        // The admin upload's own path, same bytes, a second draft.
        $web = app(SoundKitDrafts::class)->save(null, ['title' => 'Imported kit', 'description' => '', 'provenance' => 'Synthetic CLI fixture'], $this->actor);
        $webRevision = app(SoundKitIntake::class)->handle($web->id, UploadedFile::fake()->createWithContent(basename($path), file_get_contents($path)), 1, $this->actor);
        $webRevision = app(SoundKitProcessor::class)->handle($webRevision->id);
        $this->assertSame('ready', $webRevision->status);
        foreach (['source_sha256', 'source_size_bytes', 'mime_type', 'profile', 'profile_sha256', 'description_snapshot', 'status', 'number',
            'archive_sha256', 'archive_size_bytes', 'original_name', 'uploaded_by', 'requested_by', 'attempts', 'failure_code'] as $attribute) {
            $this->assertEquals($webRevision->{$attribute}, $cli->{$attribute}, $attribute);
        }
        $this->assertSame('wav-sample-kit-zip-v1', $cli->profile['archive_version']);
        $this->assertSame(array_keys($webRevision->manifest), array_keys($cli->manifest));
        $this->assertSame($webRevision->manifest['members'], $cli->manifest['members']);
        $this->assertSame(array_keys($webRevision->evidence), array_keys($cli->evidence));
        $this->assertSame(array_keys($webRevision->getAttributes()), array_keys($cli->getAttributes()));
        $this->assertStringStartsWith('sound-kits/revisions/'.$draft->public_id.'-', $cli->source_path);
        $this->assertSame(['Bass.wav', 'Kicks/Kick.wav'], array_column($cli->manifest['members'], 'name'));
        $this->assertSame(0, DB::table('tracks')->count());
        $this->assertSame(0, DB::table('offers')->count());
        Queue::assertPushed(ProcessSoundKit::class, 2);
    }

    public function test_audit_events_name_the_actor_and_the_console_transport(): void
    {
        $path = $this->tmp();
        $this->kit($path)->assertSuccessful();
        $revision = SoundKitRevision::sole();
        foreach (['sound_kit.draft.created', 'sound_kit.revision.received', 'sound_kit.cli_import', 'sound_kit.revision.processing', 'sound_kit.revision.verified'] as $action) {
            $event = AuditEvent::where('action', $action)->sole();
            $this->assertSame($this->actor->id, $event->actor_id, $action);
        }
        $event = AuditEvent::where('action', 'sound_kit.cli_import')->sole();
        $this->assertSame(SoundKitRevision::class, $event->subject_type);
        $this->assertSame($revision->id, $event->subject_id);
        $this->assertSame('console', $event->context['transport']);
        $this->assertSame(hash_file('sha256', $path), $event->context['source_sha256']);
        $this->assertTrue($event->context['draft_created']);
        $this->assertStringNotContainsString('/tmp', json_encode($event->context));
    }

    public function test_draft_option_appends_a_numbered_revision_and_refuses_metadata_or_unknown_drafts(): void
    {
        $this->kit($this->tmp())->assertSuccessful();
        $draft = SoundKitDraft::sole();
        $second = $this->tmp(StemsFixtures::zip([['name' => 'Snare.wav', 'bytes' => MediaFixtures::wav(0.3, 200)]]));
        $this->assertRefused($second, 'apply only to a new draft', ['--draft' => $draft->public_id]);
        $this->assertRefused($second, 'No sound kit draft has that --draft', ['--draft' => (string) Str::uuid()]);
        $this->assertRefused($second, 'No sound kit draft has that --draft', ['--draft' => 'x']);
        $this->assertRefused($second, 'Give --title and --provenance', ['--title' => '']);

        $before = $this->census();
        $this->artisan('vasey:sound-kit-import', ['zip' => $second, '--actor' => $this->actor->email, '--draft' => $draft->public_id, '--check' => true])
            ->expectsOutputToContain('Would append revision 2')->assertSuccessful();
        $this->assertSame($before, $this->census());
        $this->artisan('vasey:sound-kit-import', ['zip' => $second, '--actor' => $this->actor->email, '--draft' => $draft->public_id])
            ->expectsQuestion(SoundKitImport::PASSWORD_PROMPT, self::PASSWORD)
            ->expectsOutputToContain('revision=2')->expectsOutputToContain('state=ready')->assertSuccessful();
        $this->assertSame(1, SoundKitDraft::count());
        $this->assertSame([1, 2], SoundKitRevision::orderBy('number')->pluck('number')->all());
        $this->assertSame(3, $draft->fresh()->version);
        $this->assertSame(['Snare.wav'], array_column(SoundKitRevision::where('number', 2)->sole()->manifest['members'], 'name'));
    }

    public function test_an_archive_the_profile_rejects_leaves_the_new_draft_and_a_hint_but_no_revision(): void
    {
        $path = $this->tmp(StemsFixtures::zip([['name' => 'notes.txt', 'bytes' => 'not audio']]));
        $this->kit($path)->expectsOutputToContain('The new draft')->assertExitCode(1);
        $this->assertSame(1, SoundKitDraft::count());
        $this->assertSame(0, SoundKitRevision::count());
        $this->assertSame([], Storage::disk('local')->allDirectories('processing'));
        $this->assertFileExists($path);
    }

    public function test_a_failed_verification_is_reported_with_its_reason_and_a_failing_exit_code(): void
    {
        $this->scanner->reject = true;
        $path = $this->tmp();
        $this->kit($path)->expectsOutputToContain('state=failed failure_code=scan_not_clean')->expectsOutputToContain('failed verification')->assertExitCode(1);
        $this->assertSame('failed', SoundKitRevision::sole()->status);
        $this->assertFileExists($path);
    }

    public function test_a_file_swapped_after_validation_is_refused_without_a_revision_workspace_or_source_change(): void
    {
        $import = app(SoundKitCliImport::class);
        $draft = app(SoundKitDrafts::class)->save(null, ['title' => 'Swap', 'description' => '', 'provenance' => 'P'], $this->actor);
        foreach (['rename' => false, 'symlink' => true] as $kind => $viaSymlink) {
            $path = $this->tmp();
            $source = $import->source($path);
            // Same size and a regular file, so only the device/inode identity can tell the swap.
            $replacement = $this->tmp(str_repeat('x', $source['size']));
            if ($viaSymlink) {
                unlink($path);
                symlink($replacement, $path);
                $this->cleanup[] = $path;
                $expected = hash_file('sha256', $replacement);
            } else {
                rename($replacement, $path);
                $expected = hash_file('sha256', $path);
            }
            $before = $this->census();
            $result = $import->import($this->actor, $source, $draft->fresh(), []);

            $this->assertInstanceOf(SoundKitCliImportRefusal::class, $result['error'], $kind);
            $this->assertStringContainsString('changed after it was checked', $result['error']->getMessage(), $kind);
            $this->assertNull($result['revision'], $kind);
            $this->assertSame(0, SoundKitRevision::count(), $kind);
            $this->assertSame($before, $this->census(), $kind);
            $this->assertSame([], Storage::disk('local')->allDirectories('processing'), $kind);
            $this->assertSame($expected, hash_file('sha256', $path), $kind);
        }
    }

    public function test_a_file_owned_by_another_user_is_refused(): void
    {
        $path = $this->tmp();
        $this->assertSame(0, posix_geteuid(), 'This test needs a process that may chown.');
        chown($path, 65534);
        clearstatcache();
        $this->assertRefused($path, 'owned by root or by the user running this command');
        $this->assertRefused($path, 'owned by root or by the user running this command', ['--check' => true]);
        chown($path, 0);
        $this->kit($path, ['--check' => true])->expectsOutputToContain('Checked only')->assertSuccessful();
    }

    public function test_a_symlinked_private_kit_imports_directory_is_not_an_allowed_directory(): void
    {
        $real = Storage::disk('local')->path('kit-real');
        mkdir($real, 0700, true);
        chmod($real.'/..', 0700);
        file_put_contents($real.'/kit.zip', $this->bytes());
        chmod($real.'/kit.zip', 0600);
        $link = Storage::disk('local')->path('kit-imports');
        symlink($real, $link);
        $this->assertRefused(realpath($real).'/kit.zip', 'direct child of /tmp or of the private kit-imports directory');
        $this->assertRefused(realpath($real).'/kit.zip', 'direct child of /tmp or of the private kit-imports directory', ['--check' => true]);
    }

    public function test_a_hard_linked_file_is_refused(): void
    {
        $path = $this->tmp();
        $second = '/tmp/kit-cli-import-hardlink-'.Str::uuid().'.zip';
        link($path, $second);
        $this->cleanup[] = $second;
        $this->assertRefused($path, 'more than one hard link');
        $this->assertRefused($second, 'more than one hard link', ['--check' => true]);
        unlink($second);
        clearstatcache();
        $this->kit($path, ['--check' => true])->expectsOutputToContain('Checked only')->assertSuccessful();
    }

    public function test_a_group_or_world_writable_file_is_refused(): void
    {
        $path = $this->tmp();
        foreach ([0620, 0602, 0666, 0664] as $mode) {
            chmod($path, $mode);
            clearstatcache();
            $this->assertRefused($path, 'group- or world-writable', [], false);
            $this->assertRefused($path, 'group- or world-writable', ['--check' => true]);
        }
        chmod($path, 0644);
        clearstatcache();
        $this->kit($path, ['--check' => true])->expectsOutputToContain('Checked only')->assertSuccessful();
    }

    public function test_import_refuses_production_itself_without_the_command_and_creates_nothing(): void
    {
        $import = app(SoundKitCliImport::class);
        $source = $import->source($this->tmp());
        $this->enroll($this->actor); // So only the environment guard, not the panel MFA rule, can stop it.
        $before = $this->census();
        $this->inEnvironment('production', function () use ($import, $source): void {
            try {
                $import->import($this->actor, $source, null, ['title' => 'T', 'provenance' => 'P']);
                $this->fail('import() must refuse production on its own.');
            } catch (SoundKitCliImportRefusal $refusal) {
                $this->assertStringContainsString('refused in production', $refusal->getMessage());
            }
        });
        $this->assertSame($before, $this->census());
        $this->assertSame(0, SoundKitRevision::count());
        $this->assertSame(0, SoundKitDraft::count());
    }

    public function test_a_revision_claimed_first_by_a_queue_worker_is_reported_as_running_there(): void
    {
        // A worker takes the claim between the intake's commit and the CLI's own processor call.
        AuditEvent::creating(function (AuditEvent $event): void {
            if ($event->action === 'sound_kit.cli_import') {
                DB::table('sound_kit_revisions')->update(['status' => 'processing', 'claim_token' => (string) Str::uuid(), 'claimed_until' => now()->addMinutes(10)]);
            }
        });
        $this->kit($this->tmp())->expectsOutputToContain('state=processing')->expectsOutputToContain('Verification is running in the queue worker, which claimed this revision first. Check the revision state under Admin → Sound kit drafts, or re-run later with --draft=')
            ->doesntExpectOutputToContain('waiting and retryable')->assertExitCode(1);
        $this->assertSame('processing', SoundKitRevision::sole()->status);
    }
}
