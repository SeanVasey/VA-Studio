<?php

namespace Tests\Feature;

use App\Domain\Media\MediaFailure;
use App\Domain\Media\PrivateUploadParts;
use App\Domain\SoundKits\Models\SoundKitDraft;
use App\Domain\SoundKits\Models\SoundKitRevision;
use App\Domain\SoundKits\Models\SoundKitUploadSession;
use App\Domain\SoundKits\SoundKitDrafts;
use App\Domain\SoundKits\SoundKitIntake;
use App\Domain\SoundKits\SoundKitProcessor;
use App\Domain\SoundKits\SoundKitUploads;
use App\Jobs\ProcessSoundKit;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\Support\StemsFixtures;
use Tests\TestCase;

class SoundKitUploadsTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private User $actor;

    private SoundKitDraft $draft;

    private SoundKitUploads $uploads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        MediaFixtures::configure();
        $this->actor = LicenseFixtures::admin();
        $this->draft = app(SoundKitDrafts::class)->save(null, ['title' => 'Synthetic resumable samples', 'provenance' => 'Synthetic source'], $this->actor);
        $this->uploads = app(SoundKitUploads::class);
    }

    private function bytes(): string
    {
        return StemsFixtures::zip([['name' => 'Kick.wav'], ['name' => 'Bass.wav', 'bytes' => MediaFixtures::wav(0.35, 120)]]);
    }

    private function begin(?string $bytes = null, ?string $hash = null, string $name = 'samples.zip'): array
    {
        $bytes ??= $this->bytes();

        return $this->uploads->start($this->draft, 1, strlen($bytes), $hash ?? hash('sha256', $bytes), $name, $this->actor);
    }

    private function append(string $id, string $bytes, int $offset = 0, ?User $actor = null): array
    {
        return $this->uploads->append($id, $offset, UploadedFile::fake()->createWithContent('part.bin', $bytes), $actor ?? $this->actor);
    }

    private function rejected(callable $action): void
    {
        try {
            $action();
            $this->fail('Invalid kit upload was accepted.');
        } catch (ValidationException) {
            $this->assertSame(0, DB::transactionLevel());
        }
    }

    public function test_complete_retains_exact_kit_revision_then_processing_without_track_media_or_rights(): void
    {
        $bytes = $this->bytes();
        $session = $this->begin($bytes);
        $this->assertSame($this->draft->id, $session['kitId']);
        $this->assertSame(1, $session['expectedVersion']);
        $this->assertArrayNotHasKey('parts', $session);
        $this->assertArrayNotHasKey('trackId', $session);
        $this->assertArrayNotHasKey('role', $session);
        $this->append($session['id'], $bytes);
        $revision = $this->uploads->complete($session['id'], $this->actor);
        $this->assertSame('quarantined', $revision->status);
        $this->assertSame($bytes, Storage::disk('local')->get($revision->source_path));
        $this->assertSame(hash('sha256', $bytes), $revision->source_sha256);
        $this->assertSame(2, $this->draft->fresh()->version);
        $this->assertSame([], Storage::disk('local')->allFiles('resumable'));
        $this->assertSame($revision->id, $this->uploads->complete($session['id'], $this->actor)->id);
        $this->assertSame('completed', $this->uploads->inspect($session['id'], $this->actor)['status']);
        $this->assertFalse($this->uploads->inspect($session['id'], $this->actor)['cleanupPending']);
        $this->assertSame(1, SoundKitRevision::count());
        $this->assertSame(1, AuditEvent::where('action', 'sound_kit.upload.completed')->count());
        $this->assertSame(1, AuditEvent::where('action', 'sound_kit.revision.received')->count());
        Queue::assertPushed(ProcessSoundKit::class, 1);
        $this->assertSame('ready', app(SoundKitProcessor::class)->handle($revision->id)->status);
        foreach (['tracks', 'media_assets', 'offers'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
    }

    public function test_over_nine_mebibyte_archive_resumes_exact_chunks_and_refuses_conflicting_replay(): void
    {
        // A valid stored WAV member makes the source exceed the native server's 9 MiB whole-file ceiling.
        $bytes = StemsFixtures::zip([['name' => 'Long.wav', 'bytes' => MediaFixtures::wav(60)]]);
        $this->assertGreaterThan(9 * 1024 * 1024, strlen($bytes));
        $session = $this->begin($bytes);
        $first = substr($bytes, 0, SoundKitUploads::CHUNK_BYTES);
        $tail = substr($bytes, SoundKitUploads::CHUNK_BYTES);
        $this->rejected(fn () => $this->append($session['id'], $tail, SoundKitUploads::CHUNK_BYTES));
        $this->rejected(fn () => $this->append($session['id'], substr($first, 1)));
        $saved = $this->append($session['id'], $first);
        $this->assertSame($saved, $this->append($session['id'], $first));
        $this->rejected(fn () => $this->append($session['id'], str_repeat('x', strlen($first))));
        $this->rejected(fn () => $this->uploads->complete($session['id'], $this->actor));
        $this->assertCount(1, Storage::disk('local')->allFiles('resumable'));
        $this->append($session['id'], $tail, $saved['receivedBytes']);
        $revision = $this->uploads->complete($session['id'], $this->actor);
        $this->assertSame(hash('sha256', $bytes), hash_file('sha256', Storage::disk('local')->path($revision->source_path)));
        $this->assertSame(strlen($bytes), $revision->source_size_bytes);
    }

    public function test_start_replays_expired_identity_without_extension_and_four_uncleaned_sessions_bound_transport(): void
    {
        $session = $this->begin();
        $this->travel(10)->hours();
        $this->assertSame($session, $this->begin());
        $this->travel(15)->hours();
        $expired = $this->begin();
        $this->assertSame($session['id'], $expired['id']);
        $this->assertSame($session['expiresAt'], $expired['expiresAt']);
        $this->assertSame('expired', $expired['status']);
        for ($i = 0; $i < 3; $i++) {
            $this->begin(name: 'other-'.$i.'.zip');
        }
        $this->rejected(fn () => $this->begin(name: 'fifth.zip'));
        $this->rejected(fn () => $this->append($session['id'], $this->bytes()));
        $this->rejected(fn () => $this->uploads->complete($session['id'], $this->actor));
        $this->assertSame('cancelled', $this->uploads->cancel($session['id'], $this->actor)['status']);
        $this->assertSame('cancelled', $this->uploads->cancel($session['id'], $this->actor)['status']);
        $this->assertSame('uploading', $this->begin(name: 'fifth.zip')['status']);
        $this->assertSame(1, AuditEvent::where('action', 'sound_kit.upload.cancelled')->count());
    }

    public function test_stale_draft_or_changed_profile_refuses_completion_without_changing_retained_kit(): void
    {
        $session = $this->begin();
        $this->append($session['id'], $this->bytes());
        app(SoundKitDrafts::class)->save($this->draft, ['title' => 'New draft title', 'provenance' => 'New synthetic reference', 'version' => 1], $this->actor);
        $this->rejected(fn () => $this->uploads->complete($session['id'], $this->actor));
        $this->assertSame('uploading', $this->uploads->inspect($session['id'], $this->actor)['status']);
        $this->uploads->cancel($session['id'], $this->actor);
        $second = $this->uploads->start($this->draft, 2, strlen($this->bytes()), hash('sha256', $this->bytes()), 'new.zip', $this->actor);
        $this->append($second['id'], $this->bytes());
        config(['media.max_source_bytes' => 1000000]);
        $this->rejected(fn () => $this->uploads->complete($second['id'], $this->actor));
        $this->assertSame('cancelled', $this->uploads->cancel($second['id'], $this->actor)['status']);
        $this->assertSame(0, SoundKitRevision::count());
        $this->assertSame(2, $this->draft->fresh()->version);
    }

    public static function operations(): array
    {
        return ['inspect' => ['inspect'], 'append' => ['append'], 'complete' => ['complete'], 'cancel' => ['cancel']];
    }

    #[DataProvider('operations')]
    public function test_foreign_or_revoked_operator_cannot_read_or_write_a_session(string $operation): void
    {
        $session = $this->begin();
        $this->append($session['id'], $this->bytes());
        $before = SoundKitUploadSession::sole()->getAttributes();
        $files = Storage::disk('local')->allFiles();
        foreach ([LicenseFixtures::admin(), $this->actor] as $actor) {
            if ($actor->id === $this->actor->id) {
                DB::table('users')->where('id', $actor->id)->update(['is_admin' => false]);
            }
            try {
                $operation === 'append' ? $this->append($session['id'], $this->bytes(), actor: $actor) : $this->uploads->{$operation}($session['id'], $actor);
                $this->fail('Foreign or revoked authority was accepted.');
            } catch (AuthorizationException) {
            }
            $this->assertSame($before, SoundKitUploadSession::sole()->getAttributes());
            $this->assertSame($files, Storage::disk('local')->allFiles());
        }
        $this->assertSame(0, SoundKitRevision::count());
    }

    public static function mfaOperations(): array
    {
        return ['start' => ['start'], ...self::operations()];
    }

    #[DataProvider('mfaOperations')]
    public function test_required_mfa_is_current_at_each_operation(string $operation): void
    {
        $panel = Filament::getPanel('admin');
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        $this->actor->saveAppAuthenticationSecret($panel->getMultiFactorAuthenticationProviders()['app']->generateSecret());
        $session = $this->begin();
        $this->append($session['id'], $this->bytes());
        DB::table('users')->where('id', $this->actor->id)->update(['app_authentication_secret' => null]);
        $before = SoundKitUploadSession::sole()->getAttributes();
        $files = Storage::disk('local')->allFiles();
        try {
            match ($operation) {
                'start' => $this->begin(name: 'different.zip'),
                'append' => $this->append($session['id'], $this->bytes()),
                default => $this->uploads->{$operation}($session['id'], $this->actor),
            };
            $this->fail('Withdrawn required MFA was accepted.');
        } catch (AuthorizationException) {
        }
        $this->assertSame($before, SoundKitUploadSession::sole()->getAttributes());
        $this->assertSame($files, Storage::disk('local')->allFiles());
    }

    public function test_completion_commit_response_loss_retains_exact_revision_and_defers_cleanup_until_replay(): void
    {
        $session = $this->begin();
        $this->append($session['id'], $this->bytes());
        $fire = true;
        DB::connection()->getEventDispatcher()->listen(TransactionCommitted::class, function () use (&$fire): void {
            if ($fire && DB::transactionLevel() === 0 && SoundKitUploadSession::where('status', 'completed')->exists()) {
                $fire = false;
                throw new RuntimeException('Synthetic lost kit completion acknowledgement');
            }
        });
        try {
            $this->uploads->complete($session['id'], $this->actor);
            $this->fail('Synthetic uncertain commit was swallowed.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Synthetic lost kit', $error->getMessage());
        }
        $revision = SoundKitRevision::sole();
        $this->assertTrue($this->uploads->inspect($session['id'], $this->actor)['cleanupPending']);
        $this->assertCount(2, Storage::disk('local')->allFiles('resumable'));
        $this->assertSame($revision->id, $this->uploads->complete($session['id'], $this->actor)->id);
        $this->assertSame([], Storage::disk('local')->allFiles('resumable'));
        $this->assertSame(1, SoundKitRevision::count());
        $this->assertSame(1, AuditEvent::where('action', 'sound_kit.upload.completed')->count());
        $this->assertSame($this->bytes(), Storage::disk('local')->get($revision->source_path));
    }

    public function test_outer_completion_rollback_preserves_one_canonical_source_and_retry_dispatches_only_after_commit(): void
    {
        $session = $this->begin();
        $this->append($session['id'], $this->bytes());
        $event = 'eloquent.created: '.AuditEvent::class;
        Event::listen($event, function (AuditEvent $audit): void {
            if ($audit->action === 'sound_kit.upload.completed') {
                throw new RuntimeException('Synthetic outer rollback');
            }
        });
        try {
            for ($i = 0; $i < 3; $i++) {
                try {
                    $this->uploads->complete($session['id'], $this->actor);
                    $this->fail('Synthetic completion failure was swallowed.');
                } catch (RuntimeException $error) {
                    $this->assertSame('Synthetic outer rollback', $error->getMessage());
                }
                $this->assertSame(0, SoundKitRevision::count());
                $this->assertSame(1, $this->draft->fresh()->version);
                $this->assertSame('uploading', SoundKitUploadSession::sole()->status);
                $this->assertCount(1, array_filter(Storage::disk('local')->allFiles('sound-kits'), fn ($path) => str_ends_with($path, 'source.zip')));
                Queue::assertNothingPushed();
            }
        } finally {
            Event::forget($event);
        }
        $this->assertSame('quarantined', $this->uploads->complete($session['id'], $this->actor)->status);
        Queue::assertPushed(ProcessSoundKit::class, 1);
    }

    public function test_chunk_commit_loss_and_row_rollback_reuse_exact_parts_without_disk_amplification(): void
    {
        $session = $this->begin();
        $event = 'eloquent.updated: '.SoundKitUploadSession::class;
        Event::listen($event, fn () => throw new RuntimeException('Synthetic chunk rollback'));
        try {
            for ($i = 0; $i < 2; $i++) {
                try {
                    $this->append($session['id'], $this->bytes());
                } catch (RuntimeException $error) {
                    $this->assertSame('Synthetic chunk rollback', $error->getMessage());
                }
                $this->assertCount(1, Storage::disk('local')->allFiles('resumable'));
                $this->assertSame(0, SoundKitUploadSession::sole()->received_bytes);
            }
        } finally {
            Event::forget($event);
        }
        $fire = true;
        DB::connection()->getEventDispatcher()->listen(TransactionCommitted::class, function () use (&$fire): void {
            if ($fire && DB::transactionLevel() === 0 && SoundKitUploadSession::sole()->received_bytes > 0) {
                $fire = false;
                throw new RuntimeException('Synthetic lost part acknowledgement');
            }
        });
        try {
            $this->append($session['id'], $this->bytes());
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic lost part acknowledgement', $error->getMessage());
        }
        $this->assertSame(strlen($this->bytes()), $this->append($session['id'], $this->bytes())['receivedBytes']);
        $this->assertCount(1, Storage::disk('local')->allFiles('resumable'));
    }

    public function test_false_digest_hostile_archive_and_corrupted_parts_cannot_become_kit_revisions(): void
    {
        $badHash = $this->begin(hash: str_repeat('a', 64));
        $this->append($badHash['id'], $this->bytes());
        try {
            $this->uploads->complete($badHash['id'], $this->actor);
            $this->fail('False digest became a revision.');
        } catch (RuntimeException) {
        }
        $this->uploads->cancel($badHash['id'], $this->actor);
        $hostile = StemsFixtures::zip([['name' => '../escape.wav']]);
        $unsafe = $this->begin($hostile);
        $this->append($unsafe['id'], $hostile);
        try {
            $this->uploads->complete($unsafe['id'], $this->actor);
            $this->fail('Hostile archive became a revision.');
        } catch (MediaFailure $error) {
            $this->assertSame('unsafe_archive', $error->failureCode);
        }
        $valid = $this->begin(name: 'different.zip');
        $this->append($valid['id'], $this->bytes());
        file_put_contents(app(PrivateUploadParts::class)->directory($valid['id']).'/part-0.part', 'CHANGED');
        try {
            $this->uploads->complete($valid['id'], $this->actor);
            $this->fail('Changed part became a revision.');
        } catch (RuntimeException) {
        }
        $this->assertSame(0, SoundKitRevision::count());
        $this->assertSame(1, $this->draft->fresh()->version);
        Queue::assertNothingPushed();
    }

    public function test_bounds_path_fields_nested_transaction_and_unowned_intake_adapter_fail_closed(): void
    {
        foreach ([[0, 'samples.zip', str_repeat('a', 64)], [209715201, 'samples.zip', str_repeat('a', 64)],
            [100, '../samples.zip', str_repeat('a', 64)], [100, 'samples.mid', str_repeat('a', 64)], [100, 'samples.zip', 'bad']] as [$size, $name, $hash]) {
            $this->rejected(fn () => $this->uploads->start($this->draft, 1, $size, $hash, $name, $this->actor));
        }
        try {
            $this->uploads->inspect('../../outside', $this->actor);
            $this->fail('Unsafe session identifier was accepted.');
        } catch (AuthorizationException) {
        }
        DB::beginTransaction();
        try {
            $this->begin();
            $this->fail('Caller transaction was accepted.');
        } catch (LogicException) {
        } finally {
            DB::rollBack();
        }
        $session = $this->begin();
        try {
            app(SoundKitIntake::class)->handleResumable(SoundKitUploadSession::sole(), UploadedFile::fake()->createWithContent('samples.zip', $this->bytes()), $this->actor);
            $this->fail('Internal adapter was callable without its owning transaction.');
        } catch (LogicException) {
        }
        $this->assertSame(0, SoundKitRevision::count());
        $this->assertSame(0, $this->uploads->inspect($session['id'], $this->actor)['receivedBytes']);
    }
}
