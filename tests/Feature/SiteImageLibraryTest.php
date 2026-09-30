<?php

namespace Tests\Feature;

use App\Application\SiteBuilder\IngestSiteImage;
use App\Application\SiteBuilder\RetrySiteImage;
use App\Domain\Media\MalwareScanner;
use App\Domain\Media\MediaFailure;
use App\Domain\Media\PrivateMediaFiles;
use App\Domain\SiteBuilder\Models\SiteImage;
use App\Domain\SiteBuilder\Models\SiteImageVariant;
use App\Domain\SiteBuilder\SiteImageDerivatives;
use App\Domain\SiteBuilder\SiteImageFiles;
use App\Domain\SiteBuilder\SiteImageInspection;
use App\Domain\SiteBuilder\SiteImageManifest;
use App\Domain\SiteBuilder\SiteImageProblem;
use App\Domain\SiteBuilder\SiteImageProcessor;
use App\Domain\SiteBuilder\SiteImageSlot;
use App\Jobs\ProcessSiteImage;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Illuminate\Validation\ValidationException;
use LogicException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\Support\SiteImageFixtures as F;
use Tests\Support\TestOnlyMediaScanner;
use Tests\TestCase;

class SiteImageLibraryTest extends TestCase
{
    use RefreshDatabase;

