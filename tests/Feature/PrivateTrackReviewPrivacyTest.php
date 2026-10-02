<?php

namespace Tests\Feature;

use App\Domain\Catalog\SaveTrackMetadata;
use App\Filament\Resources\TrackResource\Pages\ManageTracks;
use App\Http\Middleware\InquiryPrivacy;
use App\Http\Middleware\PrivateTrackReviewPrivacy;
use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Livewire\Component;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

class PrivateTrackReviewPrivacyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_real_track_page_authentication_and_authority_failures_are_private(): void
    {
        $this->privateResponse($this->get('/admin/tracks')->assertRedirect('/admin/login'));
        $unverified = LicenseFixtures::admin();
        $unverified->forceFill(['email_verified_at' => null])->save();
        foreach ([User::factory()->create(), $unverified] as $actor) {
            $response = $this->actingAs($actor)->get('/admin/tracks')->assertForbidden();
            $this->privateResponse($response);
            $this->assertSame('Private track review is unavailable.', $response->getContent());
        }
        $this->privateResponse($this->actingAs(LicenseFixtures::admin())->get('/admin/tracks')->assertOk());
    }

    public function test_missing_and_malformed_operator_media_paths_have_generic_private_errors(): void
    {
        $this->actingAs(LicenseFixtures::admin());
        foreach (['/admin/media/999999999/preview', '/admin/media/not-an-asset/preview', '/admin/media/999999999/preview/unexpected'] as $path) {
            $response = $this->get($path)->assertNotFound();
            $this->privateResponse($response);
            $this->assertSame('Private track review is unavailable.', $response->getContent());
        }
    }

    public function test_middleware_errors_under_debug_disclose_only_generic_body_and_exception_class(): void
    {
        config(['app.debug' => true]);
        Log::spy();
        Route::get('/admin/tracks/synthetic-private-failure', fn () => 'not reached')
            ->middleware(PrivateTrackReviewSyntheticFailure::class);
        $response = $this->get('/admin/tracks/synthetic-private-failure')->assertStatus(503);
        $this->privateResponse($response);
        $this->assertSame('Private track review is unavailable.', $response->getContent());
        Log::shouldHaveReceived('error')->once()->with('Private track review failed.', ['exception_class' => RuntimeException::class]);
    }

    public function test_server_marker_protects_asynchronous_errors_and_preserves_rate_limit_headers(): void
    {
        config(['app.debug' => true]);
        Route::get('/synthetic-track-review-update', function () {
            request()->attributes->set('_track_private_review', true);
            abort(429, 'SYNTHETIC PRIVATE TRACK DETAILS', ['Retry-After' => '19', 'X-RateLimit-Limit' => '240', 'X-RateLimit-Remaining' => '0']);
        });
        $response = $this->get('/synthetic-track-review-update')->assertStatus(429)
            ->assertHeader('Retry-After', '19')->assertHeader('X-RateLimit-Limit', '240')->assertHeader('X-RateLimit-Remaining', '0');
        $this->privateResponse($response);
        $this->assertSame('Private track review is unavailable.', $response->getContent());
    }

    public function test_reporting_failure_cannot_replace_the_generic_private_response(): void
    {
        config(['app.debug' => true]);
        Log::shouldReceive('error')->once()->with('Private track review failed.', ['exception_class' => RuntimeException::class])
            ->andThrow(new RuntimeException('SYNTHETIC PRIVATE LOGGER DETAILS'));
        Route::get('/admin/tracks/synthetic-reporting-failure', fn () => throw new RuntimeException('SYNTHETIC PRIVATE TRACK DETAILS'));
        $response = $this->get('/admin/tracks/synthetic-reporting-failure')->assertStatus(503);
        $this->privateResponse($response);
        $this->assertSame('Private track review is unavailable.', $response->getContent());
    }

    public function test_retained_track_component_marks_request_before_revoked_authority_failure(): void
    {
        $actor = LicenseFixtures::admin();
        $this->actingAs($actor);
        $component = Livewire::test(ManageTracks::class);
        User::findOrFail($actor->id)->forceFill(['is_admin' => false])->save();
        $component->call('$refresh')->assertForbidden();
        $this->assertTrue(request()->attributes->get('_track_private_review'));
    }

    public function test_unrelated_paths_and_client_supplied_marker_do_not_enter_private_review_scope(): void
    {
        Route::get('/synthetic-unrelated-review', fn () => response('Public synthetic response', 200, ['Cache-Control' => 'public, max-age=60']));
        $response = $this->get('/synthetic-unrelated-review?_track_private_review=true', ['_track_private_review' => 'true'])->assertOk();
        $response->assertHeader('Cache-Control', 'max-age=60, public')->assertHeaderMissing('X-Robots-Tag');
        $this->assertSame('Public synthetic response', $response->getContent());
    }

    public function test_unresolved_or_unrelated_routes_do_not_interpret_raw_inquiry_method_overrides(): void
    {
        // The kernel enables parameter overrides, but the inquiry envelope rejects them first.
        Request::enableHttpMethodParameterOverride();
        $body = '{"\\u005fmethod":"INVALID_PRIVATE_METHOD"}';
        $routes = [
            null,
            new \Illuminate\Routing\Route('POST', '/contact/inquiries', fn () => 'unrelated'),
            (new \Illuminate\Routing\Route('POST', '/contact/inquiries', fn () => 'wrong action'))->name('synthetic.livewire.update'),
            (new \Illuminate\Routing\Route('POST', '/contact/inquiries', [HandleRequests::class, 'handleUpdate']))->name('synthetic.unrelated.update'),
        ];
        foreach ($routes as $route) {
            $request = Request::createFromBase(\Symfony\Component\HttpFoundation\Request::create(
                '/contact/inquiries', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body,
            ));
            $request->setRouteResolver(fn () => $route);
            $this->assertSame('INVALID_PRIVATE_METHOD', $request->request->get('_method'));
            $this->assertFalse(PrivateTrackReviewPrivacy::matches($request));
            $response = (new PrivateTrackReviewPrivacy)->handle($request,
                fn (Request $request) => (new InquiryPrivacy)->handle($request, function () {
                    $this->fail('The invalid raw envelope must be rejected before inquiry admission.');
                }));
            $this->assertSame(422, $response->getStatusCode());
            $this->assertSame('INQUIRY_VALIDATION_FAILED', json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)['code']);
            $this->assertSame($body, $request->attributes->get('_inquiry_body'));
            $this->privateResponse(TestResponse::fromBaseResponse($response));
            $this->assertNull($request->attributes->get('_track_private_review'));
        }
        foreach (['GET', 'PUT', 'OPTIONS'] as $method) {
            $request = Request::create('/livewire/update', $method);
            $route = (new \Illuminate\Routing\Route('POST', '/livewire/update', [HandleRequests::class, 'handleUpdate']))
                ->name('synthetic.livewire.update');
            $request->setRouteResolver(fn () => $route);
            $this->assertFalse(PrivateTrackReviewPrivacy::matches($request));
        }
    }

    public function test_signed_tracks_snapshot_remains_private_when_real_csrf_rejects_before_component_boot(): void
    {
        config(['app.debug' => true]);
        $actor = LicenseFixtures::admin();
        $this->actingAs($actor);
        $track = app(SaveTrackMetadata::class)->handle(null, ['title' => 'Private early failure fixture',
            'slug' => 'private-early-failure-fixture'], $actor);
        $sentinel = 'SYNTHETIC-PRIVATE-REVIEW-BEFORE-CSRF';
        $changes = array_replace(array_fill_keys(['artist', 'bpm', 'musical_key', 'genre', 'mood'], ['mode' => 'keep']),
            ['genre' => ['mode' => 'set', 'value' => $sentinel]]);
        $page = Livewire::test(ManageTracks::class)->callTableBulkAction('editMetadata', [$track], data: ['changes' => $changes]);
        $snapshot = json_encode($page->snapshot, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString($sentinel, $snapshot, 'Use actual private data in a genuinely signed mounted review.');
        Livewire::component('synthetic-public-privacy-component', PrivateTrackReviewPublicComponent::class);
        $otherSnapshot = json_encode(Livewire::test('synthetic-public-privacy-component')->snapshot, JSON_THROW_ON_ERROR);
        $before = [DB::table('tracks')->get()->toJson(), DB::table('audit_events')->get()->toJson()];
        $this->enableRealCsrf();
        $hydrations = 0;
        $stop = \Livewire\on('snapshot-verified', function () use (&$hydrations): void {
            $hydrations++;
        });
        try {
            foreach ([null, 'invalid-synthetic-token', 'expired-synthetic-session-token'] as $submittedToken) {
                if ($submittedToken === 'expired-synthetic-session-token') {
                    auth()->logout();
                }
                // A signed Tracks component protects the entire mixed response even when it is not first.
                $payload = ['components' => [
                    ['snapshot' => $otherSnapshot, 'updates' => [], 'calls' => []],
                    ['snapshot' => $snapshot, 'updates' => [],
                        'calls' => [['method' => 'applyReviewedMetadataChanges', 'params' => [], 'path' => '']]],
                ]];
                if ($submittedToken !== null) {
                    $payload['_token'] = $submittedToken;
                }
                $response = $this->withSession(['_token' => 'expected-synthetic-session-token'])
                    ->post(app('livewire')->getUpdateUri(), $payload, ['X-Livewire' => 'true', 'Accept' => 'text/html'])
                    ->assertStatus(419)->assertContent('Private track review is unavailable.')->assertDontSee($sentinel);
                $this->privateResponse($response);
            }
        } finally {
            $stop();
        }
        $this->assertSame(0, $hydrations, 'The real CSRF rejection must happen before Livewire component verification/boot.');
        $this->assertSame($before, [DB::table('tracks')->get()->toJson(), DB::table('audit_events')->get()->toJson()]);
    }

    public function test_unsigned_names_and_signed_unrelated_components_or_routes_cannot_opt_into_early_privacy(): void
    {
        config(['app.debug' => true]);
        $this->actingAs(LicenseFixtures::admin());
        $trackSnapshot = Livewire::test(ManageTracks::class)->snapshot;
        Livewire::component('synthetic-public-privacy-component', PrivateTrackReviewPublicComponent::class);
        $otherSnapshot = Livewire::test('synthetic-public-privacy-component')->snapshot;
        $forged = $otherSnapshot;
        $forged['memo']['name'] = $trackSnapshot['memo']['name']; // Keep the genuine unrelated signature.
        $invalid = $trackSnapshot;
        $invalid['checksum'] = str_repeat('0', 64);
        $this->enableRealCsrf();
        Route::post('/synthetic-unrelated-livewire-endpoint', fn () => response('Unrelated public response'))
            ->middleware('web')->name('synthetic.livewire.update');
        $hydrations = 0;
        $stop = \Livewire\on('snapshot-verified', function () use (&$hydrations): void {
            $hydrations++;
        });
        try {
            foreach ([
                [app('livewire')->getUpdateUri(), $forged],
                [app('livewire')->getUpdateUri(), $invalid],
                [app('livewire')->getUpdateUri(), $otherSnapshot],
                ['/synthetic-unrelated-livewire-endpoint', $trackSnapshot],
            ] as [$uri, $snapshot]) {
                $response = $this->withSession(['_token' => 'expected-synthetic-session-token'])
                    ->post($uri, ['_track_private_review' => true, 'components' => [[
                        'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'updates' => [], 'calls' => [],
                    ]]], ['X-Livewire' => 'true', 'Accept' => 'text/html'])->assertStatus(419)->assertHeaderMissing('X-Robots-Tag');
                $this->assertNotSame('Private track review is unavailable.', $response->getContent());
            }
        } finally {
            $stop();
        }
        $this->assertSame(0, $hydrations);
    }

    public function test_maximum_signed_track_review_remains_private_during_a_pre_boot_debug_failure(): void
    {
        config(['app.debug' => true]);
        $actor = LicenseFixtures::admin();
        $this->actingAs($actor);
        $sentinel = 'SYNTHETIC-PRIVATE-MAXIMUM-REVIEW';
        $tracks = [];
        for ($index = 1; $index <= 25; $index++) {
            $tracks[] = app(SaveTrackMetadata::class)->handle(null, [
                'title' => str_pad("Private early failure {$index}", 255, 't'),
                'slug' => "private-maximum-early-failure-{$index}",
                'artist' => str_repeat('a', 255), 'genre' => str_repeat('g', 255), 'mood' => str_repeat('m', 255),
            ], $actor);
        }
        $changes = array_replace(array_fill_keys(['artist', 'bpm', 'musical_key', 'genre', 'mood'], ['mode' => 'keep']),
            ['genre' => ['mode' => 'set', 'value' => $sentinel]]);
        $page = Livewire::test(ManageTracks::class)->set('tableRecordsPerPage', 25)
            ->callTableBulkAction('editMetadata', $tracks, data: ['changes' => $changes]);
        $this->assertCount(25, $page->get('bulkMetadataReview')['tracks']);
        $snapshot = json_encode($page->snapshot, JSON_THROW_ON_ERROR);
        $this->assertGreaterThan(32768, strlen($snapshot), 'Exercise the legitimate maximum review with large private metadata.');
        $this->assertStringContainsString($sentinel, $snapshot);
        $before = [DB::table('tracks')->get()->toJson(), DB::table('audit_events')->get()->toJson()];
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }

            protected function tokensMatch($request): bool
            {
                throw new RuntimeException('SYNTHETIC-PRIVATE-MAXIMUM-REVIEW');
            }
        });
        Log::spy();
        $hydrations = 0;
        $stop = \Livewire\on('snapshot-verified', function () use (&$hydrations): void {
            $hydrations++;
        });
        try {
            $response = $this->withSession(['_token' => 'expected-synthetic-session-token'])
                ->post(app('livewire')->getUpdateUri(), ['components' => [[
                    'snapshot' => $snapshot, 'updates' => [],
                    'calls' => [['method' => 'applyReviewedMetadataChanges', 'params' => [], 'path' => '']],
                ]]], ['X-Livewire' => 'true', 'Accept' => 'text/html'])
                ->assertStatus(503)->assertContent('Private track review is unavailable.')->assertDontSee($sentinel);
            $this->privateResponse($response);
        } finally {
            $stop();
        }
        Log::shouldHaveReceived('error')->once()->with('Private track review failed.', ['exception_class' => RuntimeException::class]);
        $this->assertSame(0, $hydrations);
        $this->assertSame($before, [DB::table('tracks')->get()->toJson(), DB::table('audit_events')->get()->toJson()]);
    }

    private function enableRealCsrf(): void
    {
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
    }

    private function privateResponse(TestResponse $response): void
    {
        $response->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertContains('Cookie', $response->baseResponse->getVary());
    }
}

class PrivateTrackReviewSyntheticFailure
{
    public function handle(Request $request, Closure $next): Response
    {
        throw new RuntimeException('SYNTHETIC PRIVATE /srv/private/media/source.wav SQL_BINDINGS');
    }
}

class PrivateTrackReviewPublicComponent extends Component
{
    public function render(): string
    {
        return '<div>Unrelated synthetic component</div>';
    }
}
