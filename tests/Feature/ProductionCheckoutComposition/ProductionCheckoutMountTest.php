<?php

namespace Tests\Feature\ProductionCheckoutComposition;

use App\Domain\Commerce\ProductionCheckout\OwnAccountStripeGateway;
use App\Domain\Commerce\ProductionCheckout\ProviderGateway;
use App\Domain\Customers\ProductionCustomerAccess;
use App\Http\ProductionCheckout\ProductionCheckoutMount;
use App\Providers\ProductionCheckoutServiceProvider;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The production checkout composition gate through the real root (lane PCC): provider registration, route mount and
 * stale-route admission. Closed for every value but the literal boolean true and in every environment but local, testing and
 * production; with it closed nothing is registered and a request is the private 404 before any session, identity or SQL work.
 */
final class ProductionCheckoutMountTest extends TestCase
{
    private const ROUTES = ['reviews', 'orders', 'hosted', 'reconcile', 'status', 'return'];

    private const ORDER = '11111111-1111-4111-8111-111111111111';

    private mixed $checkoutMount = true;

    private string $checkoutEnvironment = 'testing';

    public function createApplication()
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';
        $this->traitsUsedByTest = class_uses_recursive(static::class);
        $app->afterBootstrapping(LoadConfiguration::class, function (Application $app): void {
            $app['env'] = $this->checkoutEnvironment;
            $app['config']->set('production_checkout.mounted', $this->checkoutMount);
        });
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** @return array<string, array{0: mixed, 1: string}> */
    public static function closedCases(): array
    {
        $cases = [];
        foreach (['local', 'testing', 'production', 'staging'] as $environment) {
            $cases['shipped false in '.$environment] = [false, $environment];
        }

        return $cases + ['string true' => ['true', 'testing'], 'integer one' => [1, 'testing'], 'float one' => [1.0, 'testing'],
            'null' => [null, 'testing'], 'array' => [[true], 'testing'], 'string yes' => ['yes', 'production'],
            'staging environment' => [true, 'staging'], 'unknown environment' => [true, 'preview'], 'empty environment' => [true, ''],
            'upper-case environment' => [true, 'Production']];
    }

    #[DataProvider('closedCases')]
    public function test_cold_default_off_registers_nothing_and_refuses_before_identity_or_sql(mixed $flag, string $environment): void
    {
        $this->checkoutMount = $flag;
        $this->checkoutEnvironment = $environment;
        $this->refreshApplication();
        $this->assertFalse((require base_path('config/production_checkout.php'))['mounted']);
        $this->assertFalse(ProductionCheckoutMount::admitted());
        foreach (self::ROUTES as $name) {
            $this->assertFalse(Route::has('production.checkout.'.$name), $name);
        }
        $this->assertNull($this->app->getProvider(ProductionCheckoutServiceProvider::class));
        $this->assertFalse($this->app->bound(ProviderGateway::class));
        $queries = $this->queries();
        $this->notFound($this->postJson('/production/checkout/reviews', ['requestKey' => 'PRIVATE-REQUEST-KEY']))->assertDontSee('PRIVATE-REQUEST-KEY');
        $this->notFound($this->get('/production/checkout/orders/'.self::ORDER.'/status'));
        $this->assertSame([], $queries(), 'The refused request performed no query.');
        $this->assertFalse($this->app->resolved(ProductionCustomerAccess::class));
    }

    /** @return array<string, array{0: string}> */
    public static function admittedEnvironments(): array
    {
        return ['local' => ['local'], 'testing' => ['testing'], 'production' => ['production']];
    }