    private TestOnlyMediaScanner $scanner;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        $this->scanner = MediaFixtures::configure();
        $this->actor = LicenseFixtures::admin();
    }

    private function upload(string $bytes, string $name = 'synthetic.jpg'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'site-image-');
        file_put_contents($path, $bytes);
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return new UploadedFile($path, $name, null, null, true);
    }

    private function ingest(string $slot, string $bytes, string $credit = 'Synthetic studio photograph', string $name = 'synthetic.jpg'): SiteImage
    {
        return app(IngestSiteImage::class)->handle($slot, $this->upload($bytes, $name), $credit, true, $this->actor);
    }

    private function process(SiteImage $image): SiteImage
    {
        return app(SiteImageProcessor::class)->handle($image->id);
    }

    /** @return array<string, list<string>> */
    private function refusal(callable $attempt): array
    {
        try {
            $attempt();
        } catch (ValidationException $exception) {
            return $exception->errors();
        }
        $this->fail('The attempt should have been refused.');
    }

    private function bytes(SiteImageVariant $variant): string
    {
        return (string) Storage::disk('local')->get($variant->storage_path);
    }

    /** @return list<string> */
    private function actions(SiteImage $image): array
    {
        return AuditEvent::query()->where('subject_type', SiteImage::class)->where('subject_id', $image->id)->orderBy('id')->pluck('action')->all();
    }

    private function assertNothingStored(): void
    {
        $this->assertSame(0, SiteImage::count());
        $this->assertSame([], Storage::disk('local')->allFiles('site-images'));
        Queue::assertNothingPushed();
    }

    /** Decodes with FFmpeg, so the check does not depend on the WebP support GD was built with. */
    private function decode(SiteImageVariant $variant): \GdImage
    {
        $directory = sys_get_temp_dir().'/site-image-decode-'.Str::uuid();
        mkdir($directory, 0700);
        try {
            file_put_contents($directory.'/in', $this->bytes($variant));
            (new Process([config('media.ffmpeg'), '-nostdin', '-hide_banner', '-loglevel', 'error', '-f', $variant->format === 'webp' ? 'webp_pipe' : 'jpeg_pipe',
                '-i', $directory.'/in', '-frames:v', '1', '-c:v', 'png', '-pix_fmt', 'rgb24', $directory.'/out.png']))->setTimeout(30)->mustRun();
            $image = imagecreatefrompng($directory.'/out.png');
            $this->assertInstanceOf(\GdImage::class, $image);

            return $image;
        } finally {
            (new Filesystem)->deleteDirectory($directory);
        }
    }

    private function assertColour(array $expected, \GdImage $image, int $x, int $y): void
    {
        $rgb = imagecolorat($image, $x, $y);
        $actual = [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
        foreach ($expected as $channel => $value) {
            $this->assertEqualsWithDelta($value, $actual[$channel], 12, "Colour drift at {$x},{$y}: ".json_encode($actual));
        }
    }

    public function test_each_slot_is_prepared_in_its_exact_sizes_and_formats(): void
    {
        $cases = [
            'hero_desktop' => [2400, 890, [[1200, 445], [1800, 668], [2400, 890]], ['jpeg', 'webp']],
            'hero_mobile' => [960, 890, [[480, 445], [720, 668], [960, 890]], ['jpeg', 'webp']],
            'studio' => [1440, 630, [[720, 315], [1080, 473], [1440, 630]], ['jpeg', 'webp']],
            // The share image is trimmed to exactly 1200 x 630 from a larger source of the same shape.
            'share' => [1600, 840, [[1200, 630]], ['jpeg']],
        ];
        foreach ($cases as $slot => [$width, $height, $sizes, $formats]) {
            $image = $this->process($this->ingest($slot, F::jpeg($width, $height)));

            $this->assertSame('ready', $image->status, $slot);
            $this->assertSame([null, 1, null, null], [$image->failure_code, $image->attempts, $image->claim_token, $image->claimed_until]);
            $this->assertSame(SiteImageSlot::PROFILE_VERSION, $image->profile_version);
            $this->assertSame(SiteImageSlot::fingerprint(), $image->profile_fingerprint);
            $this->assertTrue(SiteImageManifest::matches($image));
            // MySQL stores JSON objects with its own key order.
            $this->assertEquals(['engine' => 'test-only', 'status' => 'clean', 'sha256' => $image->source_sha256],
                array_intersect_key($image->evidence['source_scan'], array_flip(['engine', 'status', 'sha256'])));
            $this->assertSame('yuvj444p', $image->evidence['pixel_format']);
            $this->assertStringStartsWith('ffmpeg version', $image->evidence['ffmpeg_version']);
            $expected = [];
            foreach ($formats as $format) {
                foreach ($sizes as [$w, $h]) {
                    $expected[] = [$format, $w, $h];
                }
            }
            $variants = $image->variants()->get();
            $this->assertSame($expected, $variants->map(fn (SiteImageVariant $v): array => [$v->format, $v->width, $v->height])->all(), $slot);
            $directories = [];
            foreach ($variants as $variant) {
                $extension = $variant->format === 'webp' ? 'webp' : 'jpg';
                $this->assertMatchesRegularExpression('~\Asite-images/revisions/[0-9a-f-]{36}/'.$variant->width.'\.'.$extension.'\z~D', $variant->storage_path);
                $directories[] = dirname($variant->storage_path);
                $bytes = $this->bytes($variant);
                $this->assertSame([hash('sha256', $bytes), strlen($bytes)], [$variant->sha256, $variant->size_bytes]);
                $size = getimagesizefromstring($bytes);
                $this->assertSame([$variant->width, $variant->height, $variant->mimeType()], [$size[0], $size[1], $size['mime']]);
            }
            $this->assertCount(1, array_unique($directories));
            $this->assertSame(['site.image.uploaded', 'site.image.processing', 'site.image.processed'], $this->actions($image));
            // The quarantined original is kept as evidence and never becomes a variant.
            $this->assertTrue(Storage::disk('local')->exists($image->source_path));
        }
    }

    public function test_the_encoder_arguments_and_fingerprint_are_those_of_profile_v1(): void
    {
        // Built from the recorded settings, in the order profile site-image-v1 has always used. A different list or fingerprint
        // means a new profile, which needs a new version and migration (D-25).
        $this->assertSame(['-c:v', 'mjpeg', '-q:v', '3', '-pix_fmt', 'yuvj420p'], SiteImageSlot::encoderArguments('jpeg'));
        $this->assertSame(['-c:v', 'libwebp', '-quality', '80', '-compression_level', '4'], SiteImageSlot::encoderArguments('webp'));
        $this->assertSame('d93be6e82f8dbe45919b7d8c07726e83a33ccc361ce7101612e775a0207dfc6a', SiteImageSlot::fingerprint());
    }

    /** @return array<string, array{string}> */
    public static function slots(): array
    {
        return array_map(fn (string $slot): array => [$slot], array_combine(array_keys(SiteImageSlot::DEFINITIONS), array_keys(SiteImageSlot::DEFINITIONS)));
    }

    /** Every slot, because each has its own sizes and the share image takes the cropping path. */
    #[DataProvider('slots')]
    public function test_metadata_never_reaches_a_prepared_file_and_does_not_change_the_pixels(string $slot): void
    {
        ['width' => $width, 'height' => $height] = SiteImageSlot::DEFINITIONS[$slot];
        $pairs = [
            [F::jpeg($width, $height), F::jpeg($width, $height, ['exif' => 1, 'xmp' => true, 'iptc' => true, 'icc' => true, 'comment' => true])],
            [F::png($width, $height), F::png($width, $height, 'rgb', F::pngMetadataChunks())],
        ];
        $forbidden = [...F::MARKERS, 'Exif', 'ICC_PROFILE', 'ns.adobe.com', '8BIM', 'Photoshop', 'Lavc', 'CREATOR', 'XMP ', 'ICCP', 'EXIF'];
        foreach ($pairs as [$plain, $marked]) {
            $clean = $this->process($this->ingest($slot, $plain))->variants()->get();
            $stripped = $this->process($this->ingest($slot, $marked))->variants()->get();
            $this->assertCount(SiteImageSlot::variantCount($slot), $stripped);
            foreach ($stripped as $index => $variant) {
                $bytes = $this->bytes($variant);
                foreach ($forbidden as $marker) {
                    $this->assertStringNotContainsString($marker, $bytes, "{$marker} survived in {$variant->storage_path}");
                }
                // Same pixels in, same bytes out: the metadata changed nothing but was dropped.
                $this->assertSame($clean[$index]->sha256, $variant->sha256);
            }
        }
    }

    public function test_prepared_files_keep_the_source_colours(): void
    {
        foreach ([F::jpeg(1440, 630), F::png(1440, 630)] as $source) {
            foreach ($this->process($this->ingest('studio', $source))->variants()->where('width', 1440)->get() as $variant) {
                $decoded = $this->decode($variant);
                // Well inside each area of the pattern, away from chroma-subsampled edges.
                $this->assertColour(F::FIELD, $decoded, 1080, 470);
                $this->assertColour(F::BLOCK, $decoded, 200, 100);
            }
        }
    }

    public function test_progressive_grayscale_palette_and_polyglot_sources_are_prepared_cleanly(): void
    {
        $polyglot = F::jpeg(1440, 630)."<script>alert('SYNTHETIC-POLYGLOT-MARKER')</script>PK\x03\x04SYNTHETIC-POLYGLOT-MARKER";
        $sources = [F::jpeg(1440, 630, ['progressive' => true]), F::flatJpeg(1440, 630, 1), F::png(1440, 630, 'gray'), F::png(1440, 630, 'palette'), $polyglot];
        foreach ($sources as $index => $source) {
            $image = $this->process($this->ingest('studio', $source));
            $this->assertSame('ready', $image->status, "Source {$index}");
            foreach ($image->variants()->get() as $variant) {
                foreach (['<script', 'SYNTHETIC-POLYGLOT-MARKER', "PK\x03\x04"] as $payload) {
                    $this->assertStringNotContainsString($payload, $this->bytes($variant));
                }
            }
        }
    }

    public function test_an_rgb_jpeg_declared_by_an_adobe_marker_is_prepared(): void
    {
        // Three 8-bit components with Adobe transform 0 are RGB samples, which FFmpeg decodes as planar RGB.
        $image = $this->process($this->ingest('studio', F::jpeg(1440, 630, ['adobe_rgb' => true])));

        $this->assertSame(['ready', null, 'gbrp'], [$image->status, $image->failure_code, $image->evidence['pixel_format'] ?? null]);
        $this->assertSame(6, $image->variants()->count());
    }

    public function test_a_valid_upload_is_quarantined_with_its_provenance_and_queued(): void
    {
        $bytes = F::jpeg(960, 890);
        $image = app(IngestSiteImage::class)->handle('hero_mobile', $this->upload($bytes, "..\\..\\mobile\x07-hero.jpg"), '  Photo by Synthetic Studio  ', true, $this->actor);

        $this->assertSame(['hero_mobile', 'quarantined', 0, 'mobile-hero.jpg', 'Photo by Synthetic Studio', $this->actor->id],
            [$image->slot, $image->fresh()->status, $image->fresh()->attempts, $image->original_name, $image->credit, $image->uploaded_by]);
        $this->assertSame([hash('sha256', $bytes), strlen($bytes), 'image/jpeg', 960, 890],
            [$image->source_sha256, $image->size_bytes, $image->mime_type, $image->width, $image->height]);
        $this->assertNotNull($image->rights_confirmed_at);
        $this->assertMatchesRegularExpression('~\Asite-images/quarantine/[0-9a-f-]{36}/source\.upload\z~D', $image->source_path);
        $this->assertSame($bytes, Storage::disk('local')->get($image->source_path));
        $event = AuditEvent::query()->where('action', 'site.image.uploaded')->sole();
        $this->assertSame([$this->actor->id, $image->id], [$event->actor_id, (int) $event->subject_id]);
        $this->assertEquals(['slot' => 'hero_mobile', 'sha256' => hash('sha256', $bytes), 'size_bytes' => strlen($bytes), 'width' => 960, 'height' => 890], $event->context);
        Queue::assertPushedOn('media', ProcessSiteImage::class, fn (ProcessSiteImage $job): bool => $job->imageId === $image->id);
    }

    public function test_intake_refuses_problem_files_before_storing_anything(): void
    {
        ob_start();
        imagegif(imagecreatetruecolor(1440, 630));
        $gif = (string) ob_get_clean();
        // A WebP container header is enough for type detection, and needs no WebP support in GD.
        $webp = 'RIFF'.pack('V', 30).'WEBPVP8 '.pack('V', 18).str_repeat("\0", 18);
        $message = fn (string $code): string => SiteImageProblem::MESSAGES[$code];
        $cases = [
            'CMYK JPEG' => ['studio', F::flatJpeg(1440, 630, 4), $message('unsupported_pixel_format')],
            '12-bit JPEG' => ['studio', F::flatJpeg(1440, 630, 1, 12), $message('unsupported_depth')],
            'sideways JPEG' => ['studio', F::jpeg(1440, 630, ['exif' => 6]), $message('rotated_image')],
            'upside-down JPEG' => ['studio', F::jpeg(1440, 630, ['exif' => 3]), $message('rotated_image')],
            'mirrored JPEG' => ['studio', F::jpeg(1440, 630, ['exif' => 2]), $message('rotated_image')],
            'sideways PNG' => ['studio', F::png(1440, 630, 'rgb', [F::pngExif(8)]), $message('rotated_image')],
            // FFmpeg reads the last of several EXIF blocks, and skips a stray byte before a marker as libjpeg does.
            'sideways second JPEG EXIF block' => ['studio', F::jpeg(1440, 630, ['exif' => [1, 6]]), $message('rotated_image')],
            'sideways JPEG EXIF after a stray byte' => ['studio', F::jpeg(1440, 630, ['exif' => 6, 'stray' => true]), $message('rotated_image')],
            'sideways second PNG eXIf chunk' => ['studio', F::png(1440, 630, 'rgb', [F::pngExif(1), F::pngExif(6)]), $message('rotated_image')],
            // FFmpeg does not read PNG eXIf at all, so a chunk too large to check is refused.
            'oversized PNG eXIf' => ['studio', F::png(1440, 630, 'rgb', [F::pngExif(6, 70 * 1024)]), $message('oversized_metadata')],
            '16-bit PNG' => ['studio', F::png(1440, 630, 'rgb16'), $message('unsupported_depth')],
            '16-bit grey PNG' => ['studio', F::png(1440, 630, 'gray16'), $message('unsupported_depth')],
            'alpha PNG' => ['studio', F::png(1440, 630, 'rgba'), $message('transparent_image')],
            'grey alpha PNG' => ['studio', F::png(1440, 630, 'gray_alpha'), $message('transparent_image')],
            'palette transparency' => ['studio', F::png(1440, 630, 'palette_alpha'), $message('transparent_image')],
            'colour key' => ['studio', F::png(1440, 630, 'rgb_key'), $message('transparent_image')],
            'grey key' => ['studio', F::png(1440, 630, 'gray_key'), $message('transparent_image')],
            'oversized header' => ['studio', F::oversizedPngHeader(7000, 3063), 'larger than 6000 px on a side'],
            'GIF' => ['studio', $gif, 'Upload a JPEG or PNG image.'],
            'WebP' => ['studio', $webp, 'Upload a JPEG or PNG image.'],
            'text named .jpg' => ['studio', str_repeat('not an image ', 10), 'Upload a JPEG or PNG image.'],
            'broken JPEG header' => ['studio', substr(F::jpeg(1440, 630), 0, 40), 'The image could not be read'],
            'wrong shape' => ['studio', F::jpeg(2400, 890), 'This image is 2400 × 890 px. At least 1440 px wide, about 2.29:1 (for example 1440 × 630).'],
            'too narrow' => ['studio', F::jpeg(1200, 525), 'This image is 1200 × 525 px.'],
            'just outside tolerance' => ['studio', F::jpeg(1500, 630), 'This image is 1500 × 630 px.'],
            'share too small' => ['share', F::jpeg(1180, 620), 'This image is 1180 × 620 px. At least 1200 × 630 px, about 1.90:1. Trimmed to exactly 1200 × 630.'],
        ];
        foreach ($cases as $case => [$slot, $bytes, $expected]) {
            $errors = $this->refusal(fn () => $this->ingest($slot, $bytes));
            $this->assertSame(['upload'], array_keys($errors), $case);
            $this->assertStringContainsString($expected, $errors['upload'][0], $case);
        }
        $this->assertNothingStored();
        // Within 3% of the slot's shape is accepted, and so is an upright eXIf chunk of the largest size that is still read.
        $this->assertSame('quarantined', $this->ingest('studio', F::jpeg(1480, 630))->fresh()->status);
        $this->assertSame('quarantined', $this->ingest('studio', F::png(1440, 630, 'rgb', [F::pngExif(1, SiteImageInspection::MAX_EXIF_BYTES)]))->fresh()->status);
    }

    public function test_intake_requires_authorization_mfa_provenance_and_a_fresh_upload(): void
    {
        $bytes = F::jpeg(1440, 630);
        $staff = User::factory()->create();
        foreach ([$staff] as $unauthorized) {
            try {
                app(IngestSiteImage::class)->handle('studio', $this->upload($bytes), 'Credit', true, $unauthorized);
                $this->fail('A user without catalog administration must be refused.');
            } catch (AuthorizationException) {
            }
        }
        $cases = [
            ['logo', 'Credit', true, 'slot'], ['studio', '', true, 'credit'], ['studio', '   ', true, 'credit'],
            ['studio', str_repeat('a', 201), true, 'credit'], ['studio', 'Photo <b>bold</b>', true, 'credit'],
            ['studio', "Line\nbreak", true, 'credit'], ['studio', 'Credit', false, 'rights_confirmed'],
        ];
        foreach ($cases as [$slot, $credit, $rights, $field]) {
            $errors = $this->refusal(fn () => app(IngestSiteImage::class)->handle($slot, $this->upload($bytes), $credit, $rights, $this->actor));
            $this->assertSame([$field], array_keys($errors), json_encode([$slot, $credit, $rights]));
        }
        // A path to an existing private object is never accepted in place of an upload.
        foreach (['site-images/quarantine/x/source.upload', null, ['path' => 'x']] as $notAnUpload) {
            $errors = $this->refusal(fn () => app(IngestSiteImage::class)->handle('studio', $notAnUpload, 'Credit', true, $this->actor));
            $this->assertSame(['upload' => ['Choose a newly uploaded file.']], $errors);
        }
        $tooLarge = UploadedFile::fake()->create('large.jpg', 20 * 1024 + 1, 'image/jpeg');
        $this->assertSame(['upload' => ['Site images must be up to 20 MiB.']], $this->refusal(fn () => app(IngestSiteImage::class)->handle('studio', $tooLarge, 'Credit', true, $this->actor)));
        $this->assertSame(['upload' => ['Site images must be up to 20 MiB.']], $this->refusal(fn () => $this->ingest('studio', 'tiny')));
        $this->assertNothingStored();

        $panel = Filament::getPanel('admin');
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        try {
            $this->ingest('studio', $bytes);
            $this->fail('An administrator without MFA must be refused while MFA is required.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('Multi-factor authentication is required.', $exception->getMessage());
        }
        $this->actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->assertSame(str_repeat('a', 200), $this->ingest('studio', $bytes, str_repeat('a', 200))->credit);
    }

    public function test_processing_fails_permanently_for_problems_in_the_file(): void
    {
        $jpeg = F::jpeg(1440, 630);
        $cases = [
            ['rotated_image', F::jpeg(1440, 630, ['exif' => 6]), 1440, 630],
            ['rotated_image', F::jpeg(1440, 630, ['exif' => [1, 6]]), 1440, 630],
            ['oversized_metadata', F::png(1440, 630, 'rgb', [F::pngExif(6, 70 * 1024)]), 1440, 630],
            ['unsupported_pixel_format', F::flatJpeg(1440, 630, 4), 1440, 630],
            ['unsupported_depth', F::png(1440, 630, 'rgb16'), 1440, 630],
            ['transparent_image', F::png(1440, 630, 'palette_alpha'), 1440, 630],
            // Headers intact: part of the image data is missing, or almost all of it.
            ['processor_failed', substr($jpeg, 0, (int) (strlen($jpeg) * 0.6)), 1440, 630],
            ['invalid_image', substr($jpeg, 0, 1500), 1440, 630],
            // The recorded size no longer matches the file.
            ['invalid_image', F::jpeg(1480, 630), 1440, 630],
        ];
        foreach ($cases as [$code, $bytes, $width, $height]) {
            $image = $this->process(F::quarantined('studio', $bytes, $width, $height, $this->actor));
            $this->assertSame(['failed', $code, 1, null], [$image->status, $image->failure_code, $image->attempts, $image->claim_token], $code);
            $this->assertNotNull($image->processed_at);
            $this->assertSame(0, $image->variants()->count());
            $this->assertSame(['site.image.processing', 'site.image.failed'], $this->actions($image));
            $this->assertFalse(RetrySiteImage::retryable($image));
        }
        $this->scanner->reject = true;
        $rejected = $this->process($this->ingest('studio', F::jpeg(1440, 630)));
        $this->assertSame(['failed', 'scan_not_clean'], [$rejected->status, $rejected->failure_code]);
        $this->assertSame([], Storage::disk('local')->allFiles('site-images/revisions'));

        $this->expectException(LogicException::class);
        $rejected->update(['credit' => 'Changed']);
    }

    public function test_the_prober_classifies_decoded_formats_on_its_own(): void
    {
        $files = app(PrivateMediaFiles::class);
        $workspace = $files->workspace();
        try {
            $cases = [
                'rotated_image' => [F::jpeg(630, 1440, ['exif' => 6]), F::jpeg(1440, 630, ['exif' => 2])],
                'unsupported_pixel_format' => [F::flatJpeg(1440, 630, 4)],
                'unsupported_depth' => [F::flatJpeg(1440, 630, 1, 12), F::png(1440, 630, 'rgb16'), F::png(1440, 630, 'gray16')],
                'transparent_image' => [F::png(1440, 630, 'rgba'), F::png(1440, 630, 'gray_alpha'), F::png(1440, 630, 'rgb_key'), F::png(1440, 630, 'gray_key')],
            ];
            foreach ($cases as $code => $sources) {
                foreach ($sources as $index => $bytes) {
                    file_put_contents($workspace.'/probe', $bytes);
                    try {
                        app(SiteImageInspection::class)->inspect($workspace.'/probe', str_starts_with($bytes, "\x89PNG") ? 'image/png' : 'image/jpeg', $workspace);
                        $this->fail("{$code} #{$index} passed the prober.");
                    } catch (MediaFailure $failure) {
                        $this->assertSame($code, $failure->failureCode, "{$code} #{$index}");
                    }
                }
            }
            file_put_contents($workspace.'/probe', F::jpeg(1440, 630));
            $this->assertSame(['width' => 1440, 'height' => 630, 'pix_fmt' => 'yuvj444p'], app(SiteImageInspection::class)->inspect($workspace.'/probe', 'image/jpeg', $workspace));
            // Palette transparency decodes as plain pal8; the sanitized PNG still declares it.
            file_put_contents($workspace.'/probe', F::png(1440, 630, 'palette_alpha'));
            $this->assertSame('pal8', app(SiteImageInspection::class)->inspect($workspace.'/probe', 'image/png', $workspace)['pix_fmt']);
            file_put_contents($workspace.'/opaque', F::png(1440, 630, 'palette'));
            $this->assertTrue(SiteImageInspection::pngHasTransparency($workspace.'/probe'));
            $this->assertFalse(SiteImageInspection::pngHasTransparency($workspace.'/opaque'));
        } finally {
            $files->cleanup($workspace);
        }
    }

    public function test_a_temporary_problem_waits_for_a_retry_that_then_succeeds(): void
    {
        app()->instance(MalwareScanner::class, new class extends MalwareScanner
        {
            public function scan(string $path): array
            {
                throw new MediaFailure('scanner_unavailable', 'Synthetic outage.');
            }
        });
        $image = $this->process($this->ingest('studio', F::jpeg(1440, 630)));
        $this->assertSame(['quarantined', 'scanner_unavailable', 1, null, null], [$image->status, $image->failure_code, $image->attempts, $image->claim_token, $image->processed_at]);
        $this->assertSame(0, $image->variants()->count());
        $this->assertTrue(RetrySiteImage::retryable($image));

        app(RetrySiteImage::class)->handle($image, $this->actor);
        Queue::assertPushed(ProcessSiteImage::class, 2);
        app()->instance(MalwareScanner::class, $this->scanner);
        $ready = $this->process($image);

        $this->assertSame(['ready', null, 2], [$ready->status, $ready->failure_code, $ready->attempts]);
        $this->assertSame(['site.image.uploaded', 'site.image.processing', 'site.image.retry_pending', 'site.image.retry_requested', 'site.image.processing', 'site.image.processed'], $this->actions($image));
        $this->assertEquals(['status' => 'quarantined', 'failure_code' => 'scanner_unavailable'],
            AuditEvent::query()->where('action', 'site.image.retry_requested')->sole()->context);
    }

    public function test_an_unexpected_error_is_reported_and_left_for_a_retry(): void
    {
        Exceptions::fake();
        app()->instance(MalwareScanner::class, new class extends MalwareScanner
        {
            public function scan(string $path): array
            {
                throw new RuntimeException('Synthetic worker crash.');
            }
        });
        $image = $this->process($this->ingest('studio', F::jpeg(1440, 630)));

        $this->assertSame(['quarantined', 'processing_interrupted'], [$image->status, $image->failure_code]);
        Exceptions::assertReported(RuntimeException::class);
        $this->assertSame([], Storage::disk('local')->allFiles('site-images/revisions'));
    }

    /**
     * A scripted clamscan whose version line carries today's signature date. Each scan follows the mode file beside it.
     *
     * @return array{string, string} the executable and its mode file
     */
    private function scriptedClamscan(): array
    {
        $directory = sys_get_temp_dir().'/site-image-clamscan-'.Str::uuid();
        mkdir($directory, 0700);
        $this->beforeApplicationDestroyed(fn () => (new Filesystem)->deleteDirectory($directory));
        $script = $directory.'/clamscan';
        file_put_contents($script, implode("\n", [
            '#!/bin/sh',
            'mode=$(cat "$(dirname "$0")/mode")',
            'if [ "$1" = "--version" ]; then',
            '  if [ "$mode" = impostor ]; then echo "Impostor 1.0/1/'.date('D M d H:i:s Y').'"; else echo "ClamAV 1.4.1/27400/'.date('D M d H:i:s Y').'"; fi',
            '  exit 0',
            'fi',
            'for path; do :; done',
            'case "$mode" in',
            '  clean) echo "$path: OK" ;;',
            '  found) echo "$path: Eicar-Test-Signature FOUND"; exit 1 ;;',
            '  unverified) echo "$path: Scanned" ;;',
            '  error) echo "$path: Can\'t open file or directory ERROR" >&2; exit 2 ;;',
            '  signal) kill -KILL $$ ;;',
            '  slow) exec sleep 3 ;;',
            'esac',
        ])."\n");
        chmod($script, 0700);

        return [$script, $directory.'/mode'];
    }

    public function test_only_a_completed_scan_decides_and_scanner_problems_wait_for_a_retry(): void
    {
        [$clamscan, $mode] = $this->scriptedClamscan();
        app()->instance(MalwareScanner::class, new MalwareScanner);
        config(['media.clamscan' => $clamscan]);
        $timeout = config('media.process_timeout_seconds');
        $cases = [
            'clean' => ['ready', null],
            // A detection, or an exit 0 without the exact clean line, is a verdict on this file.
            'found' => ['failed', 'scan_not_clean'], 'unverified' => ['failed', 'scan_not_clean'],
            // A scanner error, crash, impostor or timeout says nothing about the file.
            'error' => ['quarantined', 'scanner_unavailable'], 'signal' => ['quarantined', 'scanner_unavailable'],
            'impostor' => ['quarantined', 'scanner_unavailable'], 'slow' => ['quarantined', 'processor_timeout'],
        ];
        foreach ($cases as $case => [$status, $code]) {
            file_put_contents($mode, $case);
            config(['media.process_timeout_seconds' => $case === 'slow' ? 1 : $timeout]);
            $image = $this->process($this->ingest('studio', F::jpeg(1440, 630)));

            $this->assertSame([$status, $code, 1], [$image->status, $image->failure_code, $image->attempts], $case);
            $this->assertSame($status === 'quarantined', RetrySiteImage::retryable($image), $case);
            $this->assertSame($status === 'ready' ? 6 : 0, $image->variants()->count(), $case);
        }
        $clean = SiteImage::query()->where('status', 'ready')->sole();
        $this->assertSame(['clamav', 'clean'], [$clean->evidence['source_scan']['engine'], $clean->evidence['source_scan']['status']]);
        $this->assertStringStartsWith('ClamAV 1.4.1/27400/', $clean->evidence['source_scan']['version']);
    }

    public function test_retry_is_refused_unless_the_image_is_waiting_and_checks_staff_access(): void
    {
        $ready = $this->process($this->ingest('studio', F::jpeg(1440, 630)));
        $this->assertSame(['image' => ['This image is not waiting for a retry.']], $this->refusal(fn () => app(RetrySiteImage::class)->handle($ready, $this->actor)));
        $waiting = $this->ingest('studio', F::jpeg(1440, 630));

        $panel = Filament::getPanel('admin');
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        try {
            app(RetrySiteImage::class)->handle($waiting, $this->actor);
            $this->fail('An administrator without MFA must not retry while MFA is required.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('Multi-factor authentication is required.', $exception->getMessage());
        }
        $this->assertNotContains('site.image.retry_requested', $this->actions($waiting));
        Queue::assertPushed(ProcessSiteImage::class, 2);
        $this->actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        app(RetrySiteImage::class)->handle($waiting, $this->actor);
        Queue::assertPushed(ProcessSiteImage::class, 3);

        $this->expectException(AuthorizationException::class);
        app(RetrySiteImage::class)->handle($waiting, User::factory()->create());
    }

    public function test_a_live_claim_is_left_alone_and_an_expired_one_is_taken_over(): void
    {
        $image = $this->ingest('studio', F::jpeg(1440, 630));
        $token = (string) Str::uuid();
        $image->forceFill(['status' => 'processing', 'claim_token' => $token, 'claimed_until' => now()->addSeconds(SiteImageProcessor::LEASE_SECONDS), 'attempts' => 1])->save();

        $untouched = $this->process($image);
        $this->assertSame(['processing', $token, 1], [$untouched->status, $untouched->claim_token, $untouched->attempts]);
        $this->assertFalse(RetrySiteImage::retryable($untouched));

        $this->travel(SiteImageProcessor::LEASE_SECONDS + 1)->seconds();
        $this->assertTrue(RetrySiteImage::retryable($untouched->fresh()));
        $expired = $untouched->fresh()->claimed_until->utc()->toIso8601ZuluString();
        $ready = $this->process($image);
        $this->assertSame(['ready', 2], [$ready->status, $ready->attempts]);
        // The crashed worker's attempt is on record before the takeover's own attempt.
        $this->assertSame(['site.image.uploaded', 'site.image.retry_pending', 'site.image.processing', 'site.image.processed'], $this->actions($image));
        $this->assertEquals(['failure_code' => 'processing_interrupted', 'attempt' => 1, 'claimed_until' => $expired],
            AuditEvent::query()->where('action', 'site.image.retry_pending')->sole()->context);
        $this->assertEquals(['attempt' => 2], AuditEvent::query()->where('action', 'site.image.processing')->sole()->context);
    }

    public function test_a_linked_storage_directory_waits_for_a_fix_while_a_linked_upload_fails(): void
    {
        $root = rtrim(Storage::disk('local')->path(''), '/');
        $image = $this->ingest('studio', F::jpeg(1440, 630));
        rename($root.'/site-images', $root.'/site-images-moved');
        symlink($root.'/site-images-moved', $root.'/site-images');

        $waiting = $this->process($image);
        $this->assertSame(['quarantined', 'unsafe_storage', 1], [$waiting->status, $waiting->failure_code, $waiting->attempts]);
        $this->assertTrue(RetrySiteImage::retryable($waiting));
        // Once the operator restores the directory, the retry prepares the same upload.
        unlink($root.'/site-images');
        rename($root.'/site-images-moved', $root.'/site-images');
        $this->assertSame(['ready', 2], [($ready = $this->process($image))->status, $ready->attempts]);

        // A link in place of the upload itself is a problem with that file, so it fails for good.
        $linked = $this->ingest('studio', F::jpeg(1440, 630));
        $path = $root.'/'.$linked->source_path;
        rename($path, $path.'.moved');
        symlink($path.'.moved', $path);
        $failed = $this->process($linked);
        $this->assertSame(['failed', 'unsafe_path'], [$failed->status, $failed->failure_code]);
    }

    public function test_a_run_that_lost_its_claim_writes_nothing(): void
    {
        foreach (['complete', 'fail'] as $outcome) {
            $image = $this->ingest('studio', F::jpeg(1440, 630));
            $takeover = (string) Str::uuid();
            $real = app(SiteImageDerivatives::class);
            // Another worker takes the image over while this run is still preparing it.
            app()->instance(SiteImageDerivatives::class, new class($real, $image->id, $takeover, $outcome)
            {
                public function __construct(private SiteImageDerivatives $real, private int $id, private string $token, private string $outcome) {}

                public function build(string $png, string $slot, int $width, int $height, string $workspace): array
                {
                    $outputs = $this->real->build($png, $slot, $width, $height, $workspace);
                    DB::table('site_images')->where('id', $this->id)->update(['claim_token' => $this->token, 'claimed_until' => now()->addMinutes(20), 'attempts' => 2]);
                    if ($this->outcome === 'fail') {
                        throw new MediaFailure('invalid_image', 'Synthetic late failure.');
                    }

                    return $outputs;
                }
            });
            $after = $this->process($image);
            app()->forgetInstance(SiteImageDerivatives::class);

            $this->assertSame(['processing', $takeover, 2, null], [$after->status, $after->claim_token, $after->attempts, $after->failure_code], $outcome);
            $this->assertSame(0, $after->variants()->count());
            $this->assertNotContains('site.image.processed', $this->actions($image));
            $this->assertNotContains('site.image.failed', $this->actions($image));
            $this->assertSame([], Storage::disk('local')->allFiles('site-images/revisions'));
        }
    }

    public function test_an_error_after_the_ready_commit_keeps_every_prepared_file(): void
    {
        Exceptions::fake();
        $image = $this->ingest('studio', F::jpeg(1440, 630));
        $raised = false;
        // The ready transaction commits and then something fails, as a dropped connection or a listener can.
        Event::listen(TransactionCommitted::class, function () use ($image, &$raised): void {
            if (! $raised && SiteImage::query()->whereKey($image->id)->value('status') === 'ready') {
                $raised = true;
                throw new PDOException('Synthetic failure after the ready commit.');
            }
        });
        $ready = $this->process($image);

        $this->assertTrue($raised);
        Exceptions::assertReported(PDOException::class);
        $this->assertSame(['ready', null], [$ready->status, $ready->failure_code]);
        $variants = $ready->variants()->get();
        $this->assertCount(6, $variants);
        foreach ($variants as $variant) {
            $this->assertFileExists(Storage::disk('local')->path($variant->storage_path));
            $bytes = app(SiteImageFiles::class)->verifiedBytes($variant);
            $this->assertIsString($bytes, $variant->storage_path);
            $this->assertSame($variant->sha256, hash('sha256', $bytes));
        }
    }

    public function test_cleanup_leaves_orphans_when_it_cannot_check_what_the_rows_reference(): void
    {
        Exceptions::fake();
        $image = $this->ingest('studio', F::jpeg(1440, 630));
        $real = app(SiteImageDerivatives::class);
        // Another worker takes the image over while this run prepares it, so this run promotes files it never records.
        app()->instance(SiteImageDerivatives::class, new class($real, $image->id)
        {
            public function __construct(private SiteImageDerivatives $real, private int $id) {}

            public function build(string $png, string $slot, int $width, int $height, string $workspace): array
            {
                $outputs = $this->real->build($png, $slot, $width, $height, $workspace);
                DB::table('site_images')->where('id', $this->id)->update(['claim_token' => (string) Str::uuid(), 'claimed_until' => now()->addMinutes(20), 'attempts' => 2]);

                return $outputs;
            }
        });
        DB::connection()->beforeExecuting(function (string $query): void {
            if (preg_match('/from [`"]site_image_variants[`"] where [`"]storage_path[`"] in /', $query) === 1) {
                throw new PDOException('Synthetic failure of the reference lookup.');
            }
        });
        $after = $this->process($image);

        $this->assertSame(['processing', 2, 0], [$after->status, $after->attempts, $after->variants()->count()]);
        Exceptions::assertReported(fn (PDOException $exception): bool => $exception->getMessage() === 'Synthetic failure of the reference lookup.');
        $this->assertCount(6, Storage::disk('local')->allFiles('site-images/revisions'));
    }

    public function test_a_queue_outage_after_intake_keeps_the_waiting_image_and_its_upload(): void
    {
        Exceptions::fake();
        // Like a real connection, an after-commit job is sent once its transaction commits, and here the send fails.
        Queue::swap(new class(app()) extends QueueFake
        {
            public function push($job, $data = '', $queue = null)
            {
                $send = fn () => throw new RuntimeException('Synthetic queue outage.');

                return is_object($job) && ($job->afterCommit ?? false) ? app('db.transactions')->addCallback($send) : $send();
            }
        });
        $failure = null;
        try {
            $image = $this->ingest('studio', F::jpeg(1440, 630));
        } catch (RuntimeException $failure) {
        }

        $stored = SiteImage::query()->sole();
        $this->assertSame(['quarantined', 0], [$stored->status, $stored->attempts]);
        $this->assertTrue(Storage::disk('local')->exists($stored->source_path), 'The committed upload was deleted.');
        $this->assertNull($failure, 'Intake failed after it had committed the image.');
        $this->assertSame($stored->id, $image->id);
        $this->assertTrue(RetrySiteImage::retryable($stored));
        Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'Synthetic queue outage.');
        $this->assertSame(['site.image.uploaded'], $this->actions($stored));
        $this->assertSame('ready', $this->process($stored)->status);
    }

    public function test_database_guards_keep_images_immutable_and_transitions_valid(): void
    {
        $refused = function (callable $statement, string $case): void {
            try {
                $statement();
                $this->fail("The database accepted: {$case}");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        };
        $row = fn (array $overrides = []): array => $overrides + [
            'slot' => 'studio', 'original_name' => 'raw.jpg', 'source_path' => 'site-images/quarantine/'.Str::uuid().'/source.upload',
            'source_sha256' => str_repeat('a', 64), 'size_bytes' => 10, 'mime_type' => 'image/jpeg', 'width' => 1440, 'height' => 630,
            'credit' => 'Raw', 'rights_confirmed_at' => now(), 'uploaded_by' => $this->actor->id, 'created_at' => now(), 'updated_at' => now(),
        ];
        // Spellings that MySQL's default collation would call equal (case, accents, trailing spaces) are refused like any other.
        foreach ([['status' => 'ready'], ['slot' => 'logo'], ['mime_type' => 'image/gif'], ['source_path' => 'media/quarantine/x/source.bin'],
            ['credit' => '   '], ['attempts' => 1], ['failure_code' => 'x'], ['status' => 'Quarantined'], ['slot' => 'Studio'], ['slot' => 'studio '],
            ['mime_type' => 'IMAGE/JPEG'], ['source_path' => 'sïte-images/quarantine/'.Str::uuid().'/source.upload']] as $overrides) {
            $refused(fn () => DB::table('site_images')->insert($row($overrides)), 'insert '.json_encode($overrides));
        }
        $waiting = DB::table('site_images')->insertGetId($row());
        $table = fn () => DB::table('site_images')->where('id', $waiting);
        $refused(fn () => $table()->delete(), 'delete');
        $refused(fn () => $table()->update(['credit' => 'Changed']), 'credit change');
        $refused(fn () => $table()->update(['status' => 'ready', 'manifest_sha256' => str_repeat('b', 64), 'processed_at' => now()]), 'quarantined to ready');
        $refused(fn () => $table()->update(['status' => 'processing', 'claim_token' => (string) Str::uuid(), 'claimed_until' => now()]), 'claim without an attempt');
        $refused(fn () => DB::table('site_image_variants')->insert(['site_image_id' => $waiting, 'format' => 'jpeg', 'width' => 1, 'height' => 1,
            'storage_path' => 'site-images/revisions/x/1.jpg', 'sha256' => str_repeat('c', 64), 'size_bytes' => 1, 'created_at' => now()]), 'variant for a waiting image');
        // Each identity change rides along with an otherwise valid claim, so only the byte-exact identity check can refuse it.
        $claim = fn (): array => ['status' => 'processing', 'claim_token' => (string) Str::uuid(), 'claimed_until' => now(), 'attempts' => 1];
        foreach (['credit case' => ['credit' => DB::raw('UPPER(credit)')], 'credit accent' => ['credit' => 'Ráw'], 'credit padding' => ['credit' => 'Raw '],
            'name case' => ['original_name' => DB::raw('UPPER(original_name)')], 'path case' => ['source_path' => DB::raw('UPPER(source_path)')],
            'hash case' => ['source_sha256' => str_repeat('A', 64)], 'type case' => ['mime_type' => 'IMAGE/JPEG'], 'slot case' => ['slot' => 'STUDIO']] as $case => $change) {
            $refused(fn () => $table()->update($change + $claim()), $case.' with a claim');
        }
        $token = (string) Str::uuid();
        $table()->update(['status' => 'processing', 'claim_token' => $token, 'claimed_until' => now(), 'attempts' => 1]);
        $refused(fn () => $table()->update(['claim_token' => $token, 'claimed_until' => now()->addMinutes(20), 'attempts' => 2]), 'claim again with the same token');
        $refused(fn () => $table()->update(['status' => 'quarantined', 'claim_token' => null, 'claimed_until' => null]), 'back to waiting without a failure code');
        $refused(fn () => $table()->update(['status' => 'failed', 'claim_token' => null, 'claimed_until' => null, 'failure_code' => 'invalid_image']), 'failed without a processing time');
        $refused(fn () => $table()->update(['status' => 'ready', 'claim_token' => null, 'claimed_until' => null, 'manifest_sha256' => str_repeat('b', 64),
            'processed_at' => now(), 'profile_version' => 'v', 'profile_fingerprint' => str_repeat('d', 64), 'evidence' => '{}']), 'ready without variants');
        $refused(fn () => DB::table('site_image_variants')->insert(['site_image_id' => $waiting, 'format' => 'jpeg', 'width' => 1, 'height' => 1,
            'storage_path' => 'media/revisions/x/1.jpg', 'sha256' => str_repeat('c', 64), 'size_bytes' => 1, 'created_at' => now()]), 'variant outside site-images');
        $refused(fn () => DB::table('site_image_variants')->insert(['site_image_id' => $waiting, 'format' => 'JPEG', 'width' => 1, 'height' => 1,
            'storage_path' => 'site-images/revisions/x/1.jpg', 'sha256' => str_repeat('c', 64), 'size_bytes' => 1, 'created_at' => now()]), 'variant format case');
        $table()->update(['status' => 'failed', 'claim_token' => null, 'claimed_until' => null, 'failure_code' => 'invalid_image', 'processed_at' => now()]);
        $refused(fn () => $table()->update(['status' => 'quarantined', 'processed_at' => null]), 'failed back to waiting');

        $ready = $this->process($this->ingest('studio', F::jpeg(1440, 630)));
        $refused(fn () => DB::table('site_images')->where('id', $ready->id)->update(['credit' => 'Changed']), 'ready image change');
        $refused(fn () => DB::table('site_images')->where('id', $ready->id)->update(['status' => 'failed', 'failure_code' => 'x']), 'ready to failed');
        $variant = $ready->variants()->first();
        $refused(fn () => DB::table('site_image_variants')->where('id', $variant->id)->update(['sha256' => str_repeat('e', 64)]), 'variant change');
        $refused(fn () => DB::table('site_image_variants')->where('id', $variant->id)->delete(), 'variant delete');
        $refused(fn () => DB::table('site_image_variants')->insert(['site_image_id' => $ready->id, 'format' => 'jpeg', 'width' => 2, 'height' => 1,
            'storage_path' => 'site-images/revisions/y/2.jpg', 'sha256' => str_repeat('c', 64), 'size_bytes' => 1, 'created_at' => now()]), 'variant for a ready image');
        $this->assertTrue(SiteImageManifest::matches($ready->fresh()));
    }

    public function test_the_manifest_covers_the_slot_profile_and_every_variant(): void
    {
        $ready = $this->process($this->ingest('studio', F::jpeg(1440, 630)));
        $variants = $ready->variants()->get();
        $hash = SiteImageManifest::hash('studio', $ready->profile_fingerprint, $variants);
        $this->assertSame($ready->manifest_sha256, $hash);
        $this->assertSame($hash, SiteImageManifest::hash('studio', $ready->profile_fingerprint, $variants->reverse()));
        $this->assertNotSame($hash, SiteImageManifest::hash('share', $ready->profile_fingerprint, $variants));
        $this->assertNotSame($hash, SiteImageManifest::hash('studio', str_repeat('0', 64), $variants));
        $this->assertNotSame($hash, SiteImageManifest::hash('studio', $ready->profile_fingerprint, $variants->slice(1)));
        $changed = $variants->map(fn (SiteImageVariant $v): array => $v->only(['format', 'width', 'height', 'sha256', 'size_bytes']))->all();
        $changed[0]['sha256'] = str_repeat('f', 64);
        $this->assertNotSame($hash, SiteImageManifest::hash('studio', $ready->profile_fingerprint, $changed));
        $stale = $ready->replicate()->forceFill(['id' => $ready->id, 'profile_fingerprint' => str_repeat('0', 64)]);
        $this->assertFalse(SiteImageManifest::matches($stale));
        // A later profile may change a slot's sizes; an image keeps the exact set its manifest pins. Here six stored files stand
        // for a slot whose current definition has one size.
        $older = $ready->replicate()->forceFill(['id' => $ready->id, 'slot' => 'share',
            'manifest_sha256' => SiteImageManifest::hash('share', $ready->profile_fingerprint, $variants)]);
        $this->assertNotSame(count($variants), SiteImageSlot::variantCount('share'));
        $this->assertTrue(SiteImageManifest::matches($older));
    }

    public function test_migration_rollback_is_refused_once_an_image_exists(): void
    {
        $this->ingest('studio', F::jpeg(1440, 630));
        $migration = require database_path('migrations/2026_09_30_000027_site_images.php');

        $this->expectException(LogicException::class);
        $migration->down();
    }
}
