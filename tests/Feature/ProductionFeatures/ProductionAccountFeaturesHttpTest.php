<?php

namespace Tests\Feature\ProductionFeatures;

use App\Domain\Customers\ProductionFeatures\Listening\ProductionListeningLibrary;
use App\Domain\Customers\ProductionFeatures\ProductionFeaturePreparation;
use App\Domain\Customers\ProductionIdentity\Features\ProductionAccountFeatureIdentity;
use App\Http\AccountFeatures\AccountFeatureController;
use App\Http\AccountFeatures\AccountFeatureRequestGuard;
use App\Http\AccountFeatures\AccountFeatureResponse;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ProductionFeatureFixtures;
use Tests\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class ProductionAccountFeaturesHttpTest extends TestCase
{
    use ProductionFeatureFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->featureSetup();
        $this->withoutVite();
        config(['production-account-features.http_mounted' => true]);
        // Execute the actual conditional registration after this isolated fixture config.
        Route::middleware('web')->group(base_path('routes/production-account-features.php'));
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
    }

    private function signIn(): void
    {
        $this->enrollThroughLocalSmtp('http-feature@example.test');
        $token = str_repeat('t', 40);
        $this->withSession(['_token' => $token]);
        $this->postJson('/customer/sign-in', ['email' => 'http-feature@example.test', 'password' => 'MailboxPassword123'], ['X-CSRF-TOKEN' => $token])->assertOk();
        $this->assertTrue(Auth::guard('customer')->check());
        $this->assertIsArray(session('_production_customer_identity'));
        $this->withHeader('X-CSRF-TOKEN', session()->token());
    }

    private function emptyPost(string $path, ?string $token = null): TestResponse
    {
        return $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token ?? session()->token()], '{}');
    }

    private function released(TestResponse $test): array
    {
        $response = $test->baseResponse;
        $this->assertInstanceOf(AccountFeatureResponse::class, $response);
        $this->assertSame('', $response->getContent(), 'Unsent content is provisional.');
        $this->assertFalse(headers_sent(), 'The isolated child permits genuine native send.');
        ob_start();
        try {
            $response->send(false);
            $body = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $this->assertSame($response->getContent(), $body);
        $this->assertFalse($response->headers->has('Location'));
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));

        return [$response->getStatusCode(), json_decode($body, true, 16, JSON_THROW_ON_ERROR)];
    }

    public function test_actual_session_csrf_read_initialize_change_export_and_preferences_unknown(): void
    {
        $this->signIn();
        $test = $this->get('/customer/features/listening', ['Accept' => 'application/json'])->assertStatus(200);
        $this->assertSame([200, ['initialized' => false]], $this->released($test));
        $this->assertSame(0, DB::table('production_account_feature_bindings')->count());
        [$status, $initial] = $this->released($this->emptyPost('/customer/features/listening/initialize'));
        $this->assertSame(200, $status);
        $this->assertSame(0, $initial['library']['version']);
        [$status, $changed] = $this->released($this->postJson('/customer/features/listening/change', ['action' => 'create-playlist', 'version' => 0, 'name' => 'SYNTHETIC HTTP private playlist']));
        $this->assertSame(200, $status);
        $this->assertSame(1, $changed['library']['version']);
        $this->postJson('/customer/features/listening/change', ['action' => 'clear-library', 'version' => 0])->assertStatus(409);
        [$status, $export] = $this->released($this->postJson('/customer/features/listening/export', ['version' => 1]));
        $this->assertSame(200, $status);
        $this->assertSame('SYNTHETIC HTTP private playlist', $export['export']['playlists'][0]['name']);
        $this->assertArrayNotHasKey('accountId', $export['export']);
        [$status, $pref] = $this->released($this->emptyPost('/customer/features/preferences/initialize'));
        $this->assertSame(200, $status);
        $this->assertSame('unknown', $pref['preferences']['purposes'][0]['status']);
        $this->assertFalse($pref['preferences']['purposes'][0]['canGrant']);
        $this->assertSame('not_requested', $pref['preferences']['purposes'][0]['suppression']['status']);
    }

    public function test_original_resolved_request_guard_is_positive_through_the_actual_web_stack(): void
    {
        $this->signIn();
        $error = null;
        Route::middleware('web')->withoutMiddleware(HandleInertiaRequests::class)->get('/customer/features/guard-probe', function (Request $request) use (&$error) {
            try {
                $response = new AccountFeatureResponse($request);
                $identity = ProductionAccountFeatureIdentity::forRequest($request, 'listening_library');
                $guard = new AccountFeatureRequestGuard($request, $identity);
                $guard->requireCurrent();
                $prepared = new ProductionFeaturePreparation(function () use ($guard, $response): void {
                    $guard->requireCurrent();
                    $response->requireUnchanged();
                }, 3 * 1024 * 1024);
                (new ProductionListeningLibrary)->read($identity, $prepared);
                $response->attach($prepared);

                return $response;
            } catch (\Throwable $caught) {
                $error = $caught;
            }

            return new Response('probe-complete');
        });
        $test = $this->get('/customer/features/guard-probe')->assertOk();
        $this->assertNull($error, $error === null ? '' : $error::class.' at '.$error->getFile().':'.$error->getLine());
        $this->assertSame([200, ['initialized' => false]], $this->released($test));
    }

    public function test_premature_handled_send_and_split_withdrawal_never_release_delayed_private_bytes(): void
    {
        $this->signIn();
        $early = null;
        Event::listen(RequestHandled::class, function (RequestHandled $event) use (&$early): void {
            if ($event->response instanceof AccountFeatureResponse) {
                ob_start();
                $event->response->send(false)->sendHeaders()->sendContent();
                $early = ob_get_clean();
            }
        });
        Event::listen(RequestHandled::class, function (RequestHandled $event): void {
            if ($event->response instanceof AccountFeatureResponse) {
                $event->request->session()->forget('_production_customer_identity');
            }
        });
        $test = $this->get('/customer/features/listening', ['Accept' => 'application/json']);
        $this->assertSame('', $early);
        $this->assertSame([503, ['code' => 'ACCOUNT_FEATURE_UNAVAILABLE']], $this->released($test));
        ob_start();
        $test->baseResponse->send(false)->sendContent();
        $this->assertSame('', ob_get_clean());
    }

    public function test_standalone_headers_then_late_scope_withdrawal_then_content_emit_nothing(): void
    {
        $this->signIn();
        $response = $this->get('/customer/features/listening', ['Accept' => 'application/json'])->baseResponse;
        $this->assertInstanceOf(AccountFeatureResponse::class, $response);
        ob_start();
        $response->sendHeaders();
        config(['production-account-features.http_mounted' => false]);
        $response->sendContent();
        $this->assertSame('', ob_get_clean());
        $this->assertSame('', (string) $response);
        ob_start();
        $response->send(false);
        $this->assertSame('{"code":"ACCOUNT_FEATURE_UNAVAILABLE"}', ob_get_clean());
        $this->assertSame(503, $response->getStatusCode());
    }

    #[DataProvider('lateChanges')]
    public function test_real_handled_late_changes_refuse_original_release(string $change): void
    {
        $this->signIn();
        Event::listen(RequestHandled::class, function (RequestHandled $event) use ($change): void {
            if (! $event->response instanceof AccountFeatureResponse) {
                return;
            }
            match ($change) {
                'mount' => config(['production-account-features.http_mounted' => false]),
                'body' => $event->response->setContent('SYNTHETIC callback body replacement'),
                'location' => $event->response->headers->set('Location', '/private-recipient'),
                'actor' => Auth::guard('customer')->logoutCurrentDevice(),
                'deadline' => usleep(10_100_000),
            };
        });
        $this->assertSame([503, ['code' => 'ACCOUNT_FEATURE_UNAVAILABLE']], $this->released($this->get('/customer/features/listening', ['Accept' => 'application/json'])));
        $this->assertFalse(DB::connection()->getRawPdo()->inTransaction());
    }

    public static function lateChanges(): array
    {
        return [['mount'], ['body'], ['location'], ['actor'], ['deadline']];
    }

    public function test_rotated_old_csrf_refuses_and_current_token_remains_valid_without_one_use_claim(): void
    {
        $this->signIn();
        $this->emptyPost('/customer/features/preferences/initialize', str_repeat('t', 40))->assertStatus(419);
        $this->assertSame(0, DB::table('production_account_feature_bindings')->count());
        $this->assertSame(200, $this->released($this->emptyPost('/customer/features/preferences/initialize'))[0]);
        $this->assertSame(200, $this->released($this->emptyPost('/customer/features/preferences/initialize'))[0]);
        $this->assertSame(1, DB::table('production_account_feature_bindings')->count());
    }

    #[DataProvider('invalidBodies')]
    public function test_bounded_duplicate_escaped_and_scalar_requests_refuse_before_feature_dml(string $path, string $body, int $status): void
    {
        $this->signIn();
        $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_CSRF_TOKEN' => session()->token()], $body)->assertStatus($status);
        $this->assertSame(0, DB::table('production_account_feature_bindings')->count());
        $this->assertFalse(session()->has('_old_input'));
    }

    public static function invalidBodies(): array
    {
        return [['/customer/features/listening/initialize', '[]', 422],
            ['/customer/features/listening/initialize', '{"x":1,"\\u0078":2}', 422],
            ['/customer/features/listening/initialize', str_repeat(' ', 4095).'{}', 413],
            ['/customer/features/listening/change', str_repeat(' ', 16383).'{}', 413],
            ['/customer/features/listening/export', '{"version":0,"version":1}', 422],
            ['/customer/features/preferences/initialize', '{"x":{"x":{"x":1}}}', 422]];
    }

    public function test_disabled_and_nonboolean_mount_refuse_stale_registered_routes(): void
    {
        foreach ([false, 'true', 1, null, new \ArrayObject] as $value) {
            config(['production-account-features.http_mounted' => $value]);
            $this->get('/customer/features/listening', ['Accept' => 'application/json'])->assertStatus(503)->assertExactJson(['code' => 'ACCOUNT_FEATURE_UNAVAILABLE']);
            $this->assertSame(0, DB::table('production_account_feature_bindings')->count());
        }
    }

    public function test_middleware_clone_never_receives_original_private_emission_authority(): void
    {
        $this->signIn();
        Route::getRoutes()->getByAction(AccountFeatureController::class.'@listeningRead')->middleware(FeatureCloneResponseMiddleware::class);
        $test = $this->get('/customer/features/listening', ['Accept' => 'application/json']);
        $test->assertStatus(503)->assertExactJson(['code' => 'ACCOUNT_FEATURE_UNAVAILABLE']);
        $this->assertFalse(DB::connection()->getRawPdo()->inTransaction());
    }

    public function test_unused_completed_response_is_not_retained_by_the_kernel_registry(): void
    {
        $this->signIn();
        $test = $this->get('/customer/features/listening', ['Accept' => 'application/json']);
        $this->assertInstanceOf(AccountFeatureResponse::class, $test->baseResponse);
        $weak = \WeakReference::create($test->baseResponse);
        unset($test);
        gc_collect_cycles();
        $this->assertTrue($weak->get() === null, 'Static completion metadata must not retain private response graphs.');
    }

    public function test_late_stringable_auth_slot_is_refused_without_its_callback(): void
    {
        $this->signIn();
        FeatureAuthSlotString::$calls = 0;
        Event::listen(RequestHandled::class, function (RequestHandled $event): void {
            if ($event->response instanceof AccountFeatureResponse) {
                $name = Auth::guard('customer')->getName();
                $event->request->session()->put($name, new FeatureAuthSlotString);
            }
        });
        $this->assertSame([503, ['code' => 'ACCOUNT_FEATURE_UNAVAILABLE']], $this->released($this->get('/customer/features/listening', ['Accept' => 'application/json'])));
        $this->assertSame(0, FeatureAuthSlotString::$calls);
    }

    public function test_exact_body_bounds_preserve_feature_change_and_original_identity_limit(): void
    {
        $this->signIn();
        $initial = str_repeat(' ', 4094).'{}';
        $send = fn ($path, $body) => $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_CSRF_TOKEN' => session()->token()], $body);
        $this->assertSame(200, $this->released($send('/customer/features/listening/initialize', $initial))[0]);
        $command = json_encode(['action' => 'create-playlist', 'version' => 0, 'name' => 'SYNTHETIC exact feature bound'], JSON_THROW_ON_ERROR);
        $bounded = str_repeat(' ', 16384 - strlen($command)).$command;
        [$status, $value] = $this->released($send('/customer/features/listening/change', $bounded));
        $this->assertSame(200, $status);
        $this->assertSame(1, $value['library']['version']);
        $send('/customer/sign-in', str_repeat(' ', 4095).'{}')->assertStatus(413);
        $this->assertSame(1, DB::table('production_listening_libraries')->value('version'));
    }

    public function test_controls_page_has_opaque_render_scope_and_no_private_projection(): void
    {
        $this->signIn();
        $first = $this->get('/customer/features', ['X-Inertia' => 'true', 'Accept' => 'text/html']);
        $first->assertOk()->assertJsonPath('component', 'ProductionCustomerFeatures');
        $scope = $first->json('props.scope');
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{48}\z/', $scope);
        $this->assertArrayNotHasKey('library', $first->json('props'));
        $this->assertArrayNotHasKey('preferences', $first->json('props'));
        $first->assertDontSee('http-feature@example.test', false);
        $second = $this->get('/customer/features', ['X-Inertia' => 'true', 'Accept' => 'text/html']);
        $this->assertNotSame($scope, $second->json('props.scope'));
        $this->assertSame(0, DB::table('production_account_feature_bindings')->count());
    }

    public function test_private_debug_framework_exits_never_redirect_or_flash_inputs(): void
    {
        config(['app.debug' => true]);
        Route::middleware('web')->get('/customer/features/throw-probe', function (): void {
            throw new \RuntimeException('SYNTHETIC private exception text');
        });
        $failed = $this->get('/customer/features/throw-probe', ['Accept' => 'text/html']);
        $failed->assertStatus(503)->assertExactJson(['code' => 'ACCOUNT_FEATURE_UNAVAILABLE'])->assertDontSee('SYNTHETIC private exception text', false);
        $this->assertFalse($failed->headers->has('Location'));
        $this->assertFalse(session()->has('_old_input'));
        $this->call('PUT', '/customer/features/listening')->assertStatus(405)->assertExactJson(['code' => 'ACCOUNT_FEATURE_UNAVAILABLE']);
        $this->get('/customer/features/missing?name=synthetic-private')->assertStatus(422)->assertExactJson(['code' => 'ACCOUNT_FEATURE_UNAVAILABLE']);
    }

    public function test_reentry_cannot_complete_original_epoch_before_later_withdrawal(): void
    {
        $this->signIn();
        $nested = null;
        Event::listen(RequestHandled::class, function (RequestHandled $event) use (&$nested): void {
            if ($event->response instanceof AccountFeatureResponse) {
                $nested = app(Kernel::class)->handle($event->request);
                $event->request->session()->forget('_production_customer_identity');
            }
        });
        $test = $this->get('/customer/features/listening', ['Accept' => 'application/json']);
        $this->assertSame(503, $nested->getStatusCode());
        $this->assertSame([503, ['code' => 'ACCOUNT_FEATURE_UNAVAILABLE']], $this->released($test));
    }
}

final class FeatureCloneResponseMiddleware
{
    public function handle(Request $request, \Closure $next): Response
    {
        return clone $next($request);
    }
}

final class FeatureAuthSlotString implements \Stringable
{
    public static int $calls = 0;

    public function __toString(): string
    {
        self::$calls++;

        return '1';
    }
}
