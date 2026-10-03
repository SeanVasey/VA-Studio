<?php

namespace Tests\Feature;

use App\Application\Media\IngestMediaUpload;
use App\Domain\Catalog\Models\Track;
use App\Domain\Media\Models\MediaAsset;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

class MediaUploadCommitRecoveryTest extends TestCase
{
    // A RefreshDatabase outer transaction would turn the root-commit case into a savepoint test.
    use FinalizationDatabaseMigrations;

    private User $actor;

    private Track $track;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        $this->actor = User::factory()->create();
        $this->actor->forceFill(['is_admin' => true])->save();
        $this->track = Track::create(['title' => 'Upload recovery test', 'slug' => 'upload-recovery-test']);
    }

    public function test_error_after_root_commit_preserves_durable_quarantine_bytes_and_audit(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $upload = $this->artwork();
        $failure = new PDOException('Synthetic error after the upload root commit.');
        $raised = false;
        Event::listen(TransactionCommitted::class, function (TransactionCommitted $event) use ($failure, &$raised): void {
            if (! $raised && $event->connection->transactionLevel() === 0 && MediaAsset::query()->exists()) {
                $raised = true;
                throw $failure;
            }
        });

        try {
            app(IngestMediaUpload::class)->handle($this->track, $upload, 'artwork', $this->actor);
            $this->fail('The root-commit listener error was swallowed.');
        } catch (PDOException $exception) {
            $this->assertSame($failure, $exception);
        } finally {
            Event::forget(TransactionCommitted::class);
        }

        $this->assertTrue($raised);
        $this->assertSame(0, DB::transactionLevel());
        $asset = MediaAsset::sole();
        $this->assertQuarantinedSource($asset, $upload);
        $this->assertUploadAudit($asset);
        $this->get('/media/'.$asset->id)->assertNotFound();
    }

    public function test_ambiguous_completion_with_no_visible_row_retains_bytes_and_retry_uses_a_new_path(): void
    {
        $connection = DB::connection();
        $connection->beginTransaction();
        $upload = $this->artwork();
        $failure = new PDOException('Synthetic uncertain upload commit.');
        $retained = null;
        Event::listen(TransactionCommitted::class, function (TransactionCommitted $event) use ($failure, &$retained): void {
            if ($retained === null && $event->connection->transactionLevel() === 1) {
                $retained = MediaAsset::sole();
                // Controlled savepoint fault: make the completed callback's rows invisible.
                // This does not claim to reproduce a server-side lost commit acknowledgement.
                $event->connection->getPdo()->exec($event->connection->getQueryGrammar()->compileSavepointRollBack('trans2'));
                throw $failure;
            }
        });

        try {
            try {
                app(IngestMediaUpload::class)->handle($this->track, $upload, 'artwork', $this->actor);
                $this->fail('The uncertain commit error was swallowed.');
            } catch (PDOException $exception) {
                $this->assertSame($failure, $exception);
            } finally {
                Event::forget(TransactionCommitted::class);
            }

            $this->assertNotNull($retained);
            $this->assertSame(1, $connection->transactionLevel());
            $this->assertDatabaseCount('media_assets', 0);
            $this->assertDatabaseCount('audit_events', 0);
            $this->assertQuarantinedSource($retained, $upload);

            $retry = app(IngestMediaUpload::class)->handle($this->track, $upload, 'artwork', $this->actor);
            $connection->commit();
            $this->assertNotSame($retained->storage_path, $retry->storage_path);
            $this->assertQuarantinedSource($retained, $upload);
            $this->assertQuarantinedSource($retry->fresh(), $upload);
            $this->assertUploadAudit($retry);
            $this->assertDatabaseCount('media_assets', 1);
            $this->assertCount(2, Storage::disk('local')->allFiles('quarantine'));
        } finally {
            Event::forget(TransactionCommitted::class);
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack(0);
            }
        }
    }

    public static function precommitTransactionLevels(): array
    {
        return ['root transaction' => [false], 'nested transaction' => [true]];
    }

    #[DataProvider('precommitTransactionLevels')]
    public function test_confirmed_precommit_rollback_cleans_only_the_failed_upload(bool $nested): void
    {
        $upload = $this->artwork();
        $previous = app(IngestMediaUpload::class)->handle($this->track, $upload, 'artwork', $this->actor);
        $failure = new RuntimeException('Synthetic failure before the upload callback completes.');
        $attemptedPath = null;
        $eventName = 'eloquent.created: '.AuditEvent::class;
        Event::listen($eventName, function (AuditEvent $event) use ($failure, &$attemptedPath): void {
            if ($event->action === 'media.upload.quarantined') {
                $attemptedPath = MediaAsset::findOrFail($event->subject_id)->storage_path;
                throw $failure;
            }
        });
        $connection = DB::connection();
        if ($nested) {
            $connection->beginTransaction();
        }

        try {
            try {
                app(IngestMediaUpload::class)->handle($this->track, $this->artwork(), 'artwork', $this->actor);
                $this->fail('The precommit audit failure was swallowed.');
            } catch (RuntimeException $exception) {
                $this->assertSame($failure, $exception);
            } finally {
                Event::forget($eventName);
            }

            $this->assertNotNull($attemptedPath);
            $this->assertNotSame($previous->storage_path, $attemptedPath);
            $this->assertSame($nested ? 1 : 0, $connection->transactionLevel());
            Storage::disk('local')->assertMissing($attemptedPath);
            $this->assertSame([$previous->id], MediaAsset::pluck('id')->all());
            $this->assertQuarantinedSource($previous->fresh(), $upload);
            $this->assertUploadAudit($previous);
            $this->assertSame([$previous->storage_path], Storage::disk('local')->allFiles('quarantine'));
        } finally {
            Event::forget($eventName);
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack(0);
            }
        }
    }

    public function test_failed_nested_rollback_keeps_bytes_for_pending_rows_that_can_still_commit(): void
    {
        $connection = DB::connection();
        $connection->beginTransaction();
        $upload = $this->artwork();
        $failure = new RuntimeException('Synthetic failure before the upload callback completes.');
        $raised = false;
        $eventName = 'eloquent.created: '.AuditEvent::class;
        Event::listen($eventName, function (AuditEvent $event) use ($connection, $failure, &$raised): void {
            if ($event->action === 'media.upload.quarantined') {
                // Both rows now exist. Removing the savepoint makes Laravel's rollback fail.
                $connection->getPdo()->exec('RELEASE SAVEPOINT trans2');
                $raised = true;
                throw $failure;
            }
        });

        try {
            try {
                app(IngestMediaUpload::class)->handle($this->track, $upload, 'artwork', $this->actor);
                $this->fail('The failed rollback was swallowed.');
            } catch (PDOException $exception) {
                $this->assertNotSame($failure, $exception);
            } finally {
                Event::forget($eventName);
            }

            $this->assertTrue($raised);
            $this->assertSame(2, $connection->transactionLevel());
            $asset = MediaAsset::sole();
            $this->assertQuarantinedSource($asset, $upload);
            $this->assertUploadAudit($asset);

            // The first commit closes Laravel's nested level; the second commits the PDO transaction.
            $connection->commit();
            $connection->commit();
            $this->assertSame(0, $connection->transactionLevel());
            $this->assertQuarantinedSource($asset->fresh(), $upload);
            $this->assertUploadAudit($asset);
        } finally {
            Event::forget($eventName);
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack(0);
            }
        }
    }

    public function test_nested_lock_wait_failure_keeps_bytes_when_laravel_restores_depth_without_rollback(): void
    {
        $connection = DB::connection();
        $connection->beginTransaction();
        $upload = $this->artwork();
        $failure = new PDOException('Lock wait timeout exceeded; try restarting transaction');
        $raised = false;
        $eventName = 'eloquent.created: '.AuditEvent::class;
        Event::listen($eventName, function (AuditEvent $event) use ($failure, &$raised): void {
            if ($event->action === 'media.upload.quarantined') {
                // Exercise Laravel's real nested concurrency-error handler without a live lock race.
                $raised = true;
                throw $failure;
            }
        });

        try {
            try {
                app(IngestMediaUpload::class)->handle($this->track, $upload, 'artwork', $this->actor);
                $this->fail('The nested concurrency error was swallowed.');
            } catch (DeadlockException $exception) {
                $this->assertSame($failure, $exception->getPrevious());
            } finally {
                Event::forget($eventName);
            }

            $this->assertTrue($raised);
            $this->assertSame(1, $connection->transactionLevel());
            $asset = MediaAsset::sole();
            $this->assertQuarantinedSource($asset, $upload);
            $this->assertUploadAudit($asset);
            $connection->commit();
            $this->assertSame(0, $connection->transactionLevel());
            $this->assertQuarantinedSource($asset->fresh(), $upload);
            $this->assertUploadAudit($asset);
        } finally {
            Event::forget($eventName);
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack(0);
            }
        }
    }

    private function artwork(): UploadedFile
    {
        // Genuine 1x1 PNG bytes; no customer assets in test fixtures.
        return UploadedFile::fake()->createWithContent('image.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
    }

    private function assertQuarantinedSource(MediaAsset $asset, UploadedFile $upload): void
    {
        $disk = Storage::disk('local');
        $this->assertSame('quarantined', $asset->status);
        $this->assertNull($asset->verified_by);
        $this->assertSame('local', $asset->disk);
        $this->assertStringStartsWith('quarantine/', $asset->storage_path);
        $this->assertSame($upload->getSize(), $asset->size_bytes);
        $this->assertSame(hash_file('sha256', $upload->getRealPath()), $asset->sha256);
        $disk->assertExists($asset->storage_path);
        $this->assertSame($asset->size_bytes, $disk->size($asset->storage_path));
        $this->assertSame($asset->sha256, hash_file('sha256', $disk->path($asset->storage_path)));
    }

    private function assertUploadAudit(MediaAsset $asset): void
    {
        $audit = AuditEvent::sole();
        $this->assertSame('media.upload.quarantined', $audit->action);
        $this->assertSame(MediaAsset::class, $audit->subject_type);
        $this->assertEquals($asset->id, $audit->subject_id);
        $this->assertEquals($this->actor->id, $audit->actor_id);
        $this->assertSame(['role' => 'artwork', 'sha256' => $asset->sha256, 'size_bytes' => $asset->size_bytes], $audit->context);
    }
}
