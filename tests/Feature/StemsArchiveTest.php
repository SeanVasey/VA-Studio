<?php

namespace Tests\Feature;

use App\Application\Media\IngestMediaUpload;
use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublicationReadiness;
use App\Domain\Catalog\SaveOfferDraft;
use App\Domain\Media\MalwareScanner;
use App\Domain\Media\MediaFailure;
use App\Domain\Media\MediaProcessor;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Models\MediaProcessingRun;
use App\Domain\Media\QueueMediaProcessing;
use App\Domain\Media\VerifiedMedia;
use App\Domain\Rights\Models\RightsDeclaration;
use App\Filament\Resources\MediaAssetResource\Pages\ManageMediaAssets;
use App\Models\User;
use App\Support\CanonicalJson;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MediaFixtures;
use Tests\Support\LicenseFixtures;
use Tests\Support\StemsFixtures;
use Tests\Support\TestOnlyMediaScanner;
use Tests\TestCase;
use ZipArchive;

class StemsArchiveTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;
    private Track $track;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        MediaFixtures::configure();
        $this->actor = User::factory()->create();
        $this->actor->forceFill(['is_admin' => true])->save();
        $this->track = Track::create(['title' => 'Synthetic stems', 'slug' => 'synthetic-stems']);
    }

    private function source(?string $bytes = null): MediaAsset
    {
        return MediaFixtures::source($this->track, 'stems_zip', $bytes ?? StemsFixtures::zip([['name' => 'Drums.wav'], ['name' => 'Parts/Bass.wav', 'method' => 8]]));
    }

    private function process(MediaAsset $source): MediaProcessingRun
    {
        return app(MediaProcessor::class)->handle(app(QueueMediaProcessing::class)->handle($source, $this->actor)->id);
    }

    private function assertRejected(string $bytes, array $codes): MediaProcessingRun
    {
        $source = $this->source($bytes);
        $run = app(QueueMediaProcessing::class)->handle($source, $this->actor);
        try {
            app(MediaProcessor::class)->handle($run->id);
            $this->fail('An unsafe archive became ready.');
        } catch (MediaFailure $failure) {
            $this->assertContains($failure->failureCode, $codes);
            $this->assertSame($failure->failureCode, $run->fresh()->failure_code);
        }
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame('quarantined', $source->fresh()->status);
        $this->assertSame(0, $run->outputs()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('processing'));
        $this->assertSame([], Storage::disk('local')->allFiles('media/revisions'));
        $this->assertDatabaseHas('audit_events', ['action' => 'media.processing.failed']);

        return $run->fresh();
    }

    public function test_admin_intake_processes_one_private_rebuilt_archive_with_exact_member_evidence(): void
    {
        $this->actingAs($this->actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $bytes = StemsFixtures::zip([['name' => 'Parts/', 'bytes' => '', 'mode' => 0040700], ['name' => 'Parts/Bass.wav', 'method' => 8], ['name' => 'Drums.wav']]);
        Livewire::test(ManageMediaAssets::class)->callAction('create', data: [
            'track_id' => $this->track->id, 'role' => 'stems_zip', 'upload' => UploadedFile::fake()->createWithContent('stems.zip', $bytes),
            'status' => 'ready', 'storage_path' => 'forged.zip', 'verified_by' => $this->actor->id,
        ])->assertHasNoActionErrors();
        $source = MediaAsset::sole();
        $this->assertSame('quarantined', $source->status);
        $this->assertNull($source->verified_by);
        config(['media.tag_path' => null, 'media.tag_sha256' => null]); // Stems do not generate a preview.
        $run = $this->process($source);
        $asset = $run->outputs()->sole();
        $this->assertSame('completed', $run->status);
        $this->assertSame('stems_zip', $asset->role);
        $this->assertSame($source->id, $asset->parent_asset_id);
        $this->assertTrue(app(VerifiedMedia::class)->available($asset));
        $this->assertNotSame($source->sha256, $asset->sha256);
        $this->assertNull($this->track->fresh()->duration_seconds);
        $manifest = $asset->technical_metadata['manifest'];
        $this->assertSame(['Drums.wav', 'Parts/Bass.wav'], array_column($manifest, 'name'));
        $this->assertSame(CanonicalJson::hash($manifest), $asset->technical_metadata['manifest_sha256']);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($asset->storage_path)));
        try {
            $this->assertSame(2, $zip->numFiles);
            foreach ($manifest as $member) {
                $actual = $zip->getFromName($member['name']);
                $this->assertSame(MediaFixtures::wav(0.2), $actual);
                $this->assertSame(hash('sha256', $actual), $member['sha256']);
                $this->assertSame($member['sha256'], $member['scan']['sha256']);
                $this->assertSame(200000, $member['audio']['duration_microseconds']);
            }
        } finally {
            $zip->close();
        }
        $this->assertSame([], Storage::disk('local')->allFiles('processing'));
        $this->get('/admin/media/'.$asset->id.'/preview')->assertNotFound();
        $this->get('/media/'.$asset->id)->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->get('/media/'.$source->id)->assertNotFound();
        $this->get('/media/'.$asset->id)->assertNotFound();
        $this->expectException(ValidationException::class);
        $asset->update(['original_name' => 'replacement.zip']);
    }

    public static function unsafeNames(): array
    {
        return array_map(fn ($name) => [$name], ['../escape.wav', '/absolute.wav', 'C:/drive.wav', 'folder\\escape.wav', 'a//b.wav', 'a/./b.wav', 'a/../b.wav', '.hidden.wav', "bad\nname.wav", 'NUL.wav', 'aux/b.wav', 'a /b.wav', 'a./b.wav', 'a/b/c/d/e/f.wav', 'a:b.wav', '%2e%2e/escape.wav']);
    }

    #[DataProvider('unsafeNames')]
    public function test_unsafe_paths_are_rejected_without_extraction(string $name): void
    {
        $this->assertRejected(StemsFixtures::zip([['name' => $name]]), ['unsafe_archive', 'invalid_archive']);
    }

    public function test_links_special_files_duplicate_names_and_file_directory_collisions_are_rejected(): void
    {
        foreach ([0120777, 0010600, 0020600, 0060600] as $mode) {
            $this->assertRejected(StemsFixtures::zip([['name' => 'link.wav', 'mode' => $mode]]), ['unsafe_archive']);
        }
        foreach ([['same.wav', 'same.wav'], ['Same.wav', 'same.wav'], ['a.wav', 'a.wav/b.wav']] as $names) {
            $this->assertRejected(StemsFixtures::zip(array_map(fn ($name) => ['name' => $name], $names)), ['unsafe_archive', 'invalid_archive']);
        }
    }

    public function test_nested_archives_auxiliary_files_encryption_and_unsupported_compression_are_rejected(): void
    {
        foreach ([['name' => 'nested.zip'], ['name' => 'readme.txt'], ['name' => 'locked.wav', 'flags' => 1], ['name' => 'packed.wav', 'method' => 12]] as $entry) {
            $this->assertRejected(StemsFixtures::zip([$entry]), ['unsupported_archive', 'invalid_archive']);
        }
    }

    public function test_entry_member_total_and_ratio_limits_reject_before_member_scanning(): void
    {
        $scanner = new class extends TestOnlyMediaScanner {
            public int $calls = 0;
            public function scan(string $path): array { $this->calls++; return parent::scan($path); }
        };
        app()->instance(MalwareScanner::class, $scanner);
        foreach (['entries' => 1, 'member_bytes' => 50, 'total_bytes' => 60, 'ratio' => 1] as $limit => $value) {
            $previous = config('media.stems.max_'.$limit);
            config(['media.stems.max_'.$limit => $value]);
            $scanner->calls = 0;
            $this->assertRejected(StemsFixtures::zip([['name' => 'a.wav', 'method' => 8], ['name' => 'b.wav']]), ['archive_limit']);
            $this->assertSame(1, $scanner->calls); // Only the original archive was scanned.
            config(['media.stems.max_'.$limit => $previous]);
        }
    }

    public function test_corrupt_zip_crc_forged_lengths_and_non_audio_members_never_promote(): void
    {
        $valid = StemsFixtures::zip([['name' => 'audio.wav']]);
        foreach ([substr($valid, 0, -12), StemsFixtures::zip([]), StemsFixtures::zip([['name' => 'audio.wav', 'crc' => 1]]), StemsFixtures::zip([['name' => 'audio.wav', 'size' => 44]])] as $bytes) {
            $this->assertRejected($bytes, ['invalid_archive', 'archive_limit']);
        }
        foreach ([str_repeat('not audio', 20), substr(MediaFixtures::wav(0.2), 0, -10)] as $bytes) {
            $this->assertRejected(StemsFixtures::zip([['name' => 'audio.wav', 'bytes' => $bytes]]), ['invalid_wav']);
        }
    }

    public function test_member_and_rebuilt_archive_scan_failures_clean_up_and_retry_without_duplicates(): void
    {
        foreach (['stem-', 'stems.zip'] as $target) {
            $scanner = new class($target) extends TestOnlyMediaScanner {
                public function __construct(private string $target) {}
                public function scan(string $path): array {
                    if (str_contains(basename($path), $this->target)) {
                        throw new MediaFailure('scan_not_clean', 'Synthetic rejection.');
                    }
                    return parent::scan($path);
                }
            };
            app()->instance(MalwareScanner::class, $scanner);
            $this->assertRejected(StemsFixtures::zip([['name' => 'audio.wav']]), ['scan_not_clean']);
        }
        app()->instance(MalwareScanner::class, new TestOnlyMediaScanner);
        $source = MediaAsset::where('role', 'stems_zip')->latest('id')->firstOrFail();
        $run = $this->process($source);
        $again = $this->process($source);
        $this->assertSame($run->id, $again->id);
        $this->assertSame($run->output_asset_ids, $again->output_asset_ids);
        $this->assertSame(2, $again->attempts);
        $this->assertSame(1, MediaAsset::where('status', 'ready')->count());
        $this->expectException(AuthorizationException::class);
        app(QueueMediaProcessing::class)->handle($source, User::factory()->create());
    }

    public function test_profile_changes_create_new_revisions_and_missing_manifest_evidence_fails_closed(): void
    {
        $source = $this->source();
        $first = $this->process($source);
        config(['media.stems.max_entries' => 64]);
        $second = $this->process($source);
        $this->assertNotSame($first->id, $second->id);
        $old = $first->outputs()->sole();
        $this->assertTrue(app(VerifiedMedia::class)->available($old));
        $metadata = $old->technical_metadata;
        unset($metadata['manifest'][0]['scan']);
        $old->technical_metadata = $metadata;
        $this->assertFalse(app(VerifiedMedia::class)->available($old));
        $metadata['manifest_sha256'] = CanonicalJson::hash($metadata['manifest']);
        $old->technical_metadata = $metadata;
        $this->assertFalse(app(VerifiedMedia::class)->available($old));
        $new = $second->outputs()->sole();
        $metadata = $new->technical_metadata;
        $metadata['archive_scan']['sha256'] = str_repeat('a', 64);
        $new->technical_metadata = $metadata;
        $this->assertFalse(app(VerifiedMedia::class)->available($new));
    }

    public function test_source_identity_and_published_track_guards_remain_enforced(): void
    {
        $source = $this->source();
        $run = app(QueueMediaProcessing::class)->handle($source, $this->actor);
        $source->update(['mime_type' => 'audio/wav']);
        try {
            app(MediaProcessor::class)->handle($run->id);
            $this->fail('Forged source identity was accepted.');
        } catch (MediaFailure $failure) {
            $this->assertSame('source_changed', $failure->failureCode);
        }
        DB::table('tracks')->where('id', $this->track->id)->update(['status' => 'published', 'published_slug' => $this->track->slug]);
        $this->expectException(ValidationException::class);
        app(QueueMediaProcessing::class)->handle($this->source(), $this->actor);
    }

    public function test_processed_stems_do_not_bypass_recording_revision_association_for_offers(): void
    {
        MediaFixtures::readyTrackMedia($this->track, $this->actor);
        $stems = $this->process($this->source())->outputs()->sole();
        $license = LicenseFixtures::published($this->actor, terms: ['schema_version' => 1, 'features' => ['SYNTHETIC STEMS ONLY'], 'required_asset_roles' => ['stems_zip']]);
        RightsDeclaration::create(['track_id' => $this->track->id, 'provenance_reference' => 'TEST-ONLY', 'sample_disclosure' => 'Synthetic', 'status' => 'verified', 'verified_by' => $this->actor->id, 'verified_at' => now()]);
        $offer = app(SaveOfferDraft::class)->handle(null, ['track_id' => $this->track->id, 'license_version_id' => $license->id, 'price_minor' => 100, 'currency' => 'USD', 'deliverable_asset_ids' => [$stems->id]], $this->actor);
        $this->assertSame(['Deliverables must come from the same verified recording revision as the current preview.'], app(PublicationReadiness::class)->draftBlockers($offer));
    }

    public function test_forged_zip_intake_and_customer_intake_are_rejected(): void
    {
        try {
            app(IngestMediaUpload::class)->handle($this->track, UploadedFile::fake()->createWithContent('renamed.zip', MediaFixtures::wav(0.2)), 'stems_zip', $this->actor);
            $this->fail('Renaming a WAV admitted it as a ZIP.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('media_assets', 0);
        }
        $this->expectException(AuthorizationException::class);
        app(IngestMediaUpload::class)->handle($this->track, UploadedFile::fake()->createWithContent('stems.zip', StemsFixtures::zip([['name' => 'audio.wav']])), 'stems_zip', User::factory()->create());
    }

    public function test_archive_budget_expiry_during_member_scanning_prevents_promotion(): void
    {
        config(['media.stems.max_seconds' => 1]);
        app()->instance(MalwareScanner::class, new class extends TestOnlyMediaScanner {
            public function scan(string $path): array {
                if (str_starts_with(basename($path), 'stem-')) {
                    usleep(1100000);
                }
                return parent::scan($path);
            }
        });
        $this->assertRejected(StemsFixtures::zip([['name' => 'audio.wav']]), ['archive_timeout']);
    }
}
