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
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
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
        return array_merge(...array_map(fn ($path) => [[\RuntimeException::class, 500, $path], [LockTimeoutException::class, 503, $path], [ValidationException::class, 422, $path]], ['/paid-tax-grants/origins/example', '/production/tax-checkout/previews']));
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
        if ($class !== ValidationException::class) {
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
