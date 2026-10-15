<?php

namespace Tests\Feature\ProductionFeatures;

use App\Domain\Customers\ProductionIdentity\Features\ProductionAccountFeatureIdentity;
use App\Http\AccountFeatures\AccountFeatureRequestGuard;
use App\Http\AccountFeatures\AccountFeatureResponse;
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
        $this->assertSame([200, ['initialized' => false]], $this->released($this->getJson('/customer/features/listening')));
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
        Route::middleware('web')->get('/customer/features/guard-probe', function (Request $request) use (&$error) {
            try {
                $identity = ProductionAccountFeatureIdentity::forRequest($request, 'listening_library');
                $guard = new AccountFeatureRequestGuard($request, $identity);
                $guard->requireCurrent();
            } catch (\Throwable $caught) {
                $error = $caught;
            }

            return new Response('probe-complete');
        });
        $this->get('/customer/features/guard-probe')->assertOk();
        $this->assertNull($error, $error === null ? '' : $error::class.' at '.$error->getFile().':'.$error->getLine());
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
        $test = $this->getJson('/customer/features/listening');
        $this->assertSame('', $early);
        $this->assertSame([503, ['code' => 'ACCOUNT_FEATURE_UNAVAILABLE']], $this->released($test));
        ob_start();
        $test->baseResponse->send(false)->sendContent();
        $this->assertSame('', ob_get_clean());
    }

    public function test_standalone_headers_then_late_scope_withdrawal_then_content_emit_nothing(): void
    {
        $this->signIn();
        $response = $this->getJson('/customer/features/listening')->baseResponse;
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
        $this->assertSame([503, ['code' => 'ACCOUNT_FEATURE_UNAVAILABLE']], $this->released($this->getJson('/customer/features/listening')));
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
            $this->getJson('/customer/features/listening')->assertStatus(503)->assertExactJson(['code' => 'ACCOUNT_FEATURE_UNAVAILABLE']);
            $this->assertSame(0, DB::table('production_account_feature_bindings')->count());
        }
    }
}
