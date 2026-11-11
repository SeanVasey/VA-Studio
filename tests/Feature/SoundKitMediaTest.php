<?php

namespace Tests\Feature;

use App\Domain\Customers\DataRights\RetentionSchedule;
use App\Domain\Media\AudioDerivatives;
use App\Domain\Media\MalwareScanner;
use App\Domain\Media\MediaFailure;
use App\Domain\Media\PrivateMediaFiles;
use App\Domain\SiteBuilder\Models\SiteImageVariant;
use App\Domain\SoundKits\Products\SoundKitDemo;
use App\Domain\SoundKits\Products\SoundKitDemoProcessor;
use App\Domain\SoundKits\Products\SoundKitProduct;
use App\Domain\SoundKits\Products\SoundKitProductMedia;
use App\Domain\SoundKits\Products\SoundKitProducts;
use App\Filament\Resources\SoundKitProductResource\Pages\ManageSoundKitProducts;
use App\Http\Middleware\HandleInertiaRequests;
use App\Jobs\ProcessSoundKitDemo;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\Support\SiteImageFixtures as F;
use Tests\Support\SoundKitMediaFixtures;
use Tests\Support\SoundKitProductFixtures;
use Tests\Support\TestOnlyMediaScanner;
use Tests\TestCase;

/**
 * BL-53 follow-up (FP-007): the public cover art and demo audio of a sound-kit product. Every kit, image and sound below is
 * synthetic test data. The processing runs through the real media tools with the testing-only scanner.
 */
class SoundKitMediaTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use SoundKitProductFixtures;

    private User $actor;

    private TestOnlyMediaScanner $scanner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        $this->scanner = MediaFixtures::configure();
        config(['app.url' => 'https://audio.example.test', 'app.debug' => false]);
        $this->actor = LicenseFixtures::admin();
        $this->actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function media(): SoundKitProductMedia
    {
        return app(SoundKitProductMedia::class);
    }

    /** A product that is saved, rights-cleared and offer-approved, with no cover or demo yet. */
    private function bare(array $over = []): SoundKitProduct
    {
        return $this->product('approved', $over, media: false);
    }

    private function withCover(SoundKitProduct $product): SoundKitProduct
    {
        $product = $this->media()->chooseCover($product, $product->revision, SoundKitMediaFixtures::cover($this->actor)->id, 'A synthetic test cover', $this->actor);

        return $this->media()->approveCover($product, $product->revision, $this->actor);
    }

    private function upload(string $name = 'demo.wav', ?string $bytes = null): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $bytes ?? MediaFixtures::wav(1.2, 330));
    }

    private function enable(): void
    {
        config(['sound-kits.storefront_enabled' => true]);
    }

    private function messages(callable $operation): array
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            return $exception->errors();
        }
        $this->fail('The operation was accepted.');
    }

    private function inertia(): array
    {
        return ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/'))];
    }

    private function previewFile(SoundKitDemo $demo): string
    {
        return app(PrivateMediaFiles::class)->resolve($demo->preview_path);
    }

    /** A long, tiny mono WAV (5 MB) to prove the duration cap without a large fixture. */
    private function longWav(int $seconds): string
    {
        $path = sys_get_temp_dir().'/vasey-long-'.bin2hex(random_bytes(6)).'.wav';
        try {
            (new Process([config('media.ffmpeg'), '-nostdin', '-hide_banner', '-loglevel', 'error', '-f', 'lavfi', '-i', 'sine=frequency=220:sample_rate=8000:duration='.$seconds, '-ac', '1', '-c:a', 'pcm_s16le', $path]))
                ->setTimeout(60)->mustRun();

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    public function test_a_kit_is_listed_only_with_an_approved_cover_and_an_approved_demo(): void
    {
        $service = app(SoundKitProducts::class);
        $product = $this->bare();
        $this->assertSame(['cover', 'demo'], array_keys(array_intersect_key($service->blockers($product), ['cover' => 1, 'demo' => 1])));
        $errors = $this->messages(fn () => $service->publish($product, $product->revision, $this->actor));
        $this->assertSame(['cover', 'demo'], array_keys($errors));
        $this->assertFalse($product->refresh()->is_published);

        // A cover alone is not enough, and a demo alone is not enough.
        $product = $this->withCover($product);
        $this->assertSame(['demo'], array_keys($service->blockers($product)));
        $this->assertSame(['demo'], array_keys($this->messages(fn () => $service->publish($product, $product->revision, $this->actor))));

        $product = $this->media()->approveDemo(...$this->processedDemo($product));
        $this->assertSame([], $service->blockers($product));
        $product = $service->publish($product, $product->revision, $this->actor);
        $this->assertTrue($service->listed($product));

        // Withdrawing the cover approval behind the writer hides the published kit at once.
        DB::table('sound_kit_products')->where('id', $product->id)->update(['cover_approval_hash' => null]);
        $this->assertFalse($service->listed($product->refresh()));
        $this->assertArrayHasKey('cover', $service->blockers($product));
        DB::table('sound_kit_products')->where('id', $product->id)->update(['cover_approval_hash' => $this->media()->coverBinding($product)]);
        $this->assertTrue($service->listed($product->refresh()));
        // Likewise the demo.
        DB::table('sound_kit_products')->where('id', $product->id)->update(['demo_approval_hash' => str_repeat('a', 64)]);
        $this->assertFalse($service->listed($product->refresh()));
        $this->assertArrayHasKey('demo', $service->blockers($product));
    }

    /** @return array{0: SoundKitProduct, 1: int, 2: User} arguments for approveDemo after a real upload and processing */
    private function processedDemo(SoundKitProduct $product): array
    {
        $demo = SoundKitMediaFixtures::demo($product, $this->actor);
        $this->assertSame('ready', $demo->status, (string) $demo->failure_code);
        $product->refresh();

        return [$product, $product->revision, $this->actor];
    }

    public function test_only_a_ready_square_release_cover_can_be_chosen_and_it_needs_a_description(): void
    {
        $product = $this->bare();
        $ready = SoundKitMediaFixtures::cover($this->actor);
        $this->assertSame('release_cover', $ready->slot);
        $hero = F::ready('hero_desktop', $this->actor);
        $waiting = F::quarantined('release_cover', F::jpeg(1200, 1200), 1200, 1200, $this->actor);
        $rejected = fn (mixed $id, mixed $alt = 'A synthetic cover') => $this->messages(fn () => $this->media()->chooseCover($product->refresh(), $product->revision, $id, $alt, $this->actor));
        foreach ([$hero->id, $waiting->id, 999999, 0, -3, 'abc', null, [], 1.5] as $id) {
            $this->assertArrayHasKey('cover', $rejected($id), 'accepted: '.json_encode($id));
        }
        foreach ([null, '', '   ', '<b>x</b>', "two\nlines", str_repeat('x', 201), 7, []] as $alt) {
            $this->assertArrayHasKey('cover_alt', $rejected($ready->id, $alt), 'accepted: '.json_encode($alt));
        }
        $this->assertNull($product->refresh()->cover_image_id);
        $this->assertArrayHasKey('cover', $this->messages(fn () => $this->media()->approveCover($product, $product->revision, $this->actor)), 'nothing chosen yet');

        $chosen = $this->media()->chooseCover($product, $product->revision, (string) $ready->id, '  A synthetic cover  ', $this->actor);
        $this->assertSame([$ready->id, 'A synthetic cover', $ready->manifest_sha256], [$chosen->cover_image_id, $chosen->cover_alt, $chosen->cover_manifest_sha256]);
        $this->assertNull($chosen->cover_approval_hash, 'choosing approves nothing');
        $this->assertArrayHasKey('cover', app(SoundKitProducts::class)->blockers($chosen));
    }

    public function test_a_cover_approval_is_bound_to_the_exact_image_and_a_replacement_voids_it(): void
    {
        $service = app(SoundKitProducts::class);
        $product = $this->withCover($this->bare());
        $first = $product->cover_image_id;
        $this->assertNotNull($product->cover_approved_by);
        $this->assertArrayNotHasKey('cover', $service->blockers($product));

        // A changed description does not change the image, so the approval stands; a different image voids it.
        $product = $this->media()->chooseCover($product, $product->revision, $first, 'A reworded description', $this->actor);
        $this->assertNotNull($product->cover_approval_hash);
        $second = SoundKitMediaFixtures::cover($this->actor);
        $product = $this->media()->chooseCover($product, $product->revision, $second->id, 'A synthetic replacement', $this->actor);
        $this->assertSame([null, null, null], [$product->cover_approval_hash, $product->cover_approved_at, $product->cover_approved_by]);
        $this->assertSame('Approve the chosen cover art.', $service->blockers($product)['cover']);
        $product = $this->media()->approveCover($product, $product->revision, $this->actor);
        $this->assertArrayNotHasKey('cover', $service->blockers($product));

        // Behind the writer: pointing the product at the first image again, or at a changed pin, cannot reuse the approval.
        DB::table('sound_kit_products')->where('id', $product->id)->update(['cover_image_id' => $first]);
        $this->assertArrayHasKey('cover', $service->blockers($product->refresh()));
        DB::table('sound_kit_products')->where('id', $product->id)->update(['cover_image_id' => $second->id, 'cover_manifest_sha256' => str_repeat('b', 64)]);
        $this->assertArrayHasKey('cover', $service->blockers($product->refresh()));
        DB::table('sound_kit_products')->where('id', $product->id)->update(['cover_manifest_sha256' => $second->manifest_sha256, 'cover_alt' => '']);
        $this->assertArrayHasKey('cover', $service->blockers($product->refresh()), 'no description');
    }

    public function test_demo_uploads_that_are_too_big_the_wrong_type_or_too_long_are_refused_and_leave_nothing_behind(): void
    {
        $product = $this->bare();
        $png = MediaFixtures::png();
        $cases = [
            'oversize' => UploadedFile::fake()->create('big.wav', 102401),
            'png named wav' => $this->upload('demo.wav', $png),
            'text named wav' => $this->upload('demo.wav', str_repeat('not audio at all. ', 50)),
            'truncated wav' => $this->upload('demo.wav', substr(MediaFixtures::wav(1.2, 330), 0, 400)),
            'too small' => $this->upload('tiny.wav', 'RIFF'),
        ];
        foreach ($cases as $label => $file) {
            $this->assertArrayHasKey('demo', $this->messages(fn () => $this->media()->uploadDemo($product->refresh(), $product->revision, $file, $this->actor)), $label);
        }
        // A stored path or a bare string is never accepted as an upload.
        foreach (['sound-kits/revisions/x/archive.zip', null, 123] as $value) {
            $this->assertArrayHasKey('demo', $this->messages(fn () => $this->media()->uploadDemo($product->refresh(), $product->revision, $value, $this->actor)));
        }
        $this->assertSame(0, SoundKitDemo::count());
        $this->assertNull($product->refresh()->demo_id);
        $this->assertSame([], array_filter(Storage::disk('local')->allFiles(), fn (string $file): bool => str_starts_with($file, 'sound-kit-demos/')));
        $this->assertSame(0, DB::table('audit_events')->where('action', 'sound_kit_products.product.demo_uploaded')->count());
    }

    public function test_the_demo_processor_builds_no_untagged_preview(): void
    {
        // Track runs also make an untagged public preview; a kit demo is a single tagged taster, so the real processor must
        // ask for none (PR #322 review P2-1). The spy records what the processor actually asked AudioDerivatives for.
        $spy = new class extends AudioDerivatives
        {
            public array $calls = [];

            public function build(string $master, string $tag, array $profile, string $workspace, bool $untaggedPreview = true): array
            {
                $outputs = parent::build($master, $tag, $profile, $workspace, $untaggedPreview);
                $this->calls[] = ['untagged' => $untaggedPreview, 'roles' => array_column($outputs, 'role')];

                return $outputs;
            }
        };
        $this->app->instance(AudioDerivatives::class, $spy);
        $product = $this->bare();
        $demo = $this->media()->uploadDemo($product, $product->revision, $this->upload(), $this->actor);
        $ready = app(SoundKitDemoProcessor::class)->handle($demo->id);
        $this->assertSame('ready', $ready->status, (string) $ready->failure_code);
        $this->assertSame([['untagged' => false, 'roles' => ['master_wav', 'download_mp3', 'preview_tagged']]], $spy->calls);
    }

    public function test_a_demo_is_scanned_and_becomes_the_same_tagged_preview_a_track_gets(): void
    {
        $product = $this->bare();
        $source = MediaFixtures::wav(1.2, 330);
        $demo = $this->media()->uploadDemo($product, $product->revision, $this->upload('demo.wav', $source), $this->actor);
        $this->assertSame(['quarantined', $demo->id], [$demo->status, $product->refresh()->demo_id]);
        $this->assertStringStartsWith('sound-kit-demos/quarantine/', $demo->source_path);
        $this->assertArrayHasKey('demo', app(SoundKitProducts::class)->blockers($product), 'waiting is not ready');
        $this->assertArrayHasKey('demo', $this->messages(fn () => $this->media()->approveDemo($product, $product->revision, $this->actor)));

        $ready = app(SoundKitDemoProcessor::class)->handle($demo->id);
        $this->assertSame('ready', $ready->status, (string) $ready->failure_code);
        $this->assertSame('clean', $ready->evidence['source_scan']['status']);
        $this->assertSame($ready->source_sha256, $ready->evidence['source_scan']['sha256']);
        $this->assertSame('clean', $ready->evidence['tag_scan']['status']);
        // The approved seller tag is mixed in exactly as for a track preview.
        $this->assertSame(config('media.tag_sha256'), $ready->evidence['tag_sha256']);
        $this->assertSame((int) config('media.tag_interval_seconds'), $ready->evidence['tag_interval_seconds']);
        $this->assertStringStartsWith('sound-kit-demos/revisions/', $ready->preview_path);
        $file = $this->previewFile($ready);
        $this->assertSame('audio/mpeg', (new \finfo(FILEINFO_MIME_TYPE))->file($file));
        $this->assertSame(hash_file('sha256', $file), $ready->preview_sha256);
        $this->assertNotSame($ready->source_sha256, $ready->preview_sha256, 'the tagged preview is not the source');
        $this->assertSame(200, count($ready->waveform));
        $this->assertGreaterThanOrEqual(1, $ready->duration_seconds);
        // The full-quality source stays in private quarantine, and a processed row never changes.
        $this->assertSame($source, Storage::disk('local')->get($ready->source_path));
        $this->assertSame('ready', $ready->status);
        try {
            $ready->forceFill(['original_name' => 'changed.wav'])->save();
            $this->fail('A processed demo changed.');
        } catch (\LogicException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_an_infected_or_unscannable_demo_never_becomes_ready_and_temporary_problems_can_be_retried(): void
    {
        $product = $this->bare();
        // A scan that does not return clean evidence is final.
        $this->scanner->reject = true;
        $demo = $this->media()->uploadDemo($product, $product->revision, $this->upload(), $this->actor);
        $failed = app(SoundKitDemoProcessor::class)->handle($demo->id);
        $this->assertSame(['failed', 'scan_not_clean', null], [$failed->status, $failed->failure_code, $failed->preview_path]);
        $this->assertStringContainsString('Upload a new demo', app(SoundKitProducts::class)->blockers($product->refresh())['demo']);
        $this->assertArrayHasKey('demo', $this->messages(fn () => $this->media()->approveDemo($product, $product->revision, $this->actor)));
        $this->assertArrayHasKey('demo', $this->messages(fn () => $this->media()->retryDemo($product->refresh(), $product->revision, $this->actor)), 'a final failure is not retried');
        $this->assertSame([], array_filter(Storage::disk('local')->allFiles(), fn (string $file): bool => str_starts_with($file, 'sound-kit-demos/revisions/')));
        $this->scanner->reject = false;

        // A scanner that is unavailable, or a missing tag, is the operator's to fix: the demo waits and a retry completes it.
        app()->instance(MalwareScanner::class, new class extends TestOnlyMediaScanner
        {
            public function scan(string $path): array
            {
                throw new MediaFailure('scanner_unavailable', 'The scanner is down.');
            }
        });
        $demo = $this->media()->uploadDemo($product->refresh(), $product->revision, $this->upload(), $this->actor);
        $waiting = app(SoundKitDemoProcessor::class)->handle($demo->id);
        $this->assertSame(['quarantined', 'scanner_unavailable'], [$waiting->status, $waiting->failure_code]);
        app()->instance(MalwareScanner::class, $this->scanner);
        $tag = config('media.tag_path');
        config(['media.tag_path' => null]);
        $this->media()->retryDemo($product->refresh(), $product->revision, $this->actor);
        $waiting = app(SoundKitDemoProcessor::class)->handle($demo->id);
        $this->assertSame(['quarantined', 'tag_not_configured'], [$waiting->status, $waiting->failure_code]);
        config(['media.tag_path' => $tag, 'media.tag_sha256' => str_repeat('0', 64)]);
        $waiting = app(SoundKitDemoProcessor::class)->handle($demo->id);
        $this->assertSame('tag_hash_mismatch', $waiting->failure_code, 'a tag that is not the approved one is refused');
        config(['media.tag_sha256' => hash('sha256', MediaFixtures::wav(0.2, 1800))]);
        $this->media()->retryDemo($product->refresh(), $product->revision, $this->actor);
        $done = app(SoundKitDemoProcessor::class)->handle($demo->id);
        $this->assertSame(['ready', null], [$done->status, $done->failure_code]);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'sound_kit_demo.processed')->count());
        $this->assertSame(2, DB::table('audit_events')->where('action', 'sound_kit_products.product.demo_retry_queued')->count());
    }

    public function test_a_demo_approval_is_bound_to_the_exact_preview_and_a_replacement_voids_it(): void
    {
        $service = app(SoundKitProducts::class);
        $product = $this->bare();
        $first = SoundKitMediaFixtures::demo($product, $this->actor);
        $product = $this->media()->approveDemo($product->refresh(), $product->revision, $this->actor);
        $this->assertArrayNotHasKey('demo', $service->blockers($product));
        $this->assertNotNull($product->demo_approved_by);

        $second = SoundKitMediaFixtures::demo($product, $this->actor, MediaFixtures::wav(1.5, 550));
        $product->refresh();
        $this->assertSame($second->id, $product->demo_id);
        $this->assertSame([null, null, null], [$product->demo_approval_hash, $product->demo_approved_at, $product->demo_approved_by]);
        $this->assertSame('Approve the demo audio.', $service->blockers($product)['demo']);
        // The first demo stays on record, unapproved; it cannot be approved in place of the current one.
        $this->assertSame('ready', $first->refresh()->status);
        $product = $this->media()->approveDemo($product, $product->revision, $this->actor);
        $this->assertArrayNotHasKey('demo', $service->blockers($product));

        // Behind the writer: re-pointing at the old demo, or at a forged approval, shows nothing.
        DB::table('sound_kit_products')->where('id', $product->id)->update(['demo_id' => $first->id]);
        $this->assertArrayHasKey('demo', $service->blockers($product->refresh()));
        DB::table('sound_kit_products')->where('id', $product->id)->update(['demo_id' => $second->id, 'demo_approval_hash' => $this->media()->demoBinding($product, $first)]);
        $this->assertArrayHasKey('demo', $service->blockers($product->refresh()));
        // A demo of another product is never this product's demo.
        $other = $this->bare(['slug' => 'other-kit']);
        $foreign = SoundKitMediaFixtures::demo($other, $this->actor);
        DB::table('sound_kit_products')->where('id', $product->id)->update(['demo_id' => $foreign->id]);
        $this->assertArrayHasKey('demo', $service->blockers($product->refresh()));
    }

    public function test_media_of_a_published_kit_cannot_be_replaced_until_it_is_unpublished(): void
    {
        $service = app(SoundKitProducts::class);
        $product = $this->product();
        $this->assertTrue($service->listed($product));
        $other = SoundKitMediaFixtures::cover($this->actor);
        $this->assertArrayHasKey('cover', $this->messages(fn () => $this->media()->chooseCover($product->refresh(), $product->revision, $other->id, 'x', $this->actor)));
        $before = SoundKitDemo::count();
        $this->assertArrayHasKey('demo', $this->messages(fn () => $this->media()->uploadDemo($product->refresh(), $product->revision, $this->upload(), $this->actor)));
        $this->assertSame($before, SoundKitDemo::count());
        $this->assertTrue($service->listed($product->refresh()));
        $product = $service->unpublish($product, $product->revision, $this->actor);
        $product = $this->media()->chooseCover($product, $product->revision, $other->id, 'A synthetic replacement', $this->actor);
        $this->assertArrayHasKey('cover', $service->blockers($product));
        $this->assertArrayHasKey('cover', $this->messages(fn () => $service->publish($product, $product->revision, $this->actor)));
    }

    public function test_the_public_pages_show_only_the_approved_cover_and_demo_and_only_while_the_pages_are_on(): void
    {
        $product = $this->product();
        $demo = $product->demo;
        $slug = $product->slug;
        $cover = $product->cover_image_id;
        $demoUrl = '/sound-kits/'.$slug.'/demo/'.$demo->public_id;
        $coverUrl = fn (): string => $this->get('/sound-kits/'.$slug, $this->inertia())->json('props.kit.cover.jpeg.0.url');

        // Flag off (the default): nothing is public, not the pages, the demo or the cover bytes.
        $variant = SiteImageVariant::where('site_image_id', $cover)->where('format', 'jpeg')->orderBy('width')->first();
        $imageUrl = '/site-images/'.$variant->sha256.'.jpg';
        $this->get($demoUrl)->assertNotFound();
        $this->get($imageUrl)->assertNotFound();

        $this->enable();
        $kit = $this->get('/sound-kits/'.$slug, $this->inertia())->assertOk()->json('props.kit');
        $this->assertSame($demoUrl, $kit['demo']['url']);
        $this->assertSame($imageUrl, $coverUrl());
        $this->assertSame('A synthetic test cover', $kit['cover']['alt']);
        $this->assertSame([400, 800, 1200], array_column($kit['cover']['jpeg'], 'width'));
        $this->assertSame(200, count($kit['demo']['waveform']));
        $index = $this->get('/sound-kits', $this->inertia())->assertOk()->json('props.kits');
        $this->assertSame($kit['cover'], $index[0]['cover']);
        $this->assertSame('https://audio.example.test'.end($kit['cover']['jpeg'])['url'], $this->get('/sound-kits/'.$slug, $this->inertia())->json('props.metadata.imageUrl'));

        $response = $this->get($demoUrl)->assertOk();
        $response->assertHeader('Content-Type', 'audio/mpeg');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame(file_get_contents($this->previewFile($demo)), $response->baseResponse->getFile()->getContent());
        $this->get($imageUrl)->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        // Only this kit's approved demo, by its own id.
        $this->get('/sound-kits/'.$slug.'/demo/'.Str::uuid())->assertNotFound();
        $this->get('/sound-kits/other-kit/demo/'.$demo->public_id)->assertNotFound();
        $this->get('/sound-kits/'.$slug.'/demo/'.$product->archiveRevision->public_id)->assertNotFound();
        $this->get('/sound-kits/'.$slug.'/demo/not-a-uuid')->assertNotFound();

        // A damaged stored preview is never sent.
        $path = $this->previewFile($demo);
        chmod($path, 0600);
        $original = file_get_contents($path);
        file_put_contents($path, $original.'x');
        $this->get($demoUrl)->assertNotFound();
        // The same length with one bit changed is caught by the hash, not the size.
        $flipped = $original;
        $flipped[intdiv(strlen($flipped), 2)] = chr(ord($flipped[intdiv(strlen($flipped), 2)]) ^ 1);
        file_put_contents($path, $flipped);
        $this->assertSame(strlen($original), strlen($flipped));
        $this->get($demoUrl)->assertNotFound();
        file_put_contents($path, $original);
        $this->get($demoUrl)->assertOk();

        // Unpublished or withdrawn: gone at once, bytes included.
        $service = app(SoundKitProducts::class);
        $down = $service->unpublish($product->refresh(), $product->revision, $this->actor);
        foreach ([$demoUrl, $imageUrl] as $url) {
            $this->get($url)->assertNotFound();
        }
        $up = $service->publish($down, $down->revision, $this->actor);
        $this->get($demoUrl)->assertOk();
        $service->revokeRights($up, $up->revision, $this->actor);
        foreach ([$demoUrl, $imageUrl] as $url) {
            $this->get($url)->assertNotFound();
        }
    }

    public function test_an_unapproved_or_replaced_asset_is_not_shown_or_served(): void
    {
        $this->enable();
        $product = $this->product();
        $slug = $product->slug;
        $oldDemo = $product->demo;
        $oldDemoUrl = '/sound-kits/'.$slug.'/demo/'.$oldDemo->public_id;
        $oldCover = SiteImageVariant::where('site_image_id', $product->cover_image_id)->where('format', 'jpeg')->orderBy('width')->first();
        $this->get($oldDemoUrl)->assertOk();
        $this->get('/site-images/'.$oldCover->sha256.'.jpg')->assertOk();

        $service = app(SoundKitProducts::class);
        $product = $service->unpublish($product, $product->revision, $this->actor);
        // A ready cover that no kit pinned is not served just because it is a ready cover.
        $loose = SoundKitMediaFixtures::cover($this->actor);
        $looseVariant = SiteImageVariant::where('site_image_id', $loose->id)->where('format', 'jpeg')->orderBy('width')->first();
        $this->get('/site-images/'.$looseVariant->sha256.'.jpg')->assertNotFound();
        // Replace both assets without approving them: the kit is no longer listed and the old addresses are gone.
        $product = $this->media()->chooseCover($product, $product->revision, $loose->id, 'A synthetic replacement', $this->actor);
        $new = SoundKitMediaFixtures::demo($product, $this->actor, MediaFixtures::wav(1.4, 660));
        $product->refresh();
        $this->assertSame($new->id, $product->demo_id);
        $this->assertArrayHasKey('cover', $this->messages(fn () => $service->publish($product, $product->revision, $this->actor)));
        $this->assertSame(404, $this->get('/sound-kits/'.$slug)->status());
        $this->get($oldDemoUrl)->assertNotFound();
        $this->get('/site-images/'.$looseVariant->sha256.'.jpg')->assertNotFound();
        // Approving the cover alone is not enough; approving both lists the kit with the new assets only.
        $product = $this->media()->approveCover($product, $product->revision, $this->actor);
        $this->assertArrayHasKey('demo', $this->messages(fn () => $service->publish($product, $product->revision, $this->actor)));
        $product = $this->media()->approveDemo($product, $product->revision, $this->actor);
        $service->publish($product, $product->revision, $this->actor);
        $this->get($oldDemoUrl)->assertNotFound();
        $this->get('/sound-kits/'.$slug.'/demo/'.$new->public_id)->assertOk();
        $this->get('/site-images/'.$looseVariant->sha256.'.jpg')->assertOk();
    }

    public function test_the_public_payload_carries_no_private_field_and_the_archive_is_unreachable_through_the_asset_addresses(): void
    {
        $this->enable();
        $product = $this->product();
        $revision = $product->archiveRevision;
        $demo = $product->demo;
        $pages = $this->get('/sound-kits/'.$product->slug, $this->inertia())->getContent().$this->get('/sound-kits', $this->inertia())->getContent();
        foreach ([$revision->archive_path, $revision->source_path, $revision->archive_sha256, $revision->manifest_sha256, $revision->public_id, $revision->source_sha256,
            $demo->source_path, $demo->preview_path, $demo->preview_sha256, $demo->source_sha256, $demo->original_name, $product->cover_manifest_sha256,
            'quarantine', 'sound-kit-demos', 'sound-kits\/revisions', 'Kick.wav', 'Bass.wav', 'evidence', 'tag_sha256'] as $secret) {
            $this->assertStringNotContainsString((string) $secret, $pages, (string) $secret);
        }
        $json = $this->get('/sound-kits/'.$product->slug, $this->inertia())->json('props.kit');
        $this->assertSame(['alt', 'width', 'height', 'jpeg', 'webp'], array_keys($json['cover']));
        $this->assertSame(['url', 'durationSeconds', 'waveform'], array_keys($json['demo']));
        $this->assertMatchesRegularExpression('~\A/sound-kits/[a-z0-9-]+/demo/[0-9a-f-]{36}\z~', $json['demo']['url']);
        // The archive's hashes and ids resolve to nothing through either asset address.
        $archive = Storage::disk('local')->get($revision->archive_path);
        foreach (['/site-images/'.$revision->archive_sha256.'.jpg', '/site-images/'.$revision->manifest_sha256.'.webp', '/site-images/'.$demo->source_sha256.'.jpg',
            '/sound-kits/'.$product->slug.'/demo/'.$revision->public_id] as $url) {
            $this->get($url)->assertNotFound();
        }
        $served = $this->get($json['demo']['url'])->assertOk()->baseResponse->getFile()->getContent();
        $this->assertNotSame($archive, $served);
        $this->assertNotSame(Storage::disk('local')->get($demo->source_path), $served, 'the tagged preview is not the private source');
        // The only kit routes are the two pages and the demo preview; no route serves a source, an archive or a download.
        $this->get('/sound-kits/'.$product->slug.'/archive')->assertNotFound();
        $this->get('/sound-kits/'.$product->slug.'/demo')->assertNotFound();
    }

    public function test_every_media_command_needs_current_mfa_enrolled_catalog_staff_and_records_one_audit_event(): void
    {
        $product = $this->bare();
        $cover = SoundKitMediaFixtures::cover($this->actor);
        $demo = SoundKitMediaFixtures::demo($product, $this->actor);
        $product->refresh();
        $customer = User::factory()->create();
        $noMfa = LicenseFixtures::admin();
        $commands = fn (User $who) => [
            fn () => $this->media()->chooseCover($product, $product->revision, $cover->id, 'x', $who),
            fn () => $this->media()->approveCover($product, $product->revision, $who),
            fn () => $this->media()->uploadDemo($product, $product->revision, $this->upload(), $who),
            fn () => $this->media()->approveDemo($product, $product->revision, $who),
            fn () => $this->media()->retryDemo($product, $product->revision, $who),
        ];
        $before = [SoundKitDemo::count(), DB::table('audit_events')->where('action', 'like', 'sound_kit_products.product.%')->count()];
        $panel = Filament::getPanel('admin');
        $wasRequired = $panel->isMultiFactorAuthenticationRequired();
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        try {
            foreach ([$customer, $noMfa] as $who) {
                foreach ($commands($who) as $index => $write) {
                    try {
                        $write();
                        $this->fail('A user without staff authority or MFA changed kit media (command '.$index.').');
                    } catch (AuthorizationException) {
                        $this->addToAssertionCount(1);
                    }
                }
            }
            $this->assertSame($before, [SoundKitDemo::count(), DB::table('audit_events')->where('action', 'like', 'sound_kit_products.product.%')->count()]);
            $this->assertSame([], array_filter(Storage::disk('local')->allFiles(), fn (string $file): bool => str_starts_with($file, 'sound-kit-demos/quarantine/') && ! str_contains($file, $demo->public_id) && $file !== $demo->source_path));
            $this->actingAs($noMfa);
            Livewire::test(ManageSoundKitProducts::class)->assertForbidden();

            // A stale revision is refused, and each accepted command is exactly one audit event attributed to the actor.
            $stale = $product->revision - 1;
            $this->assertArrayHasKey('title', $this->messages(fn () => $this->media()->chooseCover($product, $stale, $cover->id, 'x', $this->actor)));
            $this->assertArrayHasKey('title', $this->messages(fn () => $this->media()->approveDemo($product, $stale, $this->actor)));
            $this->assertArrayHasKey('title', $this->messages(fn () => $this->media()->uploadDemo($product, $stale, $this->upload(), $this->actor)));
            $this->assertSame(0, DB::table('sound_kit_demos')->count() - $before[0]);
            $actions = ['chooseCover' => 'cover_chosen', 'approveCover' => 'cover_approved', 'approveDemo' => 'demo_approved'];
            $product = $this->media()->chooseCover($product, $product->revision, $cover->id, 'x', $this->actor);
            $product = $this->media()->approveCover($product, $product->revision, $this->actor);
            $product = $this->media()->approveDemo($product, $product->revision, $this->actor);
            foreach ($actions as $action) {
                $events = DB::table('audit_events')->where('action', 'sound_kit_products.product.'.$action)->get();
                $this->assertCount(1, $events, $action);
                $this->assertSame($this->actor->id, (int) $events[0]->actor_id);
            }
            // An unchanged cover choice is not a new revision or an audit event.
            $revision = $product->revision;
            $this->assertSame($revision, $this->media()->chooseCover($product, $revision, $cover->id, 'x', $this->actor)->revision);
            $this->assertSame(1, DB::table('audit_events')->where('action', 'sound_kit_products.product.cover_chosen')->count());
        } finally {
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $wasRequired);
        }
    }

    public function test_the_filament_editor_drives_the_cover_and_demo_actions_through_the_service(): void
    {
        $this->actingAs($this->actor);
        $product = $this->bare();
        $cover = SoundKitMediaFixtures::cover($this->actor);
        $page = fn () => Livewire::test(ManageSoundKitProducts::class);
        $page()->callTableAction('chooseCover', $product, data: ['revision' => $product->revision, 'cover_image_id' => 999999, 'cover_alt' => 'x'])->assertHasTableActionErrors(['cover_image_id']);
        $page()->callTableAction('chooseCover', $product->refresh(), data: ['revision' => $product->revision, 'cover_image_id' => $cover->id, 'cover_alt' => 'A synthetic test cover'])->assertNotified('Cover art chosen');
        $page()->callTableAction('approveCover', $product->refresh())->assertNotified('Cover art approved');
        $page()->callTableAction('uploadDemo', $product->refresh(), data: ['revision' => $product->revision, 'upload' => $this->upload()])->assertNotified('Demo uploaded; it is being prepared');
        $demo = SoundKitDemo::sole();
        $this->assertSame($demo->id, $product->refresh()->demo_id);
        $page()->assertTableActionHidden('approveDemo', $product);
        app(SoundKitDemoProcessor::class)->handle($demo->id);
        $page()->callTableAction('approveDemo', $product->refresh())->assertNotified('Demo approved');
        $this->assertSame([], array_intersect_key(app(SoundKitProducts::class)->blockers($product->refresh()), ['cover' => 1, 'demo' => 1]));
        $page()->callTableAction('publish', $product->refresh())->assertNotified('Kit published');
        // A published kit offers neither action.
        $page()->assertTableActionHidden('uploadDemo', $product->refresh())->assertTableActionHidden('chooseCover', $product);
    }

    public function test_the_demo_table_is_retained_and_classified(): void
    {
        $this->assertArrayHasKey('sound_kit_demos', RetentionSchedule::NOT_CUSTOMER_DATA);
        $product = $this->bare();
        $demo = SoundKitMediaFixtures::demo($product, $this->actor);
        foreach ([fn () => DB::table('sound_kit_demos')->where('id', $demo->id)->delete(), fn () => DB::table('sound_kit_demos')->where('id', $demo->id)->update(['preview_sha256' => str_repeat('c', 64)])] as $write) {
            try {
                $write();
                $this->fail('A processed demo row changed behind the model.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('ready', $demo->refresh()->status);
    }

    /** A scanner that records what it was asked to scan and can flag the seller tag as infected. */
    private function spyScanner(bool $infectTag = false): TestOnlyMediaScanner
    {
        $spy = new class extends TestOnlyMediaScanner
        {
            /** @var list<string> */
            public array $seen = [];

            public bool $infectTag = false;

            public function scan(string $path): array
            {
                $this->seen[] = basename($path);
                if ($this->infectTag && basename($path) === 'tag.wav') {
                    return ['engine' => 'test-only', 'version' => 'synthetic-test-fixture-v1', 'status' => 'infected', 'sha256' => hash_file('sha256', $path)];
                }

                return parent::scan($path);
            }
        };
        $spy->infectTag = $infectTag;
        app()->instance(MalwareScanner::class, $spy);

        return $spy;
    }

    /** Wraps ffprobe so that every call is recorded; returns the file that gains a line per call. */
    private function countFfprobe(): string
    {
        $dir = sys_get_temp_dir().'/vasey-ffprobe-'.bin2hex(random_bytes(5));
        mkdir($dir, 0700);
        $log = $dir.'/calls';
        file_put_contents($dir.'/ffprobe', "#!/bin/sh\necho call >> '{$log}'\nexec '".config('media.ffprobe')."' \"$@\"\n");
        chmod($dir.'/ffprobe', 0700);
        config(['media.ffprobe' => $dir.'/ffprobe']);
        $this->beforeApplicationDestroyed(function () use ($dir): void {
            @unlink($dir.'/ffprobe');
            @unlink($dir.'/calls');
            @rmdir($dir);
        });

        return $log;
    }

    private function revisionFiles(): array
    {
        return array_values(array_filter(Storage::disk('local')->allFiles(), fn (string $file): bool => str_starts_with($file, 'sound-kit-demos/revisions/')));
    }

    public function test_a_demo_whose_quarantined_bytes_changed_after_upload_fails_and_promotes_nothing(): void
    {
        $product = $this->bare();
        $demo = $this->media()->uploadDemo($product, $product->revision, $this->upload(), $this->actor);
        // Same size and a valid, clean WAV, so only the stored hash can notice the swap.
        $swapped = MediaFixtures::wav(1.2, 331);
        $this->assertSame(strlen(MediaFixtures::wav(1.2, 330)), strlen($swapped));
        Storage::disk('local')->put($demo->source_path, $swapped);

        $failed = app(SoundKitDemoProcessor::class)->handle($demo->id);
        $this->assertSame(['failed', 'source_changed', null], [$failed->status, $failed->failure_code, $failed->preview_path]);
        $this->assertSame([], $this->revisionFiles(), 'nothing is promoted');
        $this->assertSame(0, DB::table('audit_events')->where('action', 'sound_kit_demo.processed')->count());
        $this->assertArrayHasKey('demo', $this->messages(fn () => $this->media()->approveDemo($product->refresh(), $product->revision, $this->actor)));
        $this->assertNull($product->refresh()->demo_approved_by);
        $this->assertArrayHasKey('demo', app(SoundKitProducts::class)->blockers($product));
    }

    public function test_a_demo_is_refused_when_the_seller_tag_is_the_synthetic_staging_placeholder_outside_admitted_environments(): void
    {
        $product = $this->bare();
        $demo = $this->media()->uploadDemo($product, $product->revision, $this->upload(), $this->actor);
        // An unmarked file under a staging name is refused by its path once the configured and running environments disagree
        // (the same set-up the track test uses); in `testing` the same tag would be admitted.
        $plain = MediaFixtures::wav(0.2, 1800);
        Storage::disk('local')->put('branding/Staging-copy.wav', $plain);
        config(['media.tag_path' => 'branding/Staging-copy.wav', 'media.tag_sha256' => hash('sha256', $plain), 'app.env' => 'production']);

        $waiting = app(SoundKitDemoProcessor::class)->handle($demo->id);
        $this->assertSame(['quarantined', 'staging_tag_refused', null], [$waiting->status, $waiting->failure_code, $waiting->preview_path]);
        $this->assertSame([], $this->revisionFiles());
        config(['app.env' => 'testing']);
        $this->assertArrayHasKey('demo', app(SoundKitProducts::class)->blockers($product->refresh()));
        $this->assertArrayHasKey('demo', $this->messages(fn () => $this->media()->approveDemo($product, $product->revision, $this->actor)));

        // The approved tag completes the same demo once it is installed.
        config(['media.tag_path' => 'approved-tags/test-only.wav', 'media.tag_sha256' => hash('sha256', $plain)]);
        $this->media()->retryDemo($product->refresh(), $product->revision, $this->actor);
        $this->assertSame('ready', app(SoundKitDemoProcessor::class)->handle($demo->id)->status);
    }

    public function test_a_demo_whose_seller_tag_is_flagged_by_the_scanner_fails_and_promotes_nothing(): void
    {
        $product = $this->bare();
        $demo = $this->media()->uploadDemo($product, $product->revision, $this->upload(), $this->actor);
        $spy = $this->spyScanner(infectTag: true);

        $failed = app(SoundKitDemoProcessor::class)->handle($demo->id);
        $this->assertSame(['failed', 'scan_not_clean', null], [$failed->status, $failed->failure_code, $failed->preview_path]);
        $this->assertSame(['source.bin', 'tag.wav'], $spy->seen, 'the source is scanned first, then the tag');
        $this->assertSame([], $this->revisionFiles());
        $this->assertSame(0, DB::table('audit_events')->where('action', 'sound_kit_demo.processed')->count());
        $this->assertArrayHasKey('demo', $this->messages(fn () => $this->media()->approveDemo($product->refresh(), $product->revision, $this->actor)));
    }

    public function test_no_media_tool_runs_on_an_upload_or_on_bytes_the_scanner_has_not_cleared(): void
    {
        $product = $this->bare();
        $calls = $this->countFfprobe();
        $demo = $this->media()->uploadDemo($product, $product->revision, $this->upload(), $this->actor);
        $this->assertFileDoesNotExist($calls, 'the upload is checked without ffprobe');

        // A source the scanner refuses is never probed or encoded.
        $this->scanner->reject = true;
        $failed = app(SoundKitDemoProcessor::class)->handle($demo->id);
        $this->assertSame('scan_not_clean', $failed->failure_code);
        $this->assertFileDoesNotExist($calls, 'ffprobe never saw the unscanned source');
        $this->scanner->reject = false;

        // A tag that is not cleared holds the source back too.
        $second = $this->media()->uploadDemo($product->refresh(), $product->revision, $this->upload(), $this->actor);
        $this->spyScanner(infectTag: true);
        $this->assertSame('scan_not_clean', app(SoundKitDemoProcessor::class)->handle($second->id)->failure_code);
        $this->assertFileDoesNotExist($calls, 'ffprobe waits for both scans');

        // Once both are clean the tools run.
        app()->instance(MalwareScanner::class, $this->scanner);
        $third = $this->media()->uploadDemo($product->refresh(), $product->revision, $this->upload(), $this->actor);
        $this->assertSame('ready', app(SoundKitDemoProcessor::class)->handle($third->id)->status);
        $this->assertFileExists($calls);
    }

    public function test_a_demo_longer_than_five_minutes_is_accepted_at_upload_and_fails_in_the_processor_with_its_own_code(): void
    {
        $product = $this->bare();
        $demo = $this->media()->uploadDemo($product, $product->revision, $this->upload('long.wav', $this->longWav(SoundKitProductMedia::DEMO_MAX_SECONDS + 1)), $this->actor);
        $this->assertSame('quarantined', $demo->status);

        $failed = app(SoundKitDemoProcessor::class)->handle($demo->id);
        $this->assertSame(['failed', 'demo_too_long', null], [$failed->status, $failed->failure_code, $failed->preview_path]);
        $this->assertSame([], $this->revisionFiles());
        $this->assertStringContainsString('longer than 5 minutes', app(SoundKitProducts::class)->blockers($product->refresh())['demo']);
        $this->assertArrayHasKey('demo', $this->messages(fn () => $this->media()->retryDemo($product, $product->revision, $this->actor)), 'a final failure is not retried');
    }

    public function test_a_wav_that_is_well_formed_but_not_audio_fails_in_the_processor_not_at_upload(): void
    {
        $product = $this->bare();
        // A RIFF/WAVE container with a correct size but no usable stream: the cheap upload checks pass, the probe refuses it.
        $bytes = "RIFF\x24\x00\x00\x00WAVE".str_repeat("\x00", 32);
        $demo = $this->media()->uploadDemo($product, $product->revision, $this->upload('empty.wav', $bytes), $this->actor);
        $failed = app(SoundKitDemoProcessor::class)->handle($demo->id);
        $this->assertSame('failed', $failed->status);
        $this->assertContains($failed->failure_code, ['invalid_audio', 'invalid_wav', 'unsupported_wav', 'processor_failed']);
        $this->assertSame([], $this->revisionFiles());
    }

    public function test_the_upload_accepts_exactly_the_mime_types_the_wav_validator_accepts(): void
    {
        $this->assertSame(['audio/x-wav', 'audio/wav', 'audio/vnd.wave'], SoundKitProductMedia::WAV_MIME_TYPES);
        $this->assertNotContains('audio/wave', SoundKitProductMedia::WAV_MIME_TYPES);
        $path = sys_get_temp_dir().'/vasey-mime-'.bin2hex(random_bytes(5)).'.wav';
        file_put_contents($path, MediaFixtures::wav(0.5, 440));
        try {
            foreach (SoundKitProductMedia::WAV_MIME_TYPES as $mime) {
                $this->assertGreaterThan(0, app(AudioDerivatives::class)->validateWav($path, $mime)['duration_seconds'], $mime);
            }
            foreach (['audio/wave', 'audio/mpeg', 'application/octet-stream'] as $mime) {
                try {
                    app(AudioDerivatives::class)->validateWav($path, $mime);
                    $this->fail('The validator accepted '.$mime);
                } catch (MediaFailure $failure) {
                    $this->assertSame('invalid_wav', $failure->failureCode);
                }
            }
        } finally {
            @unlink($path);
        }
    }

    public function test_missing_media_tools_leave_the_demo_waiting_with_an_honest_message_and_a_retry_completes_it(): void
    {
        $product = $this->bare();
        $real = config('media.ffprobe');
        config(['media.ffprobe' => '/nonexistent/ffprobe']);
        // The upload needs no tool, so it is accepted even while the tools are down.
        $demo = $this->media()->uploadDemo($product, $product->revision, $this->upload(), $this->actor);
        $waiting = app(SoundKitDemoProcessor::class)->handle($demo->id);
        $this->assertSame(['quarantined', 'tool_unavailable'], [$waiting->status, $waiting->failure_code]);
        $message = app(SoundKitProducts::class)->blockers($product->refresh())['demo'];
        $this->assertStringContainsString('media tools are unavailable', $message);
        $this->assertStringNotContainsString('complete WAV', $message);
        $this->assertTrue(SoundKitProductMedia::retryable($waiting));

        config(['media.ffprobe' => $real]);
        $this->media()->retryDemo($product, $product->revision, $this->actor);
        $this->assertSame('ready', app(SoundKitDemoProcessor::class)->handle($demo->id)->status);
    }

    public function test_a_demo_stuck_in_processing_can_be_retried_only_after_its_lease_expires(): void
    {
        Queue::fake();
        $product = $this->bare();
        $demo = $this->media()->uploadDemo($product, $product->revision, $this->upload(), $this->actor);
        $audits = fn () => DB::table('audit_events')->where('action', 'sound_kit_products.product.demo_retry_queued')->count();
        $stuck = fn (?\DateTimeInterface $until) => DB::table('sound_kit_demos')->where('id', $demo->id)->update(['status' => 'processing', 'claim_token' => 'dead-worker', 'claimed_until' => $until, 'attempts' => 1]);

        // A worker may still be finishing it: refused, with no audit event and nothing queued.
        $stuck(now()->addMinutes(10));
        $pushed = Queue::pushed(ProcessSoundKitDemo::class)->count();
        $this->assertFalse(SoundKitProductMedia::retryable($demo->refresh()));
        $this->assertArrayHasKey('demo', $this->messages(fn () => $this->media()->retryDemo($product->refresh(), $product->revision, $this->actor)));
        $this->assertSame([0, $pushed], [$audits(), Queue::pushed(ProcessSoundKitDemo::class)->count()]);

        // The lease has run out: the same command re-dispatches it, audited, and the processor reclaims and completes it.
        $stuck(now()->subMinute());
        $this->assertTrue(SoundKitProductMedia::retryable($demo->refresh()));
        $this->media()->retryDemo($product->refresh(), $product->revision, $this->actor);
        $this->assertSame([1, $pushed + 1], [$audits(), Queue::pushed(ProcessSoundKitDemo::class)->count()]);
        $event = DB::table('audit_events')->where('action', 'sound_kit_products.product.demo_retry_queued')->first();
        $this->assertSame($this->actor->id, (int) $event->actor_id);
        $context = json_decode($event->context, true);
        $this->assertSame(['processing', 'processing_interrupted'], [$context['from_status'], $context['failure_code']]);
        $this->assertSame('ready', app(SoundKitDemoProcessor::class)->handle($demo->id)->status);
    }

    public function test_retrying_a_demo_needs_the_current_product_revision_and_a_demo(): void
    {
        Queue::fake();
        $product = $this->bare();
        $this->assertArrayHasKey('demo', $this->messages(fn () => $this->media()->retryDemo($product, $product->revision, $this->actor)), 'no demo yet');
        $demo = $this->media()->uploadDemo($product, $product->revision, $this->upload(), $this->actor);
        DB::table('sound_kit_demos')->where('id', $demo->id)->update(['failure_code' => 'scanner_unavailable']);
        $product->refresh();
        $pushed = Queue::pushed(ProcessSoundKitDemo::class)->count();
        $this->assertArrayHasKey('title', $this->messages(fn () => $this->media()->retryDemo($product, $product->revision - 1, $this->actor)));
        $this->assertSame($pushed, Queue::pushed(ProcessSoundKitDemo::class)->count());
        $this->assertSame(0, DB::table('audit_events')->where('action', 'sound_kit_products.product.demo_retry_queued')->count());
        $this->media()->retryDemo($product, $product->revision, $this->actor);
        $this->assertSame($pushed + 1, Queue::pushed(ProcessSoundKitDemo::class)->count());
        $this->assertSame($product->revision, $product->refresh()->revision, 'a retry does not change the product');
    }

    private function damage(string $path): void
    {
        $this->assertFileExists($path);
        file_put_contents($path, str_repeat('x', max(1, (int) filesize($path))));
    }

    public function test_choosing_a_cover_whose_files_are_missing_or_damaged_is_refused(): void
    {
        $product = $this->bare();
        $image = SoundKitMediaFixtures::cover($this->actor);
        $variant = $image->variants()->firstOrFail();
        $this->damage(app(PrivateMediaFiles::class)->resolve($variant->storage_path));
        $this->assertArrayHasKey('cover', $this->messages(fn () => $this->media()->chooseCover($product, $product->revision, $image->id, 'A synthetic cover', $this->actor)));
        $this->assertNull($product->refresh()->cover_image_id);
        $this->assertSame(0, DB::table('audit_events')->where('action', 'sound_kit_products.product.cover_chosen')->count());
    }

    public function test_approving_a_cover_or_a_demo_whose_stored_file_no_longer_verifies_is_refused(): void
    {
        $product = $this->bare();
        $image = SoundKitMediaFixtures::cover($this->actor);
        $product = $this->media()->chooseCover($product, $product->revision, $image->id, 'A synthetic cover', $this->actor);
        $this->damage(app(PrivateMediaFiles::class)->resolve($image->variants()->firstOrFail()->storage_path));
        $this->assertArrayHasKey('cover', $this->messages(fn () => $this->media()->approveCover($product, $product->revision, $this->actor)));
        $this->assertNull($product->refresh()->cover_approved_by);

        $demo = SoundKitMediaFixtures::demo($product, $this->actor);
        $this->assertSame('ready', $demo->status);
        $this->damage($this->previewFile($demo));
        $this->assertArrayHasKey('demo', $this->messages(fn () => $this->media()->approveDemo($product->refresh(), $product->revision, $this->actor)));
        $this->assertNull($product->refresh()->demo_approved_by);
        $this->assertSame(0, DB::table('audit_events')->whereIn('action', ['sound_kit_products.product.cover_approved', 'sound_kit_products.product.demo_approved'])->count());
    }

    public function test_publishing_re_verifies_the_stored_preview_and_cover_files(): void
    {
        $service = app(SoundKitProducts::class);
        $product = SoundKitMediaFixtures::approved($this->bare(), $this->actor);
        $this->assertSame([], $service->blockers($product), 'approved and intact');
        $demo = SoundKitDemo::findOrFail($product->demo_id);
        $original = file_get_contents($this->previewFile($demo));
        $this->damage($this->previewFile($demo));
        // Public reads trust the immutable rows (no hashing per page view); publication reads the file.
        $this->assertArrayNotHasKey('demo', $service->blockers($product));
        $this->assertArrayHasKey('demo', $this->messages(fn () => $service->publish($product, $product->revision, $this->actor)));
        $this->assertFalse($product->refresh()->is_published);
        file_put_contents($this->previewFile($demo), $original);

        $this->damage(app(PrivateMediaFiles::class)->resolve(SiteImageVariant::where('site_image_id', $product->cover_image_id)->firstOrFail()->storage_path));
        $this->assertArrayHasKey('cover', $this->messages(fn () => $service->publish($product->refresh(), $product->revision, $this->actor)));
        $this->assertFalse($product->refresh()->is_published);
    }
}
