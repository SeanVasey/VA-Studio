<?php

namespace Tests\Feature;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PaidGrantRootHttpFixture;
use Tests\TestCase;

final class PaidGrantRootRegistrationTest extends TestCase
{
    use PaidGrantRootHttpFixture;

    public static function refusedMountSettings(): array
    {
        return [['shipped', false], ['string', 'true'], ['integer', 1], ['missing', null]];
    }

    #[DataProvider('refusedMountSettings')]
    public function test_only_an_explicit_boolean_mount_registers_paid_routes(string $case, mixed $setting): void
    {
        $this->paidHttpMountSetting = $setting;
        $this->refreshApplication();
        $shipped = require base_path('config/paid-grants.php');
        $this->assertFalse($shipped['http_mounted']);
        $this->assertFalse($shipped['operative_enabled']);
        $this->assertFalse($shipped['rehearsal_enabled']);
        $this->assertFalse(Route::has('paid-grants.page'), $case);
        $response = $this->get('/paid-grants')->assertNotFound()->assertExactJson([
            'code' => 'PAID_GRANT_UNAVAILABLE',
            'message' => 'This paid license is unavailable or changed. Refresh before trying again.',
        ]);
        $response->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_the_explicit_mount_uses_root_privacy_before_any_paid_controller(): void
    {
        $this->assertTrue(Route::has('paid-grants.redeem'));
        $response = $this->get('/paid-grants?PRIVATE-QUERY-SENTINEL=1')->assertStatus(422)->assertDontSee('PRIVATE-QUERY-SENTINEL', false);
        $response->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public static function rootHandlerFailures(): array
    {
        return [[\RuntimeException::class, 500], [LockTimeoutException::class, 503]];
    }

    #[DataProvider('rootHandlerFailures')]
    public function test_root_handlers_sanitize_errors_thrown_before_paid_privacy_or_controller(string $exceptionClass, int $status): void
    {
        config(['app.debug' => true]);
        $middleware = new class($exceptionClass)
        {
            public int $calls = 0;

            public function __construct(private readonly string $exceptionClass) {}

            public function handle(Request $request, Closure $next): never
            {
                $this->calls++;
                throw new $this->exceptionClass('PRIVATE-EXCEPTION-SENTINEL');
            }
        };
        $this->app->instance('paid-root-failure-fixture', $middleware);
        $this->app->make(Kernel::class)->prependMiddleware('paid-root-failure-fixture');
        Log::shouldReceive('error')->once()->with('Paid grant request failed.', ['exception_class' => $exceptionClass]);

        $response = $this->get('/paid-grants?PRIVATE-QUERY-SENTINEL=1')->assertStatus($status)->assertExactJson([
            'code' => 'PAID_GRANT_UNAVAILABLE',
            'message' => 'This paid license is unavailable or changed. Refresh before trying again.',
        ])->assertDontSee('PRIVATE-EXCEPTION-SENTINEL', false)->assertDontSee('PRIVATE-QUERY-SENTINEL', false);

        $this->assertSame(1, $middleware->calls, 'The actual root exception handlers receive an error outside paid middleware/controller.');
        $response->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertContains('Cookie', $response->baseResponse->getVary());
    }
}
