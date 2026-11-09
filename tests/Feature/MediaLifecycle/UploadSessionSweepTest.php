<?php

namespace Tests\Feature\MediaLifecycle;

use App\Console\Commands\SweepAbandonedUploadSessions;
use App\Domain\Catalog\Models\Track;
use App\Domain\Media\Lifecycle\AbandonedUploadSessions;
use App\Domain\Media\Lifecycle\UploadSweepRefused;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Models\MediaUploadSession;
use App\Domain\Media\ResumableMediaUploads;
use App\Domain\SoundKits\Models\SoundKitDraft;
use App\Domain\SoundKits\Models\SoundKitUploadSession;
use App\Domain\SoundKits\SoundKitDrafts;
use App\Domain\SoundKits\SoundKitUploads;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\TestCase;

/**
 * `vasey:upload-sessions-sweep` (FP-034 tier 2) cleans only the chunk directories of expired or interrupted resumable
 * uploads under `resumable/`, marking each retained row first, and leaves everything else in place.
 */
final class UploadSessionSweepTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private int $uid;

    private User $actor;

    private Track $track;

    private SoundKitDraft $draft;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        MediaFixtures::configure();
        $this->uid = posix_geteuid() === 0 ? 4242 : posix_geteuid();
        $this->app->instance(SweepAbandonedUploadSessions::EUID_BINDING, $this->uid);
        $this->travelTo(now()->startOfSecond());
        $this->actor = LicenseFixtures::admin();
        $this->track = Track::create(['title' => 'SYNTHETIC sweep track', 'slug' => 'synthetic-sweep-track']);
        $this->draft = app(SoundKitDrafts::class)->save(null, ['title' => 'Synthetic sweep kit', 'provenance' => 'Synthetic source'], $this->actor);
    }

    private function root(): string
    {
        return rtrim(Storage::disk('local')->path(''), '/');
    }

    /** A media upload session with all its bytes received but never completed: one chunk file on disk. */
    private function mediaSession(string $bytes = 'synthetic media transport bytes'): string
    {
        $uploads = app(ResumableMediaUploads::class);
        $session = $uploads->start($this->track, 'artwork', strlen($bytes), hash('sha256', $bytes), 'synthetic.png', $this->actor);
        $uploads->append($session['id'], 0, UploadedFile::fake()->createWithContent('chunk.bin', $bytes), $this->actor);

        return $session['id'];
    }

    private function kitSession(string $bytes = 'synthetic kit transport bytes, at least twenty-two'): string
    {
        $uploads = app(SoundKitUploads::class);
        $session = $uploads->start($this->draft, 1, strlen($bytes), hash('sha256', $bytes), 'samples.zip', $this->actor);
        $uploads->append($session['id'], 0, UploadedFile::fake()->createWithContent('part.bin', $bytes), $this->actor);

        return $session['id'];
    }

    /** Hands the temp root to the stand-in site user (only needed, and only possible, when the suite runs as root). */
    private function own(?int $uid = null): void
    {
        if (posix_geteuid() !== 0 || ! is_dir($this->root().'/resumable')) {
            return;
        }
        $paths = [$this->root().'/resumable'];
        foreach (scandir($this->root().'/resumable') as $name) {
            if ($name === '.' || $name === '..' || is_link($this->root().'/resumable/'.$name)) {
                continue;
            }
            $paths[] = $this->root().'/resumable/'.$name;
            if (is_dir($this->root().'/resumable/'.$name)) {
                foreach (scandir($this->root().'/resumable/'.$name) as $file) {
                    if ($file !== '.' && $file !== '..' && ! is_link($this->root().'/resumable/'.$name.'/'.$file)) {
                        $paths[] = $this->root().'/resumable/'.$name.'/'.$file;
                    }
                }
            }
        }
        foreach ($paths as $path) {
            chown($path, $uid ?? $this->uid);
        }
    }

    /** Every file and directory on the private disk, with its type, size and owner. */
    private function listing(): array
    {
        $result = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root(), \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            $stat = lstat($file->getPathname());
            $result[substr($file->getPathname(), strlen($this->root()))] = [$stat['mode'], $stat['size'], $stat['uid']];
        }
        ksort($result);

        return $result;
    }

    private function rows(): array
    {
        return [DB::table('media_upload_sessions')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            DB::table('sound_kit_upload_sessions')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            DB::table('audit_events')->count(), DB::table('media_assets')->count()];
    }

    /** @return array{0:int,1:string} */
    private function sweep(array $options = []): array
    {
        $exit = Artisan::call('vasey:upload-sessions-sweep', $options);

        return [$exit, trim(Artisan::output())];
    }

    public function test_dry_run_is_the_default_and_changes_no_row_audit_event_or_file(): void
    {
        $media = $this->mediaSession();
        $kit = $this->kitSession();
        $this->own();
        $this->travel(49)->hours();
        $rows = $this->rows();
        $files = $this->listing();
        $this->assertArrayHasKey('/resumable/'.$media.'/part-0.part', $files);
        $this->assertArrayHasKey('/resumable/'.$kit.'/part-0.part', $files);

        [$exit, $output] = $this->sweep();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Dry run (nothing changed; use --execute): 2 expired and 0 interrupted-cancel session(s) older than 172800 s; 0 marked cancelled.', $output);
        $this->assertStringContainsString('Directories to remove: 2 (2 file(s)); already absent: 0; left in place: 0.', $output);
        $this->assertSame($rows, $this->rows());
        $this->assertSame($files, $this->listing());
        $this->assertStringNotContainsString($media, $output);
        $this->assertStringNotContainsString($this->root(), $output);
    }

    public function test_execute_marks_each_row_before_removing_its_chunks_and_a_second_run_changes_nothing(): void
    {
        $media = $this->mediaSession();
        $kit = $this->kitSession();
        $this->own();
        $this->travel(49)->hours();

        [$exit, $output] = $this->sweep(['--execute' => true]);

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Executed: 2 expired and 0 interrupted-cancel session(s) older than 172800 s; 2 marked cancelled.', $output);
        $this->assertStringContainsString('Directories removed: 2 (2 file(s)); already absent: 0; left in place: 0.', $output);
        foreach ([[MediaUploadSession::class, $media, 'media.upload.session_abandoned'], [SoundKitUploadSession::class, $kit, 'sound_kit.upload.abandoned']] as [$model, $id, $action]) {
            $session = $model::where('public_id', $id)->firstOrFail();
            $this->assertSame('cancelled', $session->status);
            $this->assertNotNull($session->cleaned_at);
            $this->assertDirectoryDoesNotExist($this->root().'/resumable/'.$id);
            $audit = AuditEvent::where('action', $action)->where('subject_id', $session->id)->sole();
            $this->assertNull($audit->actor_id);
            $this->assertSame('expired_unattended', $audit->context['reason']);
        }
        $this->assertDirectoryExists($this->root().'/resumable');

        $rows = $this->rows();
        $files = $this->listing();
        [$exit, $output] = $this->sweep(['--execute' => true]);
        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Executed: 0 expired and 0 interrupted-cancel session(s)', $output);
        $this->assertSame($rows, $this->rows());
        $this->assertSame($files, $this->listing());
    }

    public function test_sessions_younger_than_the_age_and_active_sessions_are_kept(): void
    {
        $old = $this->mediaSession('synthetic old enough bytes');
        $this->travel(1)->seconds();
        $young = $this->mediaSession('synthetic just too young bytes');
        $this->own();
        // $old is exactly 48 h idle at the sweep; $young one second less. Both expired 24 h after starting.
        $this->travel(48)->hours();
        $this->travel(-1)->seconds();

        [$exit, $output] = $this->sweep(['--execute' => true]);
        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('1 expired', $output);
        $this->assertSame('cancelled', MediaUploadSession::where('public_id', $old)->value('status'));
        $this->assertSame('uploading', MediaUploadSession::where('public_id', $young)->value('status'));
        $this->assertFileExists($this->root().'/resumable/'.$young.'/part-0.part');

        // A session still inside its 24-hour lifetime is never eligible, whatever the age option.
        $active = $this->mediaSession('synthetic active bytes');
        $this->own();
        $this->travel(23)->hours();
        [$exit] = $this->sweep(['--execute' => true, '--older-than' => 86400]);
        $this->assertSame(0, $exit);
        $this->assertSame('uploading', MediaUploadSession::where('public_id', $active)->value('status'));
        $this->assertFileExists($this->root().'/resumable/'.$active.'/part-0.part');
        // The shorter explicit age now reaches the young session (idle 48 h + 23 h - 1 s).
        $this->assertSame('cancelled', MediaUploadSession::where('public_id', $young)->value('status'));
    }

    public function test_an_age_below_the_upload_lifetime_is_invalid_and_changes_nothing(): void
    {
        $this->mediaSession();
        $this->own();
        $this->travel(49)->hours();
        $rows = $this->rows();
        foreach (['86399', '0', 'abc'] as $age) {
            [$exit, $output] = $this->sweep(['--execute' => true, '--older-than' => $age]);
            $this->assertSame(2, $exit, $output);
        }
        $this->assertSame($rows, $this->rows());
    }

    public function test_an_interrupted_cancel_cleanup_is_finished_and_a_completed_session_and_its_asset_are_never_touched(): void
    {
        $cancelled = $this->mediaSession('synthetic cancelled bytes');
        // A cancel whose cleanup never ran: terminal row, chunks still on disk.
        MediaUploadSession::where('public_id', $cancelled)->update(['status' => 'cancelled']);
        $bytes = MediaFixtures::png();
        $uploads = app(ResumableMediaUploads::class);
        $done = $uploads->start($this->track, 'artwork', strlen($bytes), hash('sha256', $bytes), 'synthetic.png', $this->actor);
        $uploads->append($done['id'], 0, UploadedFile::fake()->createWithContent('chunk.bin', $bytes), $this->actor);
        $asset = $uploads->complete($done['id'], $this->actor);
        // Its own cleanup ran; pretend it had not, with transport files present.
        MediaUploadSession::where('public_id', $done['id'])->update(['cleaned_at' => null]);
        mkdir($this->root().'/resumable/'.$done['id'], 0700);
        file_put_contents($this->root().'/resumable/'.$done['id'].'/complete.assembled', $bytes);
        $this->own();
        $assetFile = $this->root().'/'.MediaAsset::findOrFail($asset->id)->storage_path;
        $this->assertFileExists($assetFile);
        $this->travel(49)->hours();

        [$exit, $output] = $this->sweep(['--execute' => true]);

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('0 expired and 1 interrupted-cancel session(s)', $output);
        $this->assertStringContainsString('0 marked cancelled', $output);
        $this->assertStringContainsString('Not touched: 1 completed session(s) awaiting their own cleanup', $output);
        $this->assertNotNull(MediaUploadSession::where('public_id', $cancelled)->value('cleaned_at'));
        $this->assertDirectoryDoesNotExist($this->root().'/resumable/'.$cancelled);
        $this->assertFileExists($this->root().'/resumable/'.$done['id'].'/complete.assembled');
        $this->assertSame('completed', MediaUploadSession::where('public_id', $done['id'])->value('status'));
        $this->assertNull(MediaUploadSession::where('public_id', $done['id'])->value('cleaned_at'));
        $this->assertFileExists($assetFile);
        $this->assertSame(hash('sha256', $bytes), hash_file('sha256', $assetFile));
        $this->assertSame(0, AuditEvent::where('action', 'media.upload.session_abandoned')->count());
    }

    public function test_links_foreign_entries_wrong_names_and_unexpected_contents_are_left_in_place(): void
    {
        $tampered = $this->mediaSession('synthetic tampered bytes');
        $linked = $this->kitSession();
        $resumable = $this->root().'/resumable';
        $outside = $this->root().'/outside-target';
        mkdir($outside, 0700);
        file_put_contents($outside.'/keep.bin', 'synthetic link target');
        // An unexpected file in a sweepable session's directory, and a link in place of another's chunk.
        file_put_contents($resumable.'/'.$tampered.'/evil.sh', 'synthetic foreign file');
        unlink($resumable.'/'.$linked.'/part-0.part');
        symlink($outside.'/keep.bin', $resumable.'/'.$linked.'/part-0.part');
        // Foreign entries: a link named like a session, a file, a wrongly named directory and another user's directory.
        $linkName = (string) Str::uuid();
        symlink($outside, $resumable.'/'.$linkName);
        file_put_contents($resumable.'/notes.txt', 'synthetic note');
        mkdir($resumable.'/NOT-A-SESSION', 0700);
        file_put_contents($resumable.'/NOT-A-SESSION/part-0.part', 'synthetic');
        $this->own();
        $other = (string) Str::uuid();
        mkdir($resumable.'/'.$other, 0700);
        if (posix_geteuid() === 0) {
            chown($resumable.'/'.$other, $this->uid + 1);
        }
        $this->travel(49)->hours();
        $files = $this->listing();

        [$exit, $output] = $this->sweep(['--execute' => true]);

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('Directories removed: 0 (0 file(s)); already absent: 0; left in place: 2.', $output);
        $this->assertStringContainsString(posix_geteuid() === 0 ? '4 foreign entries' : '3 foreign entries', $output);
        $this->assertSame($files, $this->listing());
        $this->assertSame('synthetic link target', file_get_contents($outside.'/keep.bin'));
        foreach ([[MediaUploadSession::class, $tampered], [SoundKitUploadSession::class, $linked]] as [$model, $id]) {
            // Marked (no writer can use it again) but not cleaned: the next run, or an operator, finishes it.
            $session = $model::where('public_id', $id)->firstOrFail();
            $this->assertSame('cancelled', $session->status);
            $this->assertNull($session->cleaned_at);
        }
    }

    public function test_unsafe_roots_and_the_root_user_are_refused_before_any_change(): void
    {
        $id = $this->mediaSession();
        $this->own();
        $this->travel(49)->hours();
        $rows = $this->rows();
        $files = $this->listing();

        $this->app->instance(SweepAbandonedUploadSessions::EUID_BINDING, 0);
        [$exit, $output] = $this->sweep(['--execute' => true]);
        $this->assertSame([1, 'Refused: Refusing to sweep as root: run it as the site user that owns the private disk. Nothing was changed.'], [$exit, $output]);
        $this->app->instance(SweepAbandonedUploadSessions::EUID_BINDING, $this->uid);

        chmod($this->root().'/resumable', 0750);
        [$exit, $output] = $this->sweep(['--execute' => true]);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('upload temp root is not a private (0700) directory', $output);
        chmod($this->root().'/resumable', 0700);

        $this->app->instance(SweepAbandonedUploadSessions::EUID_BINDING, $this->uid + 1);
        [$exit, $output] = $this->sweep(['--execute' => true]);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('owned by the sweeping user', $output);
        $this->app->instance(SweepAbandonedUploadSessions::EUID_BINDING, $this->uid);

        $private = config('filesystems.disks.local.root');
        foreach (['served' => ['filesystems.disks.local.serve' => true], 'public' => ['filesystems.disks.local.visibility' => 'public']] as $settings) {
            config($settings);
            [$exit, $output] = $this->sweep(['--execute' => true]);
            $this->assertSame(1, $exit);
            $this->assertStringContainsString('not an unserved private local disk', $output);
            config(['filesystems.disks.local.serve' => false, 'filesystems.disks.local.visibility' => 'private']);
        }
        $this->assertSame($rows, $this->rows());
        $this->assertSame($files, $this->listing());

        // A private root that is inside the web root is refused even with a correct temp root.
        $public = public_path('synthetic-sweep-'.Str::uuid());
        mkdir($public.'/resumable/'.$id, 0700, true);
        file_put_contents($public.'/resumable/'.$id.'/part-0.part', 'synthetic');
        try {
            config(['filesystems.disks.local.root' => $public]);
            [$exit, $output] = $this->sweep(['--execute' => true]);
            $this->assertSame(1, $exit);
            $this->assertStringContainsString('is, or is inside, a public or served directory', $output);
            $this->assertFileExists($public.'/resumable/'.$id.'/part-0.part');
        } finally {
            config(['filesystems.disks.local.root' => $private]);
            unlink($public.'/resumable/'.$id.'/part-0.part');
            rmdir($public.'/resumable/'.$id);
            rmdir($public.'/resumable');
            rmdir($public);
        }
        $this->assertSame($rows, $this->rows());
        $this->assertSame('uploading', MediaUploadSession::where('public_id', $id)->value('status'));
    }

    public function test_the_scheduled_run_is_hourly_and_off_until_enabled(): void
    {
        $this->app->make(Kernel::class)->bootstrap();
        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'vasey:upload-sessions-sweep'));
        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertStringContainsString('--execute', (string) $event->command);
        $this->assertSame(['37 * * * *', true, 10], [$event->expression, $event->withoutOverlapping, $event->expiresAt]);
        $this->assertFalse($event->filtersPass($this->app));
        config(['media.upload_session_sweep.scheduled' => true]);
        $this->assertTrue($event->filtersPass($this->app));
    }

    public function test_a_session_touched_after_selection_is_rechecked_under_its_lock_and_kept(): void
    {
        $id = $this->mediaSession();
        $this->own();
        $this->travel(49)->hours();
        $touched = false;
        DB::listen(function ($query) use (&$touched, $id): void {
            // Right after the sweep selects its candidates, the row becomes recently active again.
            if (! $touched && str_contains($query->sql, 'media_upload_sessions') && str_contains($query->sql, 'expires_at')) {
                $touched = true;
                DB::table('media_upload_sessions')->where('public_id', $id)->update(['updated_at' => now()]);
            }
        });

        [$exit, $output] = $this->sweep(['--execute' => true]);

        $this->assertTrue($touched);
        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('1 expired and 0 interrupted-cancel session(s)', $output);
        $this->assertStringContainsString('0 marked cancelled', $output);
        $this->assertSame('uploading', MediaUploadSession::where('public_id', $id)->value('status'));
        $this->assertFileExists($this->root().'/resumable/'.$id.'/part-0.part');
    }

    public function test_append_complete_and_cancel_cannot_use_a_session_the_sweep_marked(): void
    {
        $media = $this->mediaSession();
        $kit = $this->kitSession();
        $this->own();
        $this->travel(49)->hours();
        $this->sweep(['--execute' => true]);
        $audits = AuditEvent::count();
        foreach ([[app(ResumableMediaUploads::class), $media, MediaUploadSession::class], [app(SoundKitUploads::class), $kit, SoundKitUploadSession::class]] as [$uploads, $id, $model]) {
            foreach (['append' => fn () => $uploads->append($id, 0, UploadedFile::fake()->createWithContent('chunk.bin', 'synthetic'), $this->actor),
                'complete' => fn () => $uploads->complete($id, $this->actor)] as $operation => $call) {
                try {
                    $call();
                    $this->fail($operation.' must be refused on a swept session.');
                } catch (ValidationException $refused) {
                    $this->assertArrayHasKey('upload', $refused->errors());
                }
            }
            // Cancelling an already cancelled session is the existing idempotent replay: it reports the terminal state
            // and changes nothing (no audit event, no revived row, no recreated directory).
            $this->assertSame(['cancelled', false], array_values(array_intersect_key($uploads->cancel($id, $this->actor), ['status' => 1, 'cleanupPending' => 1])));
            $this->assertSame('cancelled', $model::where('public_id', $id)->value('status'));
            $this->assertDirectoryDoesNotExist($this->root().'/resumable/'.$id);
        }
        $this->assertSame($audits, AuditEvent::count());
        $this->assertSame(0, MediaAsset::count());
    }

    public function test_a_directory_swapped_after_inspection_is_not_emptied(): void
    {
        $id = $this->mediaSession();
        $this->own();
        $directory = $this->root().'/resumable/'.$id;
        $inspected = lstat($directory);
        // An attacker swaps in a different directory with the same name and transport-looking contents.
        rename($directory, $this->root().'/resumable-original');
        mkdir($directory, 0700);
        file_put_contents($directory.'/part-0.part', 'synthetic swapped-in file');
        if (posix_geteuid() === 0) {
            chown($directory, $this->uid);
            chown($directory.'/part-0.part', $this->uid);
        }
        $remove = new \ReflectionMethod(AbandonedUploadSessions::class, 'remove');
        try {
            $remove->invoke(new AbandonedUploadSessions, $directory, ['part-0.part'], $this->uid, [$inspected['dev'], $inspected['ino']]);
            $this->fail('A swapped directory must not be emptied.');
        } catch (UploadSweepRefused $refused) {
            $this->assertSame('cleanup_incomplete', $refused->reason);
        }
        $this->assertSame('synthetic swapped-in file', file_get_contents($directory.'/part-0.part'));
        $this->assertFileExists($this->root().'/resumable-original/part-0.part');
    }

    public function test_a_linked_shared_storage_root_is_followed_once_but_a_linked_temp_root_is_refused(): void
    {
        $id = $this->mediaSession();
        $this->own();
        $this->travel(49)->hours();
        $real = $this->root();
        $link = dirname($real).'/linked-'.Str::uuid();
        symlink($real, $link);
        try {
            config(['filesystems.disks.local.root' => $link]);
            [$exit, $output] = $this->sweep(['--execute' => true]);
            $this->assertSame(0, $exit, $output);
            $this->assertStringContainsString('Directories removed: 1 (1 file(s))', $output);
            $this->assertDirectoryDoesNotExist($real.'/resumable/'.$id);

            // The temp root itself may not be a link, even to a private directory owned by the sweeping user.
            rename($real.'/resumable', $real.'/resumable-real');
            symlink($real.'/resumable-real', $real.'/resumable');
            [$exit, $output] = $this->sweep(['--execute' => true]);
            $this->assertSame(1, $exit);
            $this->assertStringContainsString('upload temp root is not a private (0700) directory', $output);
        } finally {
            config(['filesystems.disks.local.root' => $real]);
            unlink($link);
        }
    }
}
