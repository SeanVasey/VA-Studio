<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Track;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Models\MediaUploadSession;
use App\Domain\Media\PrivateUploadParts;
use App\Domain\Media\ResumableMediaUploads;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\Audit\AuditEvent;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\TestCase;

class ResumableMediaUploadsTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private User $actor;

    private Track $track;

    private ResumableMediaUploads $uploads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        $this->actor = LicenseFixtures::admin();
        $this->track = Track::create(['title' => 'SYNTHETIC resumable upload', 'slug' => 'resumable-fixture']);
        $this->uploads = app(ResumableMediaUploads::class);
    }

    private function begin(?string $bytes = null, ?string $hash = null): array
    {
        $bytes ??= MediaFixtures::png();

        return $this->uploads->start($this->track, 'artwork', strlen($bytes), $hash ?? hash('sha256', $bytes), 'synthetic.png', $this->actor);
    }

    private function append(string $id, string $bytes, int $offset = 0, ?User $actor = null): array
    {
        return $this->uploads->append($id, $offset, UploadedFile::fake()->createWithContent('chunk.bin', $bytes), $actor ?? $this->actor);
    }

    private function rejected(callable $action): void
    {
        try {
            $action();
            $this->fail('Invalid upload operation was accepted.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('upload', $error->errors());
        }
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_whole_file_completion_uses_existing_quarantine_and_replays_the_same_asset(): void
    {
        $bytes = MediaFixtures::png();
        $session = $this->begin($bytes);
        $this->assertSame(0, $session['receivedBytes']);
        $this->assertSame('uploading', $session['status']);
        $this->assertArrayNotHasKey('parts', $session);
        $this->assertArrayNotHasKey('storage_path', $session);
        $this->append($session['id'], $bytes);
        $asset = $this->uploads->complete($session['id'], $this->actor);
        $this->assertSame('quarantined', $asset->status);
        $this->assertSame(hash('sha256', $bytes), $asset->sha256);
        $this->assertSame($bytes, Storage::disk('local')->get($asset->storage_path));
        $this->assertSame([], Storage::disk('local')->allFiles('resumable'));
        $this->assertSame($asset->id, $this->uploads->complete($session['id'], $this->actor)->id);
        $this->assertSame('completed', $this->uploads->inspect($session['id'], $this->actor)['status']);
        $this->assertDatabaseCount('media_assets', 1);
        $this->assertSame(1, AuditEvent::where('action', 'media.upload.quarantined')->count());
        $this->assertSame(1, AuditEvent::where('action', 'media.upload.session_completed')->count());
    }

    public function test_start_replays_exact_identity_without_extending_expiry_or_consuming_another_slot(): void
    {
        $session = $this->begin();
        $this->travel(10)->hours();
        $this->assertSame($session, $this->begin());
        $this->travel(15)->hours();
        $again = $this->begin();
        $this->assertSame($session['id'], $again['id']);
        $this->assertSame($session['expiresAt'], $again['expiresAt']);
        $this->assertSame('expired', $again['status']);
        $this->assertDatabaseCount('media_upload_sessions', 1);
        $this->assertSame(1, AuditEvent::where('action', 'media.upload.session_started')->count());
    }

    public function test_chunk_row_rollback_reuses_one_exact_private_part_without_amplifying_disk_usage(): void
    {
        $session = $this->begin();
        $event = 'eloquent.updated: '.MediaUploadSession::class;
        Event::listen($event, fn () => throw new RuntimeException('SYNTHETIC chunk row rollback.'));
        try {
            for ($attempt = 0; $attempt < 3; $attempt++) {
                try {
                    $this->append($session['id'], MediaFixtures::png());
                    $this->fail('Chunk fault was swallowed.');
                } catch (RuntimeException $error) {
                    $this->assertStringContainsString('chunk row rollback', $error->getMessage());
                }
                $this->assertSame(0, MediaUploadSession::where('public_id', $session['id'])->firstOrFail()->received_bytes);
                $this->assertCount(1, Storage::disk('local')->allFiles('resumable'));
            }
        } finally {
            Event::forget($event);
        }
        $this->assertSame(strlen(MediaFixtures::png()), $this->append($session['id'], MediaFixtures::png())['receivedBytes']);
        $this->assertCount(1, Storage::disk('local')->allFiles('resumable'));
    }

    public function test_interrupted_multichunk_upload_resumes_exactly_and_duplicate_bytes_are_idempotent(): void
    {
        $bytes = MediaFixtures::png().str_repeat('x', ResumableMediaUploads::CHUNK_BYTES);
        $session = $this->begin($bytes);
        $first = substr($bytes, 0, ResumableMediaUploads::CHUNK_BYTES);
        $tail = substr($bytes, ResumableMediaUploads::CHUNK_BYTES);
        $this->rejected(fn () => $this->append($session['id'], $tail, ResumableMediaUploads::CHUNK_BYTES));
        $this->rejected(fn () => $this->append($session['id'], substr($first, 1)));
        $saved = $this->append($session['id'], $first);
        $files = Storage::disk('local')->allFiles('resumable');
        $this->assertSame($saved, app(ResumableMediaUploads::class)->inspect($session['id'], $this->actor));
        $this->assertSame($saved, $this->append($session['id'], $first));
        $this->assertSame($files, Storage::disk('local')->allFiles('resumable'));
        $this->rejected(fn () => $this->append($session['id'], str_repeat('z', strlen($first))));
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->rejected(fn () => $this->uploads->complete($session['id'], $this->actor));
            $this->assertCount(1, Storage::disk('local')->allFiles('resumable'));
        }
        $this->append($session['id'], $tail, $saved['receivedBytes']);
        $asset = $this->uploads->complete($session['id'], $this->actor);
        $this->assertSame($bytes, Storage::disk('local')->get($asset->storage_path));
    }

    public static function operations(): array
    {
        return ['inspect' => ['inspect'], 'append' => ['append'], 'complete' => ['complete'], 'cancel' => ['cancel']];
    }

    #[DataProvider('operations')]
    public function test_other_operator_and_revoked_creator_cannot_use_any_session_operation(string $method): void
    {
        $bytes = MediaFixtures::png();
        $session = $this->begin($bytes);
        $this->append($session['id'], $bytes);
        $other = LicenseFixtures::admin();
        $before = MediaUploadSession::where('public_id', $session['id'])->firstOrFail()->getAttributes();
        $files = Storage::disk('local')->allFiles('resumable');
        foreach ([$other, $this->actor] as $actor) {
            if ($actor->id === $this->actor->id) {
                DB::table('users')->where('id', $actor->id)->update(['is_admin' => false]);
            }
            try {
                $method === 'append' ? $this->append($session['id'], $bytes, actor: $actor) : $this->uploads->{$method}($session['id'], $actor);
                $this->fail('Unavailable authority used a session.');
            } catch (AuthorizationException) {
            }
            $this->assertSame($before, MediaUploadSession::where('public_id', $session['id'])->firstOrFail()->getAttributes());
            $this->assertSame($files, Storage::disk('local')->allFiles('resumable'));
        }
        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_completed_replay_still_requires_current_creator_authority(): void
    {
        $session = $this->begin();
        $this->append($session['id'], MediaFixtures::png());
        $asset = $this->uploads->complete($session['id'], $this->actor);
        DB::table('users')->where('id', $this->actor->id)->update(['email_verified_at' => null]);
        $this->expectException(AuthorizationException::class);
        $this->uploads->complete($session['id'], $this->actor);
    }

    public static function mfaOperations(): array
    {
        return ['start' => ['start'], ...self::operations()];
    }

    #[DataProvider('mfaOperations')]
    public function test_required_mfa_is_rechecked_after_admission_before_the_actor_read(string $method): void
    {
        $panel = Filament::getPanel('admin');
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        $this->actor->saveAppAuthenticationSecret($panel->getMultiFactorAuthenticationProviders()['app']->generateSecret());
        $session = $this->begin();
        $this->append($session['id'], MediaFixtures::png());
        $before = MediaUploadSession::sole()->getAttributes();
        $files = Storage::disk('local')->allFiles();
        $audits = AuditEvent::count();
        $this->assertTrue(AdminMultiFactor::satisfiedBy($this->actor));
        $withdrawn = false;
        DB::connection()->beforeExecuting(function (string $sql) use (&$withdrawn): void {
            if (! $withdrawn && DB::transactionLevel() === 1
                && preg_match('/\Aselect\b.*\bfrom ["`]users["`]/i', $sql)) {
                $withdrawn = true;
                DB::table('users')->where('id', $this->actor->id)->update(['app_authentication_secret' => null]);
            }
        });
        try {
            match ($method) {
                'start' => $this->uploads->start($this->track, 'artwork', strlen(MediaFixtures::png()), hash('sha256', MediaFixtures::png()), 'new-synthetic.png', $this->actor),
                'append' => $this->append($session['id'], MediaFixtures::png()),
                default => $this->uploads->{$method}($session['id'], $this->actor),
            };
            $this->fail('A pre-admitted operator retained upload authority after required MFA withdrawal.');
        } catch (AuthorizationException) {
        }
        $this->assertTrue($withdrawn);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame($before, MediaUploadSession::sole()->getAttributes());
        $this->assertSame($files, Storage::disk('local')->allFiles());
        $this->assertSame($audits, AuditEvent::count());
        $this->assertDatabaseCount('media_upload_sessions', 1);
        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_existing_size_role_digest_and_concurrent_session_limits_remain_bounded(): void
    {
        foreach ([['artwork', 20 * 1024 * 1024 + 1, str_repeat('a', 64)], ['master_wav', 200 * 1024 * 1024 + 1, str_repeat('a', 64)],
            ['preview_tagged', 100, str_repeat('a', 64)], ['stems_zip', 0, str_repeat('a', 64)], ['artwork', 10, '../not-a-hash']] as [$role, $size, $hash]) {
            $this->rejected(fn () => $this->uploads->start($this->track, $role, $size, $hash, 'synthetic', $this->actor));
        }
        for ($i = 0; $i < ResumableMediaUploads::MAX_ACTIVE; $i++) {
            $session = $this->uploads->start($this->track, 'artwork', strlen(MediaFixtures::png()), hash('sha256', MediaFixtures::png()), 'synthetic-'.$i.'.png', $this->actor);
        }
        $this->rejected(fn () => $this->begin());
        $this->travel(25)->hours();
        $this->rejected(fn () => $this->begin()); // Expiration does not waive retained-disk capacity.
        $this->assertSame('expired', $this->uploads->inspect($session['id'], $this->actor)['status']);
        $this->uploads->cancel($session['id'], $this->actor);
        $this->assertSame('uploading', $this->begin()['status']);
    }

    public function test_expiry_and_cancellation_preserve_originals_and_allow_idempotent_cleanup(): void
    {
        Storage::disk('local')->put('quarantine/retained/source.upload', 'RETAINED ORIGINAL');
        $session = $this->begin();
        $this->append($session['id'], MediaFixtures::png());
        $this->travel(25)->hours();
        $this->rejected(fn () => $this->append($session['id'], MediaFixtures::png()));
        $this->rejected(fn () => $this->uploads->complete($session['id'], $this->actor));
        $this->assertSame('cancelled', $this->uploads->cancel($session['id'], $this->actor)['status']);
        $this->assertSame('cancelled', $this->uploads->cancel($session['id'], $this->actor)['status']);
        $this->assertSame([], Storage::disk('local')->allFiles('resumable'));
        $this->assertSame('RETAINED ORIGINAL', Storage::disk('local')->get('quarantine/retained/source.upload'));
        $this->assertSame(1, AuditEvent::where('action', 'media.upload.session_cancelled')->count());
        $this->rejected(fn () => $this->append($session['id'], MediaFixtures::png()));
    }

    public function test_false_mime_and_changed_retained_parts_never_create_media(): void
    {
        $session = $this->begin('not an image');
        $this->append($session['id'], 'not an image');
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->rejected(fn () => $this->uploads->complete($session['id'], $this->actor));
            $this->assertCount(2, Storage::disk('local')->allFiles('resumable'));
        }
        $this->assertDatabaseCount('media_assets', 0);
        $this->uploads->cancel($session['id'], $this->actor);
        $session = $this->begin();
        $this->append($session['id'], MediaFixtures::png());
        $part = MediaUploadSession::where('public_id', $session['id'])->firstOrFail()->parts[0];
        $path = app(PrivateUploadParts::class)->directory($session['id']).'/'.$part['token'].'.part';
        file_put_contents($path, str_repeat('x', $part['size']));
        try {
            $this->append($session['id'], MediaFixtures::png());
            $this->fail('Corrupt retained part was accepted as a successful duplicate.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('changed', $error->getMessage());
        }
        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_declared_whole_file_digest_must_match_exact_assembled_bytes(): void
    {
        $session = $this->begin(hash: str_repeat('0', 64));
        $this->append($session['id'], MediaFixtures::png());
        try {
            $this->uploads->complete($session['id'], $this->actor);
            $this->fail('A false final digest became media.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('declared bytes', $error->getMessage());
        }
        $this->assertDatabaseCount('media_assets', 0);
        $this->assertSame('uploading', MediaUploadSession::where('public_id', $session['id'])->firstOrFail()->status);
        $this->assertCount(1, Storage::disk('local')->allFiles('resumable'));
    }

    public function test_error_after_root_commit_retains_spool_and_replays_one_durable_asset(): void
    {
        $session = $this->begin();
        $this->append($session['id'], MediaFixtures::png());
        $raised = false;
        Event::listen(TransactionCommitted::class, function (TransactionCommitted $event) use (&$raised): void {
            if (! $raised && $event->connection->transactionLevel() === 0 && MediaAsset::exists()) {
                $raised = true;
                throw new RuntimeException('SYNTHETIC lost root commit acknowledgement.');
            }
        });
        try {
            $this->uploads->complete($session['id'], $this->actor);
            $this->fail('The lost acknowledgement was swallowed.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('lost root commit', $error->getMessage());
        } finally {
            Event::forget(TransactionCommitted::class);
        }
        $this->assertTrue($raised);
        $this->assertSame(0, DB::transactionLevel());
        $asset = MediaAsset::sole();
        $this->assertSame($asset->id, MediaUploadSession::where('public_id', $session['id'])->firstOrFail()->asset_id);
        $this->assertCount(2, Storage::disk('local')->allFiles('resumable'));
        $this->assertSame(MediaFixtures::png(), Storage::disk('local')->get($asset->storage_path));
        $this->assertTrue($this->uploads->inspect($session['id'], $this->actor)['cleanupPending']);
        for ($i = 0; $i < 3; $i++) {
            $this->uploads->start($this->track, 'artwork', strlen(MediaFixtures::png()), hash('sha256', MediaFixtures::png()), 'other-'.$i.'.png', $this->actor);
        }
        $this->rejected(fn () => $this->begin());
        $this->assertSame($asset->id, $this->uploads->complete($session['id'], $this->actor)->id);
        $this->assertDatabaseCount('media_assets', 1);
        $this->assertSame([], Storage::disk('local')->allFiles('resumable'));
        $this->assertFalse($this->uploads->inspect($session['id'], $this->actor)['cleanupPending']);
        $this->assertSame('uploading', $this->begin()['status']);
    }

    public function test_outer_rollback_after_intake_savepoint_retains_uncertain_quarantine_for_reconciliation(): void
    {
        $session = $this->begin();
        $this->append($session['id'], MediaFixtures::png());
        $event = 'eloquent.created: '.AuditEvent::class;
        Event::listen($event, function (AuditEvent $audit): void {
            if ($audit->action === 'media.upload.session_completed') {
                throw new RuntimeException('SYNTHETIC outer finalization failure.');
            }
        });
        try {
            $this->uploads->complete($session['id'], $this->actor);
            $this->fail('Outer rollback error was swallowed.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('outer finalization', $error->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertDatabaseCount('media_assets', 0);
        $this->assertSame('uploading', MediaUploadSession::where('public_id', $session['id'])->firstOrFail()->status);
        $orphans = Storage::disk('local')->allFiles('quarantine');
        $this->assertCount(1, $orphans);
        $this->assertSame(MediaFixtures::png(), Storage::disk('local')->get($orphans[0]));
        $asset = $this->uploads->complete($session['id'], $this->actor);
        $this->assertDatabaseCount('media_assets', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles('quarantine'));
        $this->assertSame(MediaFixtures::png(), Storage::disk('local')->get($asset->storage_path));
    }

    public function test_unsafe_session_paths_and_nested_transactions_cannot_bypass_boundaries(): void
    {
        try {
            $this->uploads->inspect('../../quarantine', $this->actor);
            $this->fail('An unsafe identifier was accepted.');
        } catch (AuthorizationException) {
        }
        DB::beginTransaction();
        try {
            $this->begin();
            $this->fail('Caller-owned transaction was accepted.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('root transaction', $error->getMessage());
            $this->assertSame(1, DB::transactionLevel());
        } finally {
            DB::rollBack();
        }
        $this->assertDatabaseCount('media_upload_sessions', 0);
    }

    public function test_migration_preserves_populated_sessions_and_refuses_unjournaled_schema_reuse(): void
    {
        $migration = require database_path('migrations/2026_10_06_000038_resumable_media_uploads.php');
        try {
            $migration->up();
            $this->fail('Existing table was repurposed.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('unjournaled', $error->getMessage());
        }
        $session = $this->begin();
        try {
            $migration->down();
            $this->fail('Retained upload sessions were erased.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('Retained upload sessions', $error->getMessage());
        }
        $this->assertSame($session, $this->uploads->inspect($session['id'], $this->actor));
    }

    public function test_empty_migration_down_and_up_preserve_supported_foreign_key_layout(): void
    {
        $migration = require database_path('migrations/2026_10_06_000038_resumable_media_uploads.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('media_upload_sessions'));
        $migration->up();
        $this->assertCount(3, Schema::getForeignKeys('media_upload_sessions'));
        $this->assertSame('uploading', $this->begin()['status']);
    }

    public function test_retained_part_symlink_cannot_expose_or_delete_an_unrelated_private_file(): void
    {
        $session = $this->begin();
        $this->append($session['id'], MediaFixtures::png());
        Storage::disk('local')->put('retained-original.txt', 'PRIVATE ORIGINAL');
        $original = Storage::disk('local')->path('retained-original.txt');
        $part = app(PrivateUploadParts::class)->directory($session['id']).'/part-0.part';
        unlink($part);
        symlink($original, $part);
        try {
            $this->uploads->complete($session['id'], $this->actor);
            $this->fail('A symbolic-link source became media.');
        } catch (RuntimeException) {
        }
        try {
            $this->uploads->cancel($session['id'], $this->actor);
            $this->fail('Unexpected cleanup contents were silently followed.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('retained for inspection', $error->getMessage());
        }
        $this->assertSame('cancelled', MediaUploadSession::where('public_id', $session['id'])->firstOrFail()->status);
        $this->assertSame('PRIVATE ORIGINAL', file_get_contents($original));
        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_migration_refuses_temporary_shadow_and_foreign_index_without_removing_either(): void
    {
        $migration = require database_path('migrations/2026_10_06_000038_resumable_media_uploads.php');
        DB::statement('CREATE TEMPORARY TABLE media_upload_sessions (id INTEGER)');
        try {
            foreach (['up', 'down'] as $direction) {
                try {
                    $migration->{$direction}();
                    $this->fail('Temporary shadow was accepted.');
                } catch (LogicException $error) {
                    $this->assertStringContainsString('Temporary upload session shadow', $error->getMessage());
                }
            }
        } finally {
            DB::statement(DB::getDriverName() === 'mysql' ? 'DROP TEMPORARY TABLE media_upload_sessions' : 'DROP TABLE temp.media_upload_sessions');
        }
        DB::statement('CREATE INDEX foreign_upload_index ON media_upload_sessions (role)');
        try {
            $migration->down();
            $this->fail('Foreign schema object was erased.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('Unexpected upload session index', $error->getMessage());
        }
        $this->assertContains('foreign_upload_index', array_column(Schema::getIndexes('media_upload_sessions'), 'name'));
        $this->assertTrue(Schema::hasTable('media_upload_sessions'));
    }
}