    #[DataProvider('admittedEnvironments')]
    public function test_the_literal_true_registers_the_provider_and_routes_but_every_runtime_switch_stays_shut(string $environment): void
    {
        $this->checkoutEnvironment = $environment;
        $this->refreshApplication();
        $this->assertTrue(ProductionCheckoutMount::admitted());
        foreach (self::ROUTES as $name) {
            $this->assertTrue(Route::has('production.checkout.'.$name), $name);
        }
        $this->assertNotNull($this->app->getProvider(ProductionCheckoutServiceProvider::class));
        $this->assertInstanceOf(OwnAccountStripeGateway::class, $this->app->make(ProviderGateway::class));
        // Mounting approves nothing: with the shipped runtime switches every request is the private 503 before any query.
        $this->assertFalse(config('production_checkout.http_enabled'));
        $queries = $this->queries();
        $this->privateJson($this->postJson('/production/checkout/reviews', ['candidateId' => 1]))->assertStatus(503);
        $this->privateJson($this->get('/production/checkout/orders/'.self::ORDER.'/status'))->assertStatus(503);
        $this->assertSame([], $queries());
    }

    /** @return array<string, array{0: mixed, 1: string}> */
    public static function withdrawals(): array
    {
        return ['literal false' => [false, 'testing'], 'string true' => ['true', 'testing'], 'integer one' => [1, 'testing'],
            'staging' => [true, 'staging'], 'unknown environment' => [true, 'preview']];
    }

    #[DataProvider('withdrawals')]
    public function test_runtime_withdrawal_refuses_previously_registered_routes(mixed $flag, string $environment): void
    {
        $this->assertTrue(Route::has('production.checkout.reviews'));
        config(['production_checkout.mounted' => $flag, 'production_checkout.http_enabled' => true]);
        $this->app->instance('env', $environment);
        $queries = $this->queries();
        $this->notFound($this->postJson('/production/checkout/reviews', ['candidateId' => 1]))->assertCookieMissing(config('session.cookie'));
        $this->notFound($this->get('/production/checkout/orders/'.self::ORDER.'/return'))->assertCookieMissing(config('session.cookie'));
        $this->assertSame([], $queries());
    }

    public function test_a_non_plain_value_anywhere_in_the_parent_closes_the_gate(): void
    {
        $this->assertTrue(ProductionCheckoutMount::admitted());
        config(['production_checkout.exemption_policy_owner_ids' => [new \stdClass]]);
        $this->assertFalse(ProductionCheckoutMount::admitted());
        config(['production_checkout.exemption_policy_owner_ids' => [], 'production_checkout.account_id' => 1.5]);
        $this->assertFalse(ProductionCheckoutMount::admitted());
    }

    public function test_the_composition_lives_only_behind_the_gate(): void
    {
        $this->assertMatchesRegularExpression("~if \\(ProductionCheckoutMount::admitted\\(\\)\\) \\{\\s*require __DIR__\\.'/production-checkout\\.php';\\s*\\}~",
            file_get_contents(base_path('routes/web.php')));
        $this->assertMatchesRegularExpression('~if \(ProductionCheckoutMount::admitted\(\)\) \{\s*\$this->app->register\(ProductionCheckoutServiceProvider::class\);\s*\}~',
            file_get_contents(base_path('app/Providers/AppServiceProvider.php')));
        $this->assertStringNotContainsString('ProductionCheckoutServiceProvider', file_get_contents(base_path('bootstrap/providers.php')));
        foreach (['routes/web.php', 'app/Providers/AppServiceProvider.php', 'bootstrap/app.php'] as $file) {
            $this->assertSame(1, substr_count(file_get_contents(base_path($file)), $file === 'bootstrap/app.php'
                ? 'prepend(ProductionCheckoutPrivacy::class)' : 'ProductionCheckoutMount::admitted()'), $file);
        }
    }

    private function notFound(TestResponse $response): TestResponse
    {
        return $this->privateJson($response)->assertNotFound()->assertExactJson(['code' => 'PRODUCTION_CHECKOUT_UNAVAILABLE',
            'message' => 'Checkout could not be confirmed. Retry the same request or check the saved order.']);
    }

    private function privateJson(TestResponse $response): TestResponse
    {
        return $response->assertHeader('Content-Type', 'application/json')->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    private function queries(): \Closure
    {
        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });

        return function () use (&$queries): array {
            return $queries;
        };
    }
}
