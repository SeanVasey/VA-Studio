<?php

namespace Tests\Feature;

use App\Domain\Media\MalwareScanner;
use App\Domain\Media\MediaFailure;
use App\Domain\SoundKits\Models\SoundKitRevision;
use App\Domain\SoundKits\SoundKitDrafts;
use App\Domain\SoundKits\SoundKitFiles;
use App\Domain\SoundKits\SoundKitIntake;
use App\Domain\SoundKits\SoundKitProcessor;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Filament\Facades\Filament;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\Support\StemsFixtures;
use Tests\TestCase;

class SoundKitRecoveryTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private function fixture(?string $bytes = null): array
    {
        $this->fakePrivateMediaStorage();
        MediaFixtures::configure();
        $actor = LicenseFixtures::admin();
        $draft = app(SoundKitDrafts::class)->save(null, ['title' => 'Synthetic recovery kit', 'provenance' => 'Synthetic source'], $actor);
        $revision = app(SoundKitIntake::class)->handle($draft->id, UploadedFile::fake()->createWithContent('kit.zip', $bytes ?? StemsFixtures::zip([['name' => 'Kick.wav']])), 1, $actor);

        return [$actor, $draft, $revision];
    }

    public function test_repeated_ready_rollback_reuses_one_archive_then_commits_exactly_one_verified_outcome(): void
    {
        [$actor, , $revision] = $this->fixture();
        $refuse = true;
        SoundKitRevision::updating(function (SoundKitRevision $row) use (&$refuse): void {
            if ($refuse && $row->status === 'ready') {
                throw new \RuntimeException('Synthetic ready rollback');
            }
        });
        for ($i = 0; $i < 3; $i++) {
            $pending = app(SoundKitProcessor::class)->handle($revision->id);
            $this->assertSame('quarantined', $pending->status);
            $this->assertSame('processing_interrupted', $pending->failure_code);
            $this->assertCount(1, array_filter(Storage::disk('local')->allFiles('sound-kits'), fn ($file) => str_ends_with($file, '/samples.zip')));
        }
        $refuse = false;
        app(SoundKitIntake::class)->retry($revision->id, $actor);
        $ready = app(SoundKitProcessor::class)->handle($revision->id);
        $this->assertSame('ready', $ready->status);
        $this->assertSame(4, $ready->attempts);
        $this->assertSame(1, AuditEvent::where('action', 'sound_kit.revision.verified')->count());
    }

    public function test_ready_commit_acknowledgement_loss_retains_original_archive_and_replay_does_not_scan_again(): void
    {
        [$actor, , $revision] = $this->fixture();
        $fire = true;
        DB::connection()->getEventDispatcher()->listen(TransactionCommitted::class, function () use (&$fire): void {
            if ($fire && DB::transactionLevel() === 0 && SoundKitRevision::where('status', 'ready')->exists()) {
                $fire = false;
                throw new \RuntimeException('Synthetic ready commit acknowledgement loss');
            }
        });
        $ready = app(SoundKitProcessor::class)->handle($revision->id);
        $this->assertSame('ready', $ready->status);
        $this->assertFileExists(Storage::disk('local')->path($ready->archive_path));
        $this->assertSame($ready->getAttributes(), app(SoundKitProcessor::class)->handle($revision->id)->getAttributes());
        $this->assertSame(1, AuditEvent::where('action', 'sound_kit.revision.verified')->count());
    }

    public static function scanFailures(): array
    {
        return ['infected' => ['infected', 'test-only', true, 'scan_not_clean'], 'hash mismatch' => ['clean', 'test-only', false, 'scan_not_clean'],
            'unknown engine' => ['clean', 'untrusted', true, 'scan_not_clean'], 'testing engine outside testing' => ['clean', 'test-only', true, 'scan_not_clean', true]];
    }

    #[DataProvider('scanFailures')]
    public function test_untrusted_scan_evidence_never_becomes_ready(string $status, string $engine, bool $correctHash, string $failure, bool $outsideTesting = false): void
    {
        [, , $revision] = $this->fixture();
        app()->instance(MalwareScanner::class, new class($status, $engine, $correctHash) extends MalwareScanner
        {
            public function __construct(private string $status, private string $engine, private bool $correct) {}

            public function scan(string $path): array
            {
                return ['engine' => $this->engine, 'status' => $this->status, 'sha256' => $this->correct ? hash_file('sha256', $path) : str_repeat('0', 64)];
            }
        });
        if ($outsideTesting) {
            $panel = Filament::getPanel('admin');
            $actor = User::findOrFail($revision->requested_by);
            $actor->saveAppAuthenticationSecret($panel->getMultiFactorAuthenticationProviders()['app']->generateSecret());
            app()->instance('env', 'production');
        }
        try {
            $result = app(SoundKitProcessor::class)->handle($revision->id);
            $this->assertSame('failed', $result->status);
            $this->assertSame($failure, $result->failure_code);
            $this->assertNull($result->archive_path);
            $this->assertSame([], Storage::disk('local')->allFiles('processing'));
        } finally {
            app()->instance('env', 'testing');
        }
    }

    public function test_crc_mismatch_and_wav_decode_failure_never_produce_a_verified_archive(): void
    {
        [, $draft, $revision] = $this->fixture(StemsFixtures::zip([['name' => 'Bad.wav', 'crc' => 42]]));
        $result = app(SoundKitProcessor::class)->handle($revision->id);
        $this->assertSame('failed', $result->status);
        $this->assertNull($result->archive_path);
        $actor = LicenseFixtures::admin();
        $invalid = StemsFixtures::zip([['name' => 'LooksLikeWav.wav', 'bytes' => str_repeat('not wave audio ', 20)]]);
        $second = app(SoundKitIntake::class)->handle($draft->id, UploadedFile::fake()->createWithContent('kit.zip', $invalid), 2, $actor);
        $this->assertSame('failed', app(SoundKitProcessor::class)->handle($second->id)->status);
        $this->assertSame(0, AuditEvent::where('action', 'sound_kit.revision.verified')->count());
    }

    public function test_expired_claim_is_recoverable_and_old_claim_token_cannot_write_a_result(): void
    {
        [$actor, , $revision] = $this->fixture();
        app()->instance(MalwareScanner::class, new class($revision->id, $actor->id) extends MalwareScanner
        {
            private int $calls = 0;

            public function __construct(private int $revisionId, private int $actorId) {}

            public function scan(string $path): array
            {
                if (++$this->calls === 3) {
                    DB::table('sound_kit_revisions')->where('id', $this->revisionId)->update(['claimed_until' => now()->subSecond()]);
                    app(SoundKitIntake::class)->retry($this->revisionId, User::findOrFail($this->actorId));
                }

                return ['engine' => 'test-only', 'status' => 'clean', 'sha256' => hash_file('sha256', $path)];
            }
        });
        $lost = app(SoundKitProcessor::class)->handle($revision->id);
        $this->assertSame('quarantined', $lost->status);
        $this->assertNull($lost->claim_token);
        $this->assertNull($lost->archive_path);
        $this->assertSame(0, AuditEvent::where('action', 'sound_kit.revision.verified')->count());
        MediaFixtures::configure();
        $ready = app(SoundKitProcessor::class)->handle($revision->id);
        $this->assertSame('ready', $ready->status);
        $this->assertSame(2, $ready->attempts);
        $this->assertSame(1, AuditEvent::where('action', 'sound_kit.revision.verified')->count());
    }

    public function test_canonical_source_and_parent_symlinks_preserve_unrelated_files_and_refuse_reuse(): void
    {
        [, , $revision] = $this->fixture();
        $source = Storage::disk('local')->path($revision->source_path);
        $outside = tempnam(sys_get_temp_dir(), 'kit-unrelated-');
        file_put_contents($outside, 'Unrelated original');
        chmod($source, 0600);
        unlink($source);
        symlink($outside, $source);
        try {
            try {
                app(SoundKitFiles::class)->verify($revision->source_path, $revision->source_sha256, $revision->source_size_bytes);
                $this->fail();
            } catch (MediaFailure $error) {
                $this->assertSame('unsafe_path', $error->failureCode);
            }
            $this->assertSame('Unrelated original', file_get_contents($outside));
        } finally {
            unlink($source);
            unlink($outside);
        }
    }

    public function test_preserve_never_replaces_an_existing_canonical_file_with_different_bytes(): void
    {
        [, , $revision] = $this->fixture();
        $source = Storage::disk('local')->path($revision->source_path);
        $replacement = tempnam(sys_get_temp_dir(), 'kit-replacement-');
        file_put_contents($replacement, 'Unrelated replacement');
        try {
            try {
                app(SoundKitFiles::class)->preserve($replacement, basename(dirname($revision->source_path)), 'source.zip',
                    hash_file('sha256', $replacement), filesize($replacement));
                $this->fail('Existing retained bytes were replaced');
            } catch (MediaFailure $failure) {
                $this->assertSame('source_changed', $failure->failureCode);
            }
            $this->assertSame($revision->source_sha256, hash_file('sha256', $source));
            $this->assertSame('Unrelated replacement', file_get_contents($replacement));
        } finally {
            unlink($replacement);
        }
    }
}
