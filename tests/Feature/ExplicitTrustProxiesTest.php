<?php

namespace Tests\Feature;

use App\Http\Middleware\ExplicitTrustProxies;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Through the real global stack: a client outside the reviewed list never chooses its own address, scheme or host, including on
 * an `.on-forge.com` host where the framework middleware would trust every proxy, and a forwarded host is never trusted.
 */
final class ExplicitTrustProxiesTest extends TestCase
{
    private const FORWARDED = ['HTTP_X_FORWARDED_FOR' => '198.51.100.7', 'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_X_FORWARDED_PORT' => '443', 'HTTP_X_FORWARDED_HOST' => 'attacker.invalid', 'HTTP_X_FORWARDED_PREFIX' => '/attacker'];

    protected function setUp(): void
    {
        parent::setUp();
        Route::get('/__proxy-probe', fn (Request $request) => response()->json(['ip' => $request->ip(),
            'secure' => $request->isSecure(), 'host' => $request->getHost(), 'base' => $request->getBaseUrl()]))->withoutMiddleware('web');
    }

    protected function tearDown(): void
    {
        Request::setTrustedProxies([], ExplicitTrustProxies::HEADERS);
        parent::tearDown();
    }

    private function probe(string $remote, string $host = 'shop.synthetic.invalid'): TestResponse
    {
        return $this->call('GET', 'http://'.$host.'/__proxy-probe', [], [], [], ['REMOTE_ADDR' => $remote, ...self::FORWARDED]);
    }

    public function test_the_framework_middleware_is_replaced_and_the_explicit_one_runs_before_every_application_seam(): void
    {
        $global = $this->app[Kernel::class]->getGlobalMiddleware();
        $this->assertNotContains(TrustProxies::class, $global);
        // Before every application seam (packages may prepend their own non-request middleware ahead of it).
        $position = array_search(ExplicitTrustProxies::class, $global, true);
        $this->assertIsInt($position);
        foreach ($global as $index => $middleware) {
            if (str_starts_with($middleware, 'App\\') && $middleware !== ExplicitTrustProxies::class) {
                $this->assertGreaterThan($position, $index, $middleware);
            }
        }
        $this->assertSame('', (require base_path('config/trusted-proxies.php'))['proxies']);
    }

    /** @return array<string, array{0: string}> */
    public static function hosts(): array
    {
        return ['ordinary host' => ['shop.synthetic.invalid'], 'Forge default domain' => ['shop-synthetic.on-forge.com'],
            'Vapor default domain' => ['shop-synthetic.on-vapor.com']];
    }

    #[DataProvider('hosts')]
    public function test_the_shipped_default_trusts_no_proxy_on_any_host(string $host): void
    {
        $this->probe('10.0.0.5', $host)->assertExactJson(['ip' => '10.0.0.5', 'secure' => false, 'host' => $host, 'base' => '']);
    }

    public function test_a_listed_proxy_supplies_client_address_and_scheme_but_never_the_host_or_prefix(): void
    {
        foreach (['10.0.0.5', '10.0.0.0/8', '192.0.2.1, 10.0.0.5'] as $list) {
            config(['trusted-proxies.proxies' => $list]);
            $this->probe('10.0.0.5')->assertExactJson(['ip' => '198.51.100.7', 'secure' => true, 'host' => 'shop.synthetic.invalid', 'base' => '']);
        }
    }

    public function test_a_client_outside_the_list_cannot_choose_its_own_address(): void
    {
        config(['trusted-proxies.proxies' => '10.0.0.5']);
        $this->probe('203.0.113.9')->assertExactJson(['ip' => '203.0.113.9', 'secure' => false, 'host' => 'shop.synthetic.invalid', 'base' => '']);
        $this->probe('203.0.113.9', 'shop-synthetic.on-forge.com')->assertJsonPath('ip', '203.0.113.9');
    }

    /** @return array<string, array{0: mixed}> */
    public static function permissive(): array
    {
        return ['wildcard' => ['*'], 'double wildcard' => ['**'], 'alias' => ['REMOTE_ADDR'], 'mixed' => ['10.0.0.5,*'],
            'every address' => ['0.0.0.0/0'], 'not a string' => [['10.0.0.5']]];
    }

    #[DataProvider('permissive')]
    public function test_a_permissive_or_malformed_list_trusts_nothing(mixed $list): void
    {
        config(['trusted-proxies.proxies' => $list]);
        $this->probe('10.0.0.5')->assertExactJson(['ip' => '10.0.0.5', 'secure' => false, 'host' => 'shop.synthetic.invalid', 'base' => '']);
    }
}
