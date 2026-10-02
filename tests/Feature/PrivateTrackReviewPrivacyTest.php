<?php

namespace Tests\Feature;

use App\Filament\Resources\TrackResource\Pages\ManageTracks;
use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
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
