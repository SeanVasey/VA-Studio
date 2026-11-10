<?php

namespace Tests\Feature;

use App\Application\Media\IngestMediaUpload;
use App\Domain\Catalog\Models\Track;
use App\Domain\Media\MalwareScanner;
use App\Domain\Media\MediaFailure;
use App\Domain\Media\MediaProfile;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\QueueMediaProcessing;
use App\Domain\Media\StemsArchive;
use App\Domain\SoundKits\SoundKitArchiveProfile;
use App\Jobs\ProcessMedia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Support\MediaFixtures;
use Tests\Support\StemsFixtures;
use Tests\TestCase;

/**
 * The stems ZIP intake cap is 1 GiB (Sean, 2026-11-10) and no other role's cap moved. Sizes are faked: an upload that reports a size
 * it does not have, and ZIP directories that declare sizes their members do not have. No file here is larger than a few MiB.
 */
class StemsZipIntakeCapTest extends TestCase
{
    use RefreshDatabase;

    private const MIB = 1048576;

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
        $this->track = Track::create(['title' => 'Cap test', 'slug' => 'cap-test']);
    }

    /** A real, tiny file that reports $size to the intake. */
    private function upload(string $bytes, string $name, int $size): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'cap-');
        file_put_contents($path, $bytes);
        $this->beforeApplicationDestroyed(static fn () => @unlink($path));

        return new class($path, $name, $size) extends UploadedFile
        {
            public function __construct(string $path, string $name, private readonly int $reported)
            {
                parent::__construct($path, $name, null, UPLOAD_ERR_OK, true);
            }

            public function getSize(): int|false
            {
                return $this->reported;
            }
        };
    }

    private function zip(): string
    {
        return StemsFixtures::zip([['name' => 'Drums.wav']]);
    }

    /** @return 'admitted'|string The size message, or 'admitted' when the intake got past its size gate (and failed later on the faked size). */
    private function sizeOutcome(string $role, string $bytes, string $name, int $size): string
    {
        try {
            app(IngestMediaUpload::class)->handle($this->track, $this->upload($bytes, $name, $size), $role, $this->actor);
        } catch (ValidationException $refusal) {
            return $refusal->errors()['upload'][0];
        } catch (RuntimeException $mismatch) {
            // The copy is a few bytes, not the size the upload claimed, so integrity verification is the next thing to fail.
            $this->assertSame('Upload integrity verification failed.', $mismatch->getMessage());

            return 'admitted';
        }
        $this->fail('A file that reports a false size cannot pass integrity verification.');
    }

    public function test_a_stems_zip_of_exactly_one_gibibyte_passes_the_size_gate_and_one_byte_more_is_refused(): void
    {
        $this->assertSame('admitted', $this->sizeOutcome('stems_zip', $this->zip(), 'stems.zip', 1024 * self::MIB));
        $this->assertSame('Stems ZIPs must be between 1 byte and 1 GiB.', $this->sizeOutcome('stems_zip', $this->zip(), 'stems.zip', 1024 * self::MIB + 1));
        $this->assertSame('Stems ZIPs must be between 1 byte and 1 GiB.', $this->sizeOutcome('stems_zip', $this->zip(), 'stems.zip', 0));
        // Neither attempt left an asset or a private file behind.
        $this->assertSame(0, MediaAsset::count());
        $this->assertSame([], array_values(array_filter(Storage::disk('local')->allFiles(), fn (string $file): bool => str_starts_with($file, 'quarantine/'))));
    }

    public function test_a_stems_zip_above_the_old_200_mib_cap_is_admitted(): void
    {
        $this->assertSame('admitted', $this->sizeOutcome('stems_zip', $this->zip(), 'stems.zip', 823 * self::MIB));
    }

    public function test_no_other_role_moved(): void
    {
        $this->assertSame(['master_wav' => 200 * self::MIB, 'stems_zip' => 1024 * self::MIB, 'artwork' => 20 * self::MIB], IngestMediaUpload::MAX_BYTES);
        $wav = MediaFixtures::wav(0.2);
        $this->assertSame('admitted', $this->sizeOutcome('master_wav', $wav, 'master.wav', 200 * self::MIB));
        foreach ([200 * self::MIB + 1, 201 * self::MIB, 1024 * self::MIB] as $size) {
            $this->assertSame('WAV masters must be between 1 byte and 200 MiB.', $this->sizeOutcome('master_wav', $wav, 'master.wav', $size), (string) $size);
        }
        $png = MediaFixtures::png();
        $this->assertSame('admitted', $this->sizeOutcome('artwork', $png, 'art.png', 20 * self::MIB));
        foreach ([20 * self::MIB + 1, 200 * self::MIB, 1024 * self::MIB] as $size) {
            $this->assertSame('Artwork must be between 1 byte and 20 MiB.', $this->sizeOutcome('artwork', $png, 'art.png', $size), (string) $size);
        }
        $this->assertSame(0, IngestMediaUpload::maxBytes('preview_tagged'));
    }

    public function test_processing_bounds_a_stems_zip_by_its_own_setting_and_masters_by_theirs(): void
    {
        Queue::fake();
        $this->assertSame(1024 * self::MIB, config('media.max_stems_bytes'));
        $this->assertSame(512 * self::MIB, config('media.max_source_bytes'));

        // Lowering the master bound leaves stems alone.
        config(['media.max_source_bytes' => 100]);
        app(QueueMediaProcessing::class)->handle(MediaFixtures::source($this->track, 'stems_zip', $this->zip()), $this->actor);
        $this->assertOversize(MediaFixtures::source($this->track, 'master_wav'));

        // Lowering the stems bound leaves masters alone.
        config(['media.max_source_bytes' => 512 * self::MIB, 'media.max_stems_bytes' => 100]);
        app(QueueMediaProcessing::class)->handle(MediaFixtures::source($this->track, 'master_wav'), $this->actor);
        $this->assertOversize(MediaFixtures::source($this->track, 'stems_zip', $this->zip()));
        Queue::assertPushed(ProcessMedia::class, 2);
    }

    private function assertOversize(MediaAsset $source): void
    {
        try {
            app(QueueMediaProcessing::class)->handle($source, $this->actor);
            $this->fail('An oversize source was queued.');
        } catch (ValidationException $refusal) {
            $this->assertSame('The upload is empty or exceeds the role size limit.', $refusal->errors()['media'][0]);
        }
    }

    public function test_the_stems_expansion_guards_still_bound_the_work_at_one_gibibyte(): void
    {
        $policy = app(StemsArchive::class)->policy();
        $this->assertSame([128, 128 * self::MIB, 767 * self::MIB, 100, 360], [$policy['archive_max_entries'], $policy['archive_max_member_bytes'],
            $policy['archive_max_total_bytes'], $policy['archive_max_ratio'], $policy['archive_max_seconds']]);

        // 5 x 128 MiB + 127 MiB = 767 MiB, the most the scanner can cover, is accepted by the directory check; a 768th MiB is not.
        $this->assertSame('ok', $this->inspect([128, 128, 128, 128, 128, 127]));
        $this->assertSame('archive_limit', $this->inspect([128, 128, 128, 128, 128, 128]));
        $this->assertSame('archive_limit', $this->inspect([129]));
        $this->assertSame('ok', $this->inspect([128]));
        // The 100:1 ratio per member holds whatever the upload cap is: 128 MiB declared in under 1.28 MiB of data is a bomb.
        $this->assertSame('archive_limit', $this->inspect([128], dataBytes: (int) (1.2 * self::MIB)));
        // Sound kits keep their own expanded total, and their 200 MiB source bound.
        $kit = app(SoundKitArchiveProfile::class)->current();
        $this->assertSame([512 * self::MIB, 200 * self::MIB], [$kit['archive_max_total_bytes'], $kit['source_max_bytes']]);
    }

    /** Declared member sizes in MiB; each member carries $dataBytes (a bit over the 100:1 minimum) of stored data. */
    private function inspect(array $declaredMib, ?int $dataBytes = null): string
    {
        $members = [];
        foreach ($declaredMib as $index => $mib) {
            $members[] = ['name' => 'Stem'.$index.'.wav', 'bytes' => str_repeat('a', $dataBytes ?? (int) ceil($mib * self::MIB / 100) + 1), 'size' => $mib * self::MIB, 'crc' => 0];
        }
        $path = tempnam(sys_get_temp_dir(), 'cap-zip-');
        file_put_contents($path, StemsFixtures::zip($members));
        $this->beforeApplicationDestroyed(static fn () => @unlink($path));
        try {
            app(StemsArchive::class)->inspectUpload($path, 'application/zip', app(MediaProfile::class)->current('stems_zip'));
        } catch (MediaFailure $failure) {
            return $failure->failureCode;
        }

        return 'ok';
    }

    public function test_the_scanner_limit_stays_within_clamav_s_ceiling_and_covers_a_one_gibibyte_upload(): void
    {
        $limit = MalwareScanner::limitMebibytes();
        $policy = app(StemsArchive::class)->policy();
        $this->assertSame(2047, $limit);
        $this->assertSame(MalwareScanner::ENGINE_CEILING_MIB, $limit);
        // Upload plus its expanded members, and the rebuilt ZIP plus them, each with the 256 MiB of headroom, fit without the cap trimming them.
        $this->assertLessThanOrEqual($limit * self::MIB, IngestMediaUpload::maxBytes('stems_zip') + $policy['archive_max_total_bytes'] + 256 * self::MIB);
        $this->assertLessThanOrEqual($limit * self::MIB, 2 * $policy['archive_max_total_bytes'] + 256 * self::MIB);
        // A bound configured past what ClamAV can be given is capped, so the scanner refuses what it cannot scan rather than being told it can.
        config(['media.max_stems_bytes' => 4096 * self::MIB]);
        $this->assertSame(2047, MalwareScanner::limitMebibytes());
    }
}
