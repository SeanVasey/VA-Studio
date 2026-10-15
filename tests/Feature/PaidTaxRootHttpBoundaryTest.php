<?php

namespace Tests\Feature;

use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutTransport;
use App\Domain\Commerce\ProductionTaxCheckout\UnboundTaxCheckoutTransport;
use App\Domain\Customers\ProductionIdentity\ProductionCustomerSessions;
use App\Domain\Grants\PaidTax\PaidTaxException;
use App\Domain\Grants\PaidTax\PaidTaxPolicy;
use App\Http\Tax\PaidTaxRequestContext;
use App\Http\Tax\PaidTaxResponseOwner;
use App\Http\Tax\PaidTaxStreamResponse;
use App\Http\Tax\TaxHttpMount;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\ServerBag;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\PaidTaxRootHttpFixture;
use Tests\TestCase;

final class PaidTaxRootHttpBoundaryTest extends TestCase
{
    use PaidTaxRootHttpFixture;

    public static function mountCases(): array
    {
        return [['shipped', false, 'testing'], ['string', 'true', 'testing'], ['integer', 1, 'testing'],
            ['null', null, 'testing'], ['array', [], 'testing'], ['production', true, 'production']];
    }

    #[DataProvider('mountCases')]
    public function test_cold_refusal_never_registers_or_resolves_private_services(string $case, mixed $flag, string $environment): void
    {
        $this->taxGrantMount = $this->taxCheckoutMount = $flag;
        $this->taxRootEnvironment = $environment;
        $this->refreshApplication();
        $this->assertFalse((require base_path('config/paid-tax-grants.php'))['http_mounted']);
        $this->assertFalse((require base_path('config/production-tax-checkout.php'))['http_mounted']);
        $this->assertFalse(Route::has('paid-tax-grants.finalize'), $case);
        $this->assertFalse(Route::has('production.tax-checkout.previews'), $case);
        $this->assertFalse($this->app->bound(TaxCheckoutTransport::class));
        $this->app->afterResolving(PaidTaxPolicy::class, fn () => $this->fail('Off path resolved policy.'));
        $this->app->afterResolving(ProductionCustomerSessions::class, fn () => $this->fail('Off path resolved identity.'));
        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });
        foreach (['/paid-tax-grants/origins/'.str_repeat('a', 36).'/authorizations/x/redeem', '/production/tax-checkout/previews'] as $path) {
            $response = $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'text/html'], 'PRIVATE-BODY');
            $response->assertNotFound()->assertHeader('Content-Type', 'application/json')->assertDontSee('PRIVATE-BODY');
            $this->privateHeaders($response);
        }
        $this->assertFalse($this->app->resolved(ProductionCustomerSessions::class));
        $this->assertFalse($this->app->resolved(PaidTaxPolicy::class));
        $this->assertSame([], $queries, 'The refused request performed no query.');
    }

    #[DataProvider('mountCases')]
    public function test_runtime_withdrawal_refuses_previously_registered_routes(string $case, mixed $flag, string $environment): void
    {
        $this->assertTrue(Route::has('paid-tax-grants.finalize'));
        $this->assertTrue(Route::has('production.tax-checkout.previews'));
        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });
        config(['paid-tax-grants.http_mounted' => $flag, 'production-tax-checkout.http_mounted' => $flag]);
        $this->app->instance('env', $environment);
        foreach (['/paid-tax-grants/orders/11111111-1111-4111-8111-111111111111/finalize', '/production/tax-checkout/previews'] as $path) {
            $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertNotFound();
        }
        $this->assertFalse($this->app->resolved(ProductionCustomerSessions::class), $case);
        $this->assertSame([], $queries, 'The refused request performed no query.');
    }

    public function test_mount_alone_registers_only_unbound_transport_and_keeps_commerce_policies_off(): void
    {
        $this->assertInstanceOf(UnboundTaxCheckoutTransport::class, $this->app->make(TaxCheckoutTransport::class));
        $this->assertFalse(config('production-tax-checkout.enabled'));
        $this->assertFalse(config('paid-tax-grants.rehearsal_enabled'));
        $this->postJson('/production/tax-checkout/previews', [])->assertStatus(503);
        $this->assertFalse($this->app->resolved(ProductionCustomerSessions::class));
    }

    public static function intakeCases(): array
    {
        return [['duplicate', '{"x":1,"x":2}', [], 422], ['escaped', '{"x":1,"\\u0078":2}', [], 422],
            ['nested_duplicate', '{"x":{"a":1,"a":2}}', [], 422], ['array', '[]', [], 422], ['malformed', '{', [], 422],
            ['oversize', null, [], 413], ['range', '{}', ['HTTP_RANGE' => 'bytes=0-1'], 422],
            ['encoding', '{}', ['HTTP_CONTENT_ENCODING' => 'gzip'], 415], ['origin', '{}', ['HTTP_ORIGIN' => 'https://foreign.invalid'], 403],
            ['type', '{}', ['CONTENT_TYPE' => 'application/problem+json'], 415], ['length', '{}', ['CONTENT_LENGTH' => '65537'], 413]];
    }

    #[DataProvider('intakeCases')]
    public function test_raw_intake_refuses_before_customer_identity(string $case, ?string $body, array $server, int $status): void
    {
        $response = $this->call('POST', '/paid-tax-grants/orders/11111111-1111-4111-8111-111111111111/finalize', [], [], [],
            [...['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'text/html'], ...$server], $body ?? str_repeat('x', 65537));
        $response->assertStatus($status)->assertHeader('Content-Type', 'application/json');
        $this->privateHeaders($response);
        $this->assertFalse($this->app->resolved(ProductionCustomerSessions::class), $case);
    }

    public static function outsideFailures(): array
    {
        return array_merge(...array_map(fn ($path) => [[\RuntimeException::class, 500, $path], [LockTimeoutException::class, 503, $path], [ValidationException::class, 422, $path], [TokenMismatchException::class, 419, $path]], ['/paid-tax-grants/origins/example', '/production/tax-checkout/previews']));
    }

    #[DataProvider('outsideFailures')]
    public function test_root_errors_before_privacy_force_json_without_redirect_flash_or_exception_text(string $class, int $status, string $path): void
    {
        $middleware = new class($class)
        {
            public function __construct(private string $class) {}

            public function handle(Request $request, Closure $next): never
            {
                if ($this->class === ValidationException::class) {
                    throw ValidationException::withMessages(['token' => 'PRIVATE-VALIDATION']);
                }
                throw new $this->class('PRIVATE-EXCEPTION');
            }
        };
        $this->app->instance('tax-before-root-fixture', $middleware);
        $this->app->make(Kernel::class)->prependMiddleware('tax-before-root-fixture');
        config(['app.debug' => true]);
        if (! in_array($class, [ValidationException::class, TokenMismatchException::class], true)) {
            Log::shouldReceive('error')->once()->with('Private Tax request failed.', ['exception_class' => $class]);
        }
        $response = $this->call('POST', $path, ['token' => 'PRIVATE-TOKEN'], [], [], ['HTTP_ACCEPT' => 'text/html', 'CONTENT_TYPE' => 'application/json'], '{"token":"PRIVATE-TOKEN"}');
        $response->assertStatus($status)->assertHeader('Content-Type', 'application/json')->assertDontSee('PRIVATE-TOKEN')->assertDontSee('PRIVATE-EXCEPTION')->assertDontSee('PRIVATE-VALIDATION');
        $response->assertHeaderMissing('Location');
        $this->privateHeaders($response);
        $this->assertNull(session()->get('_old_input.token'));
    }

    public function test_original_request_context_cannot_be_replaced_renewed_or_claimed_for_another_request(): void
    {
        config(['paid-tax-grants.downloads_enabled' => true]);
        $request = Request::create('/paid-tax-grants/origins/a/authorizations/b/redeem');
        $request->server->set('REQUEST_TIME_FLOAT', microtime(true));
        PaidTaxRequestContext::capture($request);
        $request->server->set('REQUEST_TIME_FLOAT', microtime(true) + 100);
        $request->attributes->set('_paid_tax_budget', new \stdClass);
        $budget = PaidTaxRequestContext::claim($request);
        $this->assertLessThan(microtime(true) + 1, (float) $budget->receivedAt->at->format('U.u'));
        foreach ([fn () => PaidTaxRequestContext::capture($request), fn () => PaidTaxRequestContext::claim($request),
            fn () => PaidTaxRequestContext::claim(Request::create('/paid-tax-grants'))] as $operation) {
            try {
                $operation();
                $this->fail('Context replacement was admitted.');
            } catch (PaidTaxException $error) {
                $this->assertSame(503, $error->status);
            }
        }
        $reference = \WeakReference::create($request);
        unset($request, $operation);
        gc_collect_cycles();
        // Closures in the temporary foreach array are gone; neither context value retains its key.
        $this->assertNull($reference->get());
    }

    public function test_encoding_failure_and_actual_never_started_response_disposal_close_original_owner(): void
    {
        $owner = new PaidTaxResponseOwner;
        $owner->read();
        try {
            PaidTaxStreamResponse::projection(['bad' => "\xff"], $owner);
            $this->fail('Invalid encoding was accepted.');
        } catch (\JsonException) {
            $this->assertOwnerClosed($owner);
        }
        $owner = new PaidTaxResponseOwner;
        $owner->read();
        $response = PaidTaxStreamResponse::projection(['private' => 'DISPOSED'], $owner);
        $ref = \WeakReference::create($response);
        unset($response);
        $this->assertNull($ref->get(), 'The callback does not keep its response alive.');
        $this->assertOwnerClosed($owner);
    }

    public static function malformedMetadata(): array
    {
        return [['instances'], ['bindings'], ['env_binding'], ['config_parent']];
    }

    #[DataProvider('malformedMetadata')]
    public function test_mount_admission_rejects_nonliteral_metadata_without_invoking_callbacks(string $case): void
    {
        $calls = 0;
        $callback = new class($calls) implements \ArrayAccess
        {
            public function __construct(private int &$calls) {}

            public function offsetExists(mixed $offset): bool
            {
                $this->calls++;

                return true;
            }

            public function offsetGet(mixed $offset): mixed
            {
                $this->calls++;

                return true;
            }

            public function offsetSet(mixed $offset, mixed $value): void
            {
                $this->calls++;
            }

            public function offsetUnset(mixed $offset): void
            {
                $this->calls++;
            }
        };
        $instanceProperty = new \ReflectionProperty(Container::class, 'instances');
        $bindingProperty = new \ReflectionProperty(Container::class, 'bindings');
        $originalInstances = $instances = $instanceProperty->getValue($this->app);
        $originalBindings = $bindings = $bindingProperty->getValue($this->app);
        $configuration = $instances['config'];
        $itemsProperty = new \ReflectionProperty($configuration, 'items');
        $items = $itemsProperty->getValue($configuration);
        try {
            if ($case === 'instances') {
                $instanceProperty->setValue($this->app, $callback);
            } elseif ($case === 'config_parent') {
                $changed = $items;
                $changed['paid-tax-grants'] = $callback;
                $itemsProperty->setValue($configuration, $changed);
            } else {
                unset($instances['env']);
                $instanceProperty->setValue($this->app, $instances);
                $bindings['env'] = ['concrete' => function () use (&$calls) {
                    $calls++;

                    return 'testing';
                }, 'shared' => false];
                $bindingProperty->setValue($this->app, $case === 'bindings' ? $callback : $bindings);
            }
            $admitted = TaxHttpMount::admitted('paid-tax-grants.http_mounted');
        } finally {
            $instanceProperty->setValue($this->app, $originalInstances);
            $bindingProperty->setValue($this->app, $originalBindings);
            $itemsProperty->setValue($configuration, $items);
        }
        $this->assertFalse($admitted);
        $this->assertSame(0, $calls, 'Raw descriptor refusal cannot invoke ArrayAccess or an env factory.');
    }

    public function test_standard_literal_environment_binding_mounts_without_resolving_its_factory(): void
    {
        $this->app['env'] = 'testing';
        $this->assertTrue(TaxHttpMount::admitted('paid-tax-grants.http_mounted'));
        $this->postJson('/paid-tax-grants/orders/11111111-1111-4111-8111-111111111111/finalize', [])->assertStatus(422);
    }

    public function test_request_start_descriptor_refuses_callback_bag_and_keeps_a_failed_capture_tombstone(): void
    {
        config(['paid-tax-grants.downloads_enabled' => true]);
        $request = Request::create('/paid-tax-grants/origins/a/authorizations/b/redeem');
        $bag = new class(['REQUEST_TIME_FLOAT' => microtime(true)]) extends ServerBag
        {
            public int $calls = 0;

            public function get(string $key, mixed $default = null): mixed
            {
                $this->calls++;

                return parent::get($key, $default);
            }
        };
        $request->server = $bag;
        try {
            PaidTaxRequestContext::capture($request);
            $this->fail('Callback-producing server bag admitted.');
        } catch (PaidTaxException $error) {
            $this->assertSame(503, $error->status);
        }
        $this->assertSame(0, $bag->calls);
        $request->server = new ServerBag(['REQUEST_TIME_FLOAT' => microtime(true)]);
        try {
            PaidTaxRequestContext::capture($request);
            $this->fail('Failed enrollment was renewed.');
        } catch (PaidTaxException $error) {
            $this->assertSame(503, $error->status);
        }
    }

    public function test_producer_root_reads_the_actual_raw_stream_once_and_preserves_its_duplicate_parser(): void
    {
        config(['production-tax-checkout.enabled' => true]);
        $request = new class extends Request
        {
            public int $rawResourceReads = 0;

            public int $cachedBodyReads = 0;

            public function getContent(bool $asResource = false)
            {
                if ($asResource) {
                    $this->rawResourceReads++;
                } else {
                    $this->cachedBodyReads++;
                }

                return parent::getContent($asResource);
            }
        };
        $request->initialize([], [], [], [], [], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/production/tax-checkout/previews',
            'SERVER_NAME' => 'localhost', 'SERVER_PORT' => 80, 'CONTENT_TYPE' => 'application/json'], '{"candidateId":1,"\\u0063andidateId":2}');
        $response = $this->app->make(Kernel::class)->handle($request);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(1, $request->rawResourceReads, 'Only root intake opens the raw input resource.');
        $this->assertSame(1, $request->cachedBodyReads, 'Framework input normalization reads cached bytes without reopening the raw stream.');
        $this->assertFalse($this->app->resolved(ProductionCustomerSessions::class));
    }

    public function test_root_exception_headers_are_bounded_allowlisted_and_never_copy_private_metadata(): void
    {
        $middleware = new class
        {
            public function handle(Request $request, Closure $next): never
            {
                throw new HttpException(405, 'PRIVATE-EXCEPTION', headers: ['Allow' => 'GET, POST', 'Retry-After' => '12',
                    'X-RateLimit-Limit' => '99999999999', 'X-Private-Marker' => 'PRIVATE-HEADER']);
            }
        };
        $this->app->instance('tax-header-failure', $middleware);
        $this->app->make(Kernel::class)->prependMiddleware('tax-header-failure');
        $response = $this->get('/paid-tax-grants/origins/example', ['Accept' => 'text/html'])->assertStatus(405)
            ->assertHeader('Allow', 'GET, POST')->assertHeader('Retry-After', '12')->assertHeaderMissing('X-Private-Marker')
            ->assertHeaderMissing('X-RateLimit-Limit')->assertDontSee('PRIVATE-EXCEPTION')->assertHeaderMissing('Location');
        $this->privateHeaders($response);
    }

    public function test_get_query_body_wrong_method_and_native_aliases_refuse_before_identity(): void
    {
        $path = '/paid-tax-grants/origins/11111111-1111-4111-8111-111111111111';
        $this->get($path.'?token=PRIVATE-TOKEN')->assertStatus(422)->assertDontSee('PRIVATE-TOKEN');
        $this->call('GET', $path, [], [], [], [], '{}')->assertStatus(422);
        $this->call('PUT', $path, [], [], [], [], '{}')->assertStatus(405);
        config(['paid-tax-grants.downloads_enabled' => true]);
        foreach (['token='.str_repeat('t', 43).'&_token='.str_repeat('c', 40).'&%74oken='.str_repeat('t', 43),
            'token='.str_repeat('t', 43).'&_token='.str_repeat('c', 40).'&extra=PRIVATE-TOKEN'] as $body) {
            $this->call('POST', $path.'/authorizations/22222222-2222-4222-8222-222222222222/redeem', [], [], [],
                ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'REQUEST_TIME_FLOAT' => microtime(true)], $body)->assertStatus(422);
        }
        $this->assertFalse($this->app->resolved(ProductionCustomerSessions::class));
    }

    public function test_original_start_capture_reads_backing_storage_without_a_request_property_getter_hook(): void
    {
        config(['paid-tax-grants.downloads_enabled' => true]);
        $request = new class extends Request
        {
            public int $getterCalls = 0;

            public bool $armed = false;

            public ServerBag $server {
                get {
                    $this->getterCalls++;
                    if ($this->armed) {
                        throw new \RuntimeException('A server getter cannot precede the original clock.');
                    }

                    return $this->server;
                }
                set {
                    $this->server = $value;
                }
            }
        };
        $request->getterCalls = 0;
        $request->armed = true;
        $start = microtime(true);
        (new \ReflectionProperty(\Symfony\Component\HttpFoundation\Request::class, 'server'))->setRawValue($request, new ServerBag(['REQUEST_TIME_FLOAT' => $start]));
        PaidTaxRequestContext::capture($request);
        $budget = PaidTaxRequestContext::claim($request);
        $this->assertSame(0, $request->getterCalls);
        $this->assertEqualsWithDelta($start, (float) $budget->receivedAt->at->format('U.u'), 0.001);
        $budget->initialDeadline()->proveCurrent();
    }

    public function test_real_producer_csrf_equality_and_rotation_return_private_419_without_input_flash(): void
    {
        config(['production-tax-checkout.enabled' => true]);
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        $this->withSession(['_token' => str_repeat('c', 40)]);
        $path = '/production/tax-checkout/previews';
        $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'text/html',
            'HTTP_X_CSRF_TOKEN' => str_repeat('c', 40)], '{}')->assertForbidden();
        $response = $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'text/html',
            'HTTP_X_CSRF_TOKEN' => 'PRIVATE-INVALID'], '{}')->assertStatus(419)->assertHeaderMissing('Location')->assertDontSee('PRIVATE-INVALID');
        $this->privateHeaders($response);
        session()->regenerateToken();
        session()->save();
        $fresh = session()->token();
        $this->assertNotSame(str_repeat('c', 40), $fresh);
        $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => str_repeat('c', 40)], '{}')->assertStatus(419);
        $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $fresh], '{}')->assertForbidden();
        $this->assertNull(session()->get('_old_input.token'));
    }

    private function assertOwnerClosed(PaidTaxResponseOwner $owner): void
    {
        try {
            $owner->read();
            $this->fail('Disposed owner reopened.');
        } catch (PaidTaxException) {
            $this->assertTrue(true);
        }
    }

    private function privateHeaders($response): void
    {
        $response->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertContains('Cookie', $response->baseResponse->getVary());
    }
}
