<?php

namespace Tests\Feature;

use App\Domain\Customers\ProductionCustomerAccess;
use App\Http\Middleware\ProductionCheckoutPrivacy;
use App\Http\Responses\ProductionCheckoutResponse;
use App\Providers\ProductionCheckoutServiceProvider;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/** No substitute identity classes: only default-off and unauthenticated transport boundaries here. */
class ProductionCheckoutHttpBoundaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('h', 32))]);
        $this->app->register(ProductionCheckoutServiceProvider::class);
        $this->app[Kernel::class]->prependMiddleware(ProductionCheckoutPrivacy::class);
        Route::middleware('web')->group(base_path('routes/production-checkout.php'));
    }

    public function test_default_off_never_queries_private_or_payment_data_and_never_resolves_identity(): void
    {
        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });
        $this->postJson('/production/checkout/reviews', ['candidateId' => 1])->assertStatus(503)
            ->assertExactJson(['code' => 'PRODUCTION_CHECKOUT_UNAVAILABLE',
                'message' => 'Checkout could not be confirmed. Retry the same request or check the saved order.'])
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame([], $queries);
        $this->assertFalse($this->app->resolved(ProductionCustomerAccess::class));
    }

    public static function transportCases(): array
    {
        return [['query', 422], ['range', 422], ['cross_site', 403], ['origin', 403], ['form', 415], ['compression', 415],
            ['oversize', 413], ['method', 405], ['override', 405], ['malformed', 422], ['array', 422], ['duplicate', 422], ['nested_duplicate', 422], ['guest', 403]];
    }

    #[DataProvider('transportCases')]
    public function test_private_transport_refusals_are_bounded_and_sanitized(string $case, int $status): void
    {
        config(['production_checkout.http_enabled' => true]);
        $path = '/production/checkout/reviews';
        $method = 'POST';
        $body = '{}';
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        match ($case) {
            'query' => $path .= '?payment_status=paid',
            'range' => $headers['Range'] = 'bytes=0-1',
            'cross_site' => $headers['Sec-Fetch-Site'] = 'cross-site',
            'origin' => $headers['Origin'] = 'https://foreign.invalid',
            'form' => $headers['Content-Type'] = 'application/x-www-form-urlencoded',
            'compression' => $headers['Content-Encoding'] = 'gzip',
            'oversize' => $body = str_repeat('x', 16385),
            'method' => $method = 'PUT',
            'override' => $headers['X-HTTP-Method-Override'] = 'DELETE',
            'malformed' => $body = '{"private_marker":"DO_NOT_LOG",',
            'array' => $body = '[]',
            'duplicate' => $body = '{"accepted":false,"accepted":true}',
            'nested_duplicate' => $body = '{"items":[{"trackId":1,"trackId":2}]}',
            default => null,
        };
        $response = $this->call($method, $path, [], [], [], $this->transformHeadersToServerVars($headers), $body);
        $response->assertStatus($status)->assertJsonPath('code', 'PRODUCTION_CHECKOUT_UNAVAILABLE')
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertDontSee('DO_NOT_LOG', false)->assertDontSee('payment_status', false);
    }

    public function test_outer_boundary_sanitizes_downstream_session_csrf_and_exception_paths(): void
    {
        config(['production_checkout.http_enabled' => true]);
        $middleware = new ProductionCheckoutPrivacy;
        foreach ([new HttpException(419, 'PRIVATE_CSRF'),
            new HttpException(429, 'PRIVATE_RATE'),
            new \RuntimeException('PRIVATE_SQL_SECRET')] as $error) {
            $request = Request::create('/production/checkout/orders/unknown/status', 'GET');
            $response = $middleware->handle($request, function () use ($error): never {
                throw $error;
            });
            $this->assertSame($error instanceof HttpExceptionInterface ? $error->getStatusCode() : 503, $response->getStatusCode());
            $this->assertStringNotContainsString('PRIVATE_', $response->getContent());
            $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
        }
        $this->assertFalse(ProductionCheckoutResponse::matches(Request::create('/orders/test')));
    }
}
