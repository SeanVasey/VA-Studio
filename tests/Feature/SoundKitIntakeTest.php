<?php

namespace Tests\Feature;

use App\Domain\Media\MalwareScanner;
use App\Domain\Media\MediaFailure;
use App\Domain\SoundKits\Models\SoundKitRevision;
use App\Domain\SoundKits\SoundKitDrafts;
use App\Domain\SoundKits\SoundKitIntake;
use App\Domain\SoundKits\SoundKitManifest;
use App\Domain\SoundKits\SoundKitProcessor;
use App\Jobs\ProcessSoundKit;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\Support\StemsFixtures;
use Tests\TestCase;

class SoundKitIntakeTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        MediaFixtures::configure();
    }

    private function fixture(): array
    {
        $actor = LicenseFixtures::admin();
        $draft = app(SoundKitDrafts::class)->save(null, ['title' => 'Synthetic sample kit', 'description' => 'Different short samples', 'provenance' => 'Synthetic test generator'], $actor);

        return [$actor, $draft, UploadedFile::fake()->createWithContent('samples.zip', StemsFixtures::zip([
            ['name' => 'Kicks/Kick.wav'], ['name' => 'Bass.wav', 'bytes' => MediaFixtures::wav(0.35, 120)],
        ]))];
    }

    public function test_private_sample_archive_becomes_an_immutable_manifest_without_tracks_offers_or_rights(): void
    {
        [$actor, $draft, $upload] = $this->fixture();
        $revision = app(SoundKitIntake::class)->handle($draft->id, $upload, 1, $actor);
        $this->assertSame('quarantined', $revision->status);
        $this->assertSame(2, $draft->fresh()->version);
        Queue::assertPushed(ProcessSoundKit::class, 1);
        $ready = app(SoundKitProcessor::class)->handle($revision->id);
        $this->assertSame('ready', $ready->status, $ready->failure_code ?? '');
        $manifest = app(SoundKitManifest::class)->verified($ready);
        $this->assertSame(['Bass.wav', 'Kicks/Kick.wav'], array_column($manifest['members'], 'name'));
        $this->assertSame([350000, 200000], array_column(array_column($manifest['members'], 'audio'), 'duration_microseconds'));
        $this->assertSame('wav_sample_kit', $manifest['kind']);
        $this->assertSame(CanonicalJson::hash($manifest), $ready->manifest_sha256);
        $this->assertSame(0, DB::table('tracks')->count());
        $this->assertSame(0, DB::table('offers')->count());
        $this->assertSame([], Storage::disk('local')->allFiles('processing'));
        $snapshot = app(SoundKitDrafts::class)->snapshot($draft->id, $actor);
        $this->assertStringNotContainsString($ready->source_path, json_encode($snapshot));
        $this->assertStringNotContainsString($ready->archive_path, json_encode($snapshot));
        $this->get('/sound-kits/'.$ready->public_id)->assertNotFound();
        $this->get('/storage/'.$ready->archive_path)->assertNotFound();
        $this->assertSame($ready->fresh()->getAttributes(), app(SoundKitProcessor::class)->handle($ready->id)->getAttributes());
    }

    public function test_exact_upload_retry_returns_original_revision_and_does_not_advance_draft_or_queue_again(): void
    {
        [$actor, $draft, $upload] = $this->fixture();
        $service = app(SoundKitIntake::class);
        $first = $service->handle($draft->id, $upload, 1, $actor);
        $again = $service->handle($draft->id, $upload, '1', $actor);
        $this->assertSame($first->id, $again->id);
        $this->assertSame(2, $draft->fresh()->version);
        $this->assertSame(1, SoundKitRevision::count());
        Queue::assertPushed(ProcessSoundKit::class, 1);
        $this->assertSame(1, AuditEvent::where('action', 'sound_kit.revision.received')->count());
        $this->expectException(ValidationException::class);
        $service->handle($draft->id, UploadedFile::fake()->createWithContent('different.zip', file_get_contents($upload->getRealPath())), 1, $actor);
    }

    public function test_new_upload_captures_new_description_while_prior_version_keeps_original_snapshot(): void
    {
        [$actor, $draft, $upload] = $this->fixture();
        $first = app(SoundKitIntake::class)->handle($draft->id, $upload, 1, $actor);
        app(SoundKitDrafts::class)->save($draft, ['title' => 'New title', 'description' => '', 'provenance' => 'New source note', 'version' => 2], $actor);
        $second = app(SoundKitIntake::class)->handle($draft->id, $upload, 3, $actor);
        $this->assertSame('Synthetic sample kit', $first->fresh()->description_snapshot['title']);
        $this->assertSame('New title', $second->description_snapshot['title']);
        $this->assertSame(2, $second->number);
    }

    public function test_root_commit_acknowledgement_loss_retains_one_source_and_retry_recovers_the_same_revision(): void
    {
        [$actor, $draft, $upload] = $this->fixture();
        $fire = true;
        DB::connection()->getEventDispatcher()->listen(TransactionCommitted::class, function () use (&$fire): void {
            if ($fire && DB::transactionLevel() === 0 && SoundKitRevision::count() === 1) {
                $fire = false;
                throw new \RuntimeException('Synthetic lost commit acknowledgement');
            }
        });
        try {
            app(SoundKitIntake::class)->handle($draft->id, $upload, 1, $actor);
            $this->fail();
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Synthetic lost', $error->getMessage());
        }
        $first = SoundKitRevision::sole();
        $again = app(SoundKitIntake::class)->handle($draft->id, $upload, 1, $actor);
        $this->assertSame($first->id, $again->id);
        $this->assertFileExists(Storage::disk('local')->path($again->source_path));
        Queue::assertNothingPushed();
        app(SoundKitIntake::class)->retry($again->id, $actor);
        Queue::assertPushed(ProcessSoundKit::class, 1);
    }

    public function test_repeated_database_rollback_reuses_one_deterministic_source_without_storage_amplification(): void
    {
        [$actor, $draft, $upload] = $this->fixture();
        $reject = true;
        SoundKitRevision::creating(function () use (&$reject): void {
            if ($reject) {
                throw new \RuntimeException('Synthetic row refusal');
            }
        });
        for ($i = 0; $i < 3; $i++) {
            try {
                app(SoundKitIntake::class)->handle($draft->id, $upload, 1, $actor);
                $this->fail();
            } catch (\RuntimeException $error) {
                $this->assertSame('Synthetic row refusal', $error->getMessage());
            }
        }
        $reject = false;
        $this->assertCount(1, array_filter(Storage::disk('local')->allFiles('sound-kits'), fn ($file) => str_ends_with($file, '/source.zip')));
        $this->assertSame(0, SoundKitRevision::count());
        $this->assertSame(1, $draft->fresh()->version);
        $this->assertSame(1, app(SoundKitIntake::class)->handle($draft->id, $upload, 1, $actor)->number);
    }

    public function test_transient_scanner_failure_is_private_retryable_and_success_never_replaces_source(): void
    {
        [$actor, $draft, $upload] = $this->fixture();
        $revision = app(SoundKitIntake::class)->handle($draft->id, $upload, 1, $actor);
        app()->instance(MalwareScanner::class, new class extends MalwareScanner
        {
            public function scan(string $path): array
            {
                throw new MediaFailure('scanner_unavailable', 'Synthetic scanner outage');
            }
        });
        $pending = app(SoundKitProcessor::class)->handle($revision->id);
        $this->assertSame('quarantined', $pending->status);
        $this->assertSame('scanner_unavailable', $pending->failure_code);
        $this->assertNull($pending->archive_path);
        MediaFixtures::configure();
        app(SoundKitIntake::class)->retry($revision->id, $actor);
        $ready = app(SoundKitProcessor::class)->handle($revision->id);
        $this->assertSame('ready', $ready->status);
        $this->assertSame($revision->source_path, $ready->source_path);
        $this->assertSame(2, $ready->attempts);
    }

    public static function hostileArchives(): array
    {
        return ['traversal' => [[['name' => '../escape.wav']]], 'case collision' => [[['name' => 'A.wav'], ['name' => 'a.wav']]],
            'link' => [[['name' => 'link.wav', 'mode' => 0120777]]], 'nested' => [[['name' => 'nested.zip']]],
            'text' => [[['name' => 'terms.txt']]], 'encrypted' => [[['name' => 'secret.wav', 'flags' => 1]]],
            'compression' => [[['name' => 'odd.wav', 'method' => 12]]], 'ratio' => [[['name' => 'bomb.wav', 'bytes' => str_repeat('0', 1000000), 'method' => 8]]]];
    }

    #[DataProvider('hostileArchives')]
    public function test_hostile_archives_fail_before_quarantine_or_scanner(array $entries): void
    {
        [$actor, $draft] = $this->fixture();
        try {
            app(SoundKitIntake::class)->handle($draft->id, UploadedFile::fake()->createWithContent('unsafe.zip', StemsFixtures::zip($entries)), 1, $actor);
            $this->fail('Unsafe kit accepted');
        } catch (MediaFailure) {
            $this->assertSame(0, SoundKitRevision::count());
            $this->assertSame([], Storage::disk('local')->allFiles('sound-kits'));
        }
    }

    public function test_corrupted_retained_source_fails_closed_and_ready_manifest_detects_changed_output(): void
    {
        [$actor, $draft, $upload] = $this->fixture();
        $revision = app(SoundKitIntake::class)->handle($draft->id, $upload, 1, $actor);
        $path = Storage::disk('local')->path($revision->source_path);
        chmod($path, 0600);
        file_put_contents($path, 'damaged retained source');
        $this->assertSame('failed', app(SoundKitProcessor::class)->handle($revision->id)->status);
        $this->assertSame('source_changed', $revision->fresh()->failure_code);
        $second = app(SoundKitIntake::class)->handle($draft->id, $upload, 2, $actor);
        $ready = app(SoundKitProcessor::class)->handle($second->id);
        chmod(Storage::disk('local')->path($ready->archive_path), 0600);
        file_put_contents(Storage::disk('local')->path($ready->archive_path), 'corrupted archive');
        $this->expectException(MediaFailure::class);
        app(SoundKitManifest::class)->verified($ready);
    }

    public function test_profile_change_refuses_pending_bytes_and_does_not_silently_reinterpret_the_revision(): void
    {
        [$actor, $draft, $upload] = $this->fixture();
        $revision = app(SoundKitIntake::class)->handle($draft->id, $upload, 1, $actor);
        config(['media.stems.max_entries' => 1]);
        $changed = app(SoundKitProcessor::class)->handle($revision->id);
        $this->assertSame('failed', $changed->status);
        $this->assertSame('profile_changed', $changed->failure_code);
    }

    public function test_authority_revoked_after_scanning_prevents_ready_commit_and_preserves_retryable_bytes(): void
    {
        [$actor, $draft, $upload] = $this->fixture();
        $revision = app(SoundKitIntake::class)->handle($draft->id, $upload, 1, $actor);
        app()->instance(MalwareScanner::class, new class($actor->id) extends MalwareScanner
        {
            private int $calls = 0;

            public function __construct(private int $actorId) {}

            public function scan(string $path): array
            {
                if (++$this->calls === 4) {
                    DB::table('users')->where('id', $this->actorId)->update(['is_admin' => false]);
                }

                return ['engine' => 'test-only', 'status' => 'clean', 'sha256' => hash_file('sha256', $path)];
            }
        });
        $pending = app(SoundKitProcessor::class)->handle($revision->id);
        $this->assertSame('quarantined', $pending->status);
        $this->assertSame('authority_changed', $pending->failure_code);
        $this->assertSame(0, AuditEvent::where('action', 'sound_kit.revision.verified')->count());
        $this->expectException(AuthorizationException::class);
        app(SoundKitIntake::class)->retry($revision->id, $actor);
    }

    public function test_intake_refuses_caller_owned_transaction_before_private_file_mutation(): void
    {
        [$actor, $draft, $upload] = $this->fixture();
        DB::beginTransaction();
        try {
            $this->expectException(\LogicException::class);
            app(SoundKitIntake::class)->handle($draft->id, $upload, 1, $actor);
        } finally {
            DB::rollBack();
            $this->assertSame([], Storage::disk('local')->allFiles('sound-kits'));
        }
    }
}
