<?php

namespace Tests\Feature;

use App\Application\SiteBuilder\IngestSiteImage;
use App\Domain\Media\MalwareScanner;
use App\Domain\Media\MediaFailure;
use App\Domain\SiteBuilder\Models\SiteImage;
use App\Domain\SiteBuilder\SiteImageProblem;
use App\Domain\SiteBuilder\SiteImageProcessor;
use App\Filament\Resources\SiteImageResource;
use App\Filament\Resources\SiteImageResource\Pages\ListSiteImages;
use App\Jobs\ProcessSiteImage;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use RuntimeException;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\Support\SiteImageFixtures as F;
use Tests\Support\TestOnlyMediaScanner;
use Tests\TestCase;

class SiteImageHttpTest extends TestCase
{
    use RefreshDatabase;

    private TestOnlyMediaScanner $scanner;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        $this->scanner = MediaFixtures::configure();
        $this->actor = LicenseFixtures::admin();
        config(['app.debug' => false]);
    }

    private function ingest(string $bytes, string $name = 'studio.jpg', string $slot = 'studio'): SiteImage
    {
        $path = tempnam(sys_get_temp_dir(), 'site-image-');
        file_put_contents($path, $bytes);
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return app(IngestSiteImage::class)->handle($slot, new UploadedFile($path, $name, null, null, true), 'Synthetic studio photograph', true, $this->actor);
    }

    private function ready(): SiteImage
    {
        return app(SiteImageProcessor::class)->handle($this->ingest(F::jpeg(1440, 630))->id);
    }

    private function uploadData(array $overrides = []): array
    {
        return $overrides + [
            'slot' => 'studio', 'upload' => UploadedFile::fake()->createWithContent('studio.jpg', F::jpeg(1440, 630)),
            'credit' => 'Synthetic studio photograph', 'rights_confirmed' => true,
        ];
    }

    private function assertPrivate(TestResponse $response): TestResponse
    {
        return $response->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_only_catalog_administrators_can_open_the_library_and_nothing_can_be_edited_or_deleted(): void
    {
        $url = SiteImageResource::getUrl();
        $this->get($url)->assertRedirect(route('filament.admin.auth.login'));
        $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
        $this->actingAs($this->actor)->get($url)->assertOk()->assertSee('Site images')->assertSee('Upload site image');

        $image = $this->ingest(F::jpeg(1440, 630));
        foreach (['create', 'update', 'delete', 'deleteAny', 'forceDelete', 'restore', 'replicate', 'reorder', 'view'] as $ability) {
            $this->assertFalse(SiteImageResource::can($ability, $image), $ability);
        }
        $this->assertSame(['index'], array_keys(SiteImageResource::getPages()));
        $this->get($url.'/create')->assertNotFound();
        $this->get($url.'/'.$image->id.'/edit')->assertNotFound();
    }

    public function test_required_mfa_applies_to_the_page_and_to_an_already_open_upload(): void
    {
        $this->actingAs($this->actor);
        $component = Livewire::test(ListSiteImages::class)->mountAction('uploadSiteImage')->fillForm($this->uploadData());
        $panel = Filament::getPanel('admin');
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        $component->callMountedAction()->assertForbidden();
        Route::get('/admin/synthetic-site-image-mfa', fn () => 'Synthetic setup destination')->name('filament.admin.auth.multi-factor-authentication.set-up-required');
        Route::getRoutes()->refreshNameLookups();
        Route::getRoutes()->match(Request::create(SiteImageResource::getUrl()))->middleware(Dashboard::getRouteMiddleware($panel));
        $this->get(SiteImageResource::getUrl())->assertRedirect('/admin/synthetic-site-image-mfa');
        $this->assertSame(0, SiteImage::count());
    }

    public function test_upload_stores_a_quarantined_image_and_reports_problems_beside_their_fields(): void
    {
        $this->actingAs($this->actor);
        Livewire::test(ListSiteImages::class)
            ->callAction('uploadSiteImage', data: $this->uploadData(['slot' => 'hero_desktop']))
            ->assertHasActionErrors(['upload']);
        Livewire::test(ListSiteImages::class)
            ->callAction('uploadSiteImage', data: $this->uploadData(['credit' => 'Photo <script>']))
            ->assertHasActionErrors(['credit']);
        Livewire::test(ListSiteImages::class)
            ->callAction('uploadSiteImage', data: $this->uploadData(['upload' => UploadedFile::fake()->createWithContent('alpha.png', F::png(1440, 630, 'rgba'))]))
            ->assertHasActionErrors(['upload']);
        Livewire::test(ListSiteImages::class)
            ->callAction('uploadSiteImage', data: $this->uploadData(['rights_confirmed' => false]))
            ->assertHasActionErrors(['rights_confirmed']);
        $this->assertSame(0, SiteImage::count());
        Queue::assertNothingPushed();

        // Fields outside the form cannot set state.
        Livewire::test(ListSiteImages::class)
            ->callAction('uploadSiteImage', data: $this->uploadData(['status' => 'ready', 'source_path' => 'site-images/revisions/x/1.jpg']))
            ->assertHasNoActionErrors()->assertNotified('Image uploaded');
        $image = SiteImage::sole();
        $this->assertSame(['studio', 'quarantined', 'studio.jpg'], [$image->slot, $image->status, $image->original_name]);
        $this->assertStringStartsWith('site-images/quarantine/', $image->source_path);
        Queue::assertPushedOn('media', ProcessSiteImage::class);
    }

    public function test_the_upload_field_neither_describes_nor_accepts_a_stored_path_placed_in_its_state(): void
    {
        $this->actingAs($this->actor);
        $stored = $this->ingest(F::jpeg(1440, 630));
        $component = Livewire::test(ListSiteImages::class)->mountAction('uploadSiteImage')
            ->fillForm(['slot' => 'studio', 'credit' => 'Synthetic studio photograph', 'rights_confirmed' => true])
            ->set('mountedActions.0.data.upload', ['forged' => $stored->source_path]);

        // The browser asks the field to describe its files; a path this form did not issue gets no name, size, type or link.
        $this->assertSame(['forged' => null], $component->instance()->callSchemaComponentMethod('mountedActionSchema0.upload', 'getUploadedFiles'));
        $component->callMountedAction()->assertHasActionErrors(['upload' => 'The image (JPEG or PNG) field contains a file path that is not permitted.']);
        $this->assertSame([$stored->id], SiteImage::pluck('id')->all());

        // Filament checks only string paths, so a number naming a root-level object must get nothing either.
        Storage::disk('local')->put('123', 'root-level object');
        $component->set('mountedActions.0.data.upload', ['numeric' => 123]);
        $this->assertSame(['numeric' => null], $component->instance()->callSchemaComponentMethod('mountedActionSchema0.upload', 'getUploadedFiles'));
    }

    public function test_waiting_images_show_a_queue_hint_without_diagnosing_an_outage_or_dispatching_more_work(): void
    {
        $image = $this->ingest(F::jpeg(1440, 630));
        Queue::assertPushedOn('media', ProcessSiteImage::class, fn (ProcessSiteImage $job): bool => $job->imageId === $image->id);
        Queue::assertPushed(ProcessSiteImage::class, 1);
        $before = $image->fresh()->getAttributes();
        $audits = AuditEvent::count();
        $this->actingAs($this->actor);

        $component = Livewire::test(ListSiteImages::class)
            ->assertTableColumnFormattedStateSet('status', 'Waiting', $image)
            ->assertTableColumnFormattedStateSet('failure_code', SiteImageProblem::MESSAGES['processing_waiting'], $image)
            ->assertSee(SiteImageProblem::MESSAGES['processing_waiting'])
            ->assertTableActionVisible('retry', $image)
            ->assertDontSee($image->source_path)->assertDontSee($image->source_sha256);
        // A longer wait is still not evidence that a healthy queue failed. Polling only reads the same retained state.
        $this->travel(30)->minutes();
        $component->call('$refresh')->assertTableColumnFormattedStateSet('status', 'Waiting', $image)
            ->assertTableColumnFormattedStateSet('failure_code', SiteImageProblem::MESSAGES['processing_waiting'], $image);

        $this->assertSame($before, $image->fresh()->getAttributes());
        $this->assertSame($audits, AuditEvent::count());
        $this->assertDatabaseCount('site_image_variants', 0);
        Queue::assertPushed(ProcessSiteImage::class, 1);
    }

    public function test_an_upload_dispatch_failure_keeps_a_visible_hint_until_staff_explicitly_retry(): void
    {
        Exceptions::fake();
        $queue = new class(app()) extends QueueFake
        {
            public int $pushes = 0;

            public function push($job, $data = '', $queue = null)
            {
                $this->pushes++;
                $send = fn () => throw new RuntimeException('Synthetic queue outage.');

                return is_object($job) && ($job->afterCommit ?? false) ? app('db.transactions')->addCallback($send) : $send();
            }
        };
        Queue::swap($queue);
        $this->actingAs($this->actor);
        Livewire::test(ListSiteImages::class)->callAction('uploadSiteImage', data: $this->uploadData())
            ->assertHasNoActionErrors()->assertNotified('Image uploaded')
            ->assertSee(SiteImageProblem::MESSAGES['processing_waiting']);
        $image = SiteImage::sole();
        $this->assertSame(['quarantined', 0, null], [$image->status, $image->attempts, $image->failure_code]);
        $this->assertTrue(Storage::disk('local')->exists($image->source_path));
        Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'Synthetic queue outage.');

        $component = Livewire::test(ListSiteImages::class)->call('$refresh')
            ->assertTableColumnFormattedStateSet('failure_code', SiteImageProblem::MESSAGES['processing_waiting'], $image)
            ->assertTableActionVisible('retry', $image);
        $this->assertSame(1, $queue->pushes);
        $this->assertSame(0, AuditEvent::where('action', 'site.image.retry_requested')->count());

        Queue::fake();
        $component->callTableAction('retry', $image)->assertNotified('Processing queued');
        Queue::assertPushedOn('media', ProcessSiteImage::class, fn (ProcessSiteImage $job): bool => $job->imageId === $image->id);
        Queue::assertPushed(ProcessSiteImage::class, 1);
        $this->assertSame(1, AuditEvent::where('action', 'site.image.retry_requested')->where('subject_id', $image->id)->count());
        $this->assertSame(['quarantined', 0, null], [$image->fresh()->status, $image->fresh()->attempts, $image->fresh()->failure_code]);
        $this->assertDatabaseCount('site_image_variants', 0);
    }

    public function test_retry_is_offered_only_for_waiting_images_and_the_problem_is_explained(): void
    {
        $ready = $this->ready();
        $this->scanner->reject = true;
        $failed = app(SiteImageProcessor::class)->handle($this->ingest(F::jpeg(1440, 630))->id);
        app()->instance(MalwareScanner::class, new class extends MalwareScanner
        {
            public function scan(string $path): array
            {
                throw new MediaFailure('scanner_unavailable', 'Synthetic outage.');
            }
        });
        $waiting = app(SiteImageProcessor::class)->handle($this->ingest(F::jpeg(1440, 630))->id);
        $this->actingAs($this->actor);

        Livewire::test(ListSiteImages::class)
            ->assertSee(SiteImageProblem::MESSAGES['scanner_unavailable'])->assertSee(SiteImageProblem::MESSAGES['scan_not_clean'])
            ->assertDontSee(SiteImageProblem::MESSAGES['processing_waiting'])->assertTableColumnStateSet('failure_code', null, $ready)
            ->assertSee('Waiting')->assertSee('Failed')->assertSee('Ready')
            ->assertTableActionHidden('retry', $ready)->assertTableActionHidden('retry', $failed)
            ->assertTableActionVisible('retry', $waiting)
            ->callTableAction('retry', $waiting)->assertNotified('Processing queued');
        $this->assertSame(1, AuditEvent::query()->where('action', 'site.image.retry_requested')->where('subject_id', $waiting->id)->count());
        Queue::assertPushed(ProcessSiteImage::class, fn (ProcessSiteImage $job): bool => $job->imageId === $waiting->id);
    }

    public function test_an_expired_claim_is_shown_as_interrupted_with_its_attempts_and_can_be_retried(): void
    {
        $live = $this->ingest(F::jpeg(1440, 630));
        $live->forceFill(['status' => 'processing', 'claim_token' => (string) Str::uuid(), 'claimed_until' => now()->addMinutes(10), 'attempts' => 1])->save();
        // A second worker took over once and then stopped too.
        $interrupted = $this->ingest(F::jpeg(1440, 630));
        $interrupted->forceFill(['status' => 'processing', 'claim_token' => (string) Str::uuid(), 'claimed_until' => now()->subMinutes(20), 'attempts' => 1])->save();
        $interrupted->forceFill(['claim_token' => (string) Str::uuid(), 'claimed_until' => now()->subMinute(), 'attempts' => 2])->save();
        $this->actingAs($this->actor);

        Livewire::test(ListSiteImages::class)
            ->assertTableColumnFormattedStateSet('status', 'Processing', $live)->assertTableColumnStateSet('failure_code', null, $live)
            ->assertDontSee(SiteImageProblem::MESSAGES['processing_waiting'])
            ->assertTableColumnFormattedStateSet('status', 'Interrupted', $interrupted)
            ->assertTableColumnFormattedStateSet('failure_code', SiteImageProblem::MESSAGES['processing_interrupted'], $interrupted)
            ->assertTableColumnStateSet('attempts', 1, $live)->assertTableColumnStateSet('attempts', 2, $interrupted)
            ->assertSee('Interrupted')->assertSee(SiteImageProblem::MESSAGES['processing_interrupted'])->assertSeeHtml('fi-ta-cell-attempts')
            ->assertTableActionHidden('retry', $live)->assertTableActionVisible('retry', $interrupted)
            ->callTableAction('retry', $interrupted)->assertNotified('Processing queued');
        $this->assertEquals(['status' => 'processing', 'failure_code' => null],
            AuditEvent::query()->where('action', 'site.image.retry_requested')->where('subject_id', $interrupted->id)->sole()->context);
    }

    public function test_the_list_escapes_upload_names_and_shows_thumbnails_through_the_private_preview(): void
    {
        $image = app(SiteImageProcessor::class)->handle($this->ingest(F::jpeg(1440, 630), '<img src=x onerror=alert(1)>.jpg')->id);
        $thumbnail = $image->thumbnail();
        $this->assertSame(['jpeg', 720], [$thumbnail->format, $thumbnail->width]);
        $this->actingAs($this->actor);

        Livewire::test(ListSiteImages::class)
            ->assertSeeHtml('&lt;img src=x onerror=alert(1)&gt;.jpg')->assertDontSeeHtml('<img src=x onerror')
            ->assertSeeHtml(e(route('filament.admin.site-images.preview', $thumbnail->id)))
            ->assertSeeHtml('alt="Studio image #'.$image->id.'"');
    }

    public function test_preview_serves_ready_variants_privately_and_only_to_staff(): void
    {
        $image = $this->ready();
        $jpeg = $image->variants()->where('format', 'jpeg')->where('width', 720)->sole();
        $webp = $image->variants()->where('format', 'webp')->where('width', 720)->sole();
        $url = fn (int|string $id): string => '/admin/site-images/'.$id.'/preview';

        $this->assertPrivate($this->get($url($jpeg->id)))->assertRedirect(route('filament.admin.auth.login'));
        $this->assertPrivate($this->actingAs(User::factory()->create())->get($url($jpeg->id)))->assertForbidden();

        $this->actingAs($this->actor);
        $response = $this->assertPrivate($this->get($url($jpeg->id)))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame(Storage::disk('local')->get($jpeg->storage_path), $response->getContent());
        $this->assertPrivate($this->get($url($webp->id)))->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->assertPrivate($this->get($url(999999)))->assertNotFound();
        $this->assertPrivate($this->get($url('abc')))->assertNotFound();

        // Changed bytes of the same size, a missing file and a symlink all fail closed; the restored file is served again.
        $original = Storage::disk('local')->get($jpeg->storage_path);
        $path = Storage::disk('local')->path($jpeg->storage_path);
        // Promotion seals variants read-only; unseal the file before changing it, as the media tests do.
        chmod($path, 0600);
        file_put_contents($path, strrev($original));
        $this->assertPrivate($this->get($url($jpeg->id)))->assertNotFound();
        unlink($path);
        $this->assertPrivate($this->get($url($jpeg->id)))->assertNotFound();
        $elsewhere = Storage::disk('local')->path('site-images/elsewhere.jpg');
        file_put_contents($elsewhere, $original);
        symlink($elsewhere, $path);
        $this->assertPrivate($this->get($url($jpeg->id)))->assertNotFound();
        unlink($path);
        file_put_contents($path, $original);
        $this->assertPrivate($this->get($url($jpeg->id)))->assertOk();
    }
}
