<?php

namespace Tests\Feature\ProductionMembershipHttp;

use App\Http\Membership\MembershipHttpMount;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The member mount through the real root, web stack and routes, without membership fixtures: default-off (unmounted,
 * non-boolean, production, staging), runtime withdrawal of a registered table, raw intake bounds, CSRF and the
 * per-tier rate limits. With the shipped membership gates every mounted route is the one not-found response.
 */
final class MembershipHttpMountTest extends TestCase
{
    public const NOT_FOUND = '{"code":"MEMBERSHIP_NOT_FOUND"}';

    private const PERIOD = '11111111-1111-4111-8111-111111111111';

    private mixed $memberMount = true;

    private string $memberEnvironment = 'testing';

    public function createApplication()
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';
        $this->traitsUsedByTest = class_uses_recursive(static::class);
        $app->afterBootstrapping(LoadConfiguration::class, function (Application $app): void {
            $app['env'] = $this->memberEnvironment;
            $app['config']->set('production-memberships.http_mounted', $this->memberMount);
        });
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** @return array<string, array{0:mixed,1:string}> */
    public static function mountCases(): array
    {
        return ['shipped false' => [false, 'testing'], 'string true' => ['true', 'testing'], 'integer one' => [1, 'testing'],
            'null' => [null, 'testing'], 'array' => [[true], 'testing'], 'production environment' => [true, 'production'],
            'staging environment' => [true, 'staging']];
    }

    #[DataProvider('mountCases')]
    public function test_cold_default_off_registers_no_route_and_refuses_before_identity_or_sql(mixed $flag, string $environment): void
    {
        $this->memberMount = $flag;
        $this->memberEnvironment = $environment;
        $this->refreshApplication();
        $this->assertFalse((require base_path('config/production-memberships.php'))['http_mounted']);
        $this->assertFalse(MembershipHttpMount::admitted());
        foreach (['index', 'reserve', 'release', 'expire'] as $name) {
            $this->assertFalse(Route::has('production-memberships.'.$name), $name);
        }
        $queries = $this->queries();
        $this->notFound($this->get('/production-memberships/periods', ['Accept' => 'text/html']));
        $this->notFound($this->postMember('/production-memberships/periods/'.self::PERIOD.'/reservations',
            ['offerId' => '1', 'expectedRevision' => 1, 'requestKey' => 'PRIVATE-REQUEST-KEY-0001']))->assertDontSee('PRIVATE-REQUEST-KEY');
        $this->assertSame([], $queries(), 'The refused request performed no query.');
    }

    #[DataProvider('mountCases')]
    public function test_runtime_withdrawal_refuses_previously_registered_routes(mixed $flag, string $environment): void
    {
        $this->assertTrue(Route::has('production-memberships.release'));
        config(['production-memberships.http_mounted' => $flag]);
        $this->app->instance('env', $environment);
        $queries = $this->queries();
        $this->notFound($this->getMember('/production-memberships/periods'));
        $this->notFound($this->postMember('/production-memberships/periods/'.self::PERIOD.'/expire', []));
        $this->assertSame([], $queries());
    }

    public function test_mounted_routes_with_the_shipped_membership_gates_are_the_one_not_found_response(): void
    {
        foreach (['index', 'reserve', 'release', 'expire'] as $name) {
            $this->assertTrue(Route::has('production-memberships.'.$name), $name);
        }
        $this->assertFalse(config('production-memberships.enabled'));
        $this->assertNull(config('production-memberships.approved_policy_hash'));
        $this->assertFalse(config('member-grants.enabled'));
        $responses = [
            $this->getMember('/production-memberships/periods'),
            $this->postMember('/production-memberships/periods/'.self::PERIOD.'/reservations', ['offerId' => '1', 'expectedRevision' => 1, 'requestKey' => str_repeat('k', 16)]),
            $this->postMember('/production-memberships/periods/'.self::PERIOD.'/reservations/'.self::PERIOD.'/release', []),
            $this->postMember('/production-memberships/periods/'.self::PERIOD.'/expire', []),
            // An id outside the route pattern and an unknown path under the prefix are the same response.
            $this->postMember('/production-memberships/periods/not-a-period/expire', []),
            $this->getMember('/production-memberships/unknown'),
        ];
        foreach ($responses as $response) {
            $this->notFound($response);
        }
    }

    /** @return array<string, array{0:string,1:string,2:?string,3:array,4:int}> */
    public static function intakeCases(): array
    {
        $reserve = '/production-memberships/periods/'.self::PERIOD.'/reservations';

        return [
            'duplicate key' => ['POST', $reserve, '{"offerId":"1","offerId":"2"}', [], 422],
            'escaped duplicate key' => ['POST', $reserve, '{"offerId":"1","\\u006ffferId":"2"}', [], 422],
            'array body' => ['POST', $reserve, '[]', [], 422],
            'malformed' => ['POST', $reserve, '{', [], 422],
            'oversized json' => ['POST', $reserve, null, [], 413],
            'declared oversize' => ['POST', $reserve, '{}', ['CONTENT_LENGTH' => '1025'], 413],
            'range' => ['GET', '/production-memberships/periods', null, ['HTTP_RANGE' => 'bytes=0-1'], 422],
            'query string' => ['GET', '/production-memberships/periods?requestKey=PRIVATE-REQUEST-KEY', '', [], 422],
            'get with a body' => ['GET', '/production-memberships/periods', 'x', [], 422],
            'compressed body' => ['POST', $reserve, '{}', ['HTTP_CONTENT_ENCODING' => 'gzip'], 415],
            'foreign origin' => ['POST', $reserve, '{}', ['HTTP_ORIGIN' => 'https://foreign.invalid'], 403],
            'cross-site fetch' => ['POST', $reserve, '{}', ['HTTP_SEC_FETCH_SITE' => 'cross-site'], 403],
            'wrong media type' => ['POST', $reserve, '{}', ['CONTENT_TYPE' => 'text/plain'], 415],
            'form body' => ['POST', $reserve, 'offerId=1', ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], 415],
            'method override' => ['POST', $reserve, '{}', ['HTTP_X_HTTP_METHOD_OVERRIDE' => 'PUT'], 405],
            'put' => ['PUT', $reserve, '{}', [], 405],
        ];
    }

    #[DataProvider('intakeCases')]
    public function test_raw_intake_is_bounded_before_identity(string $method, string $path, ?string $body, array $server, int $status): void
    {
        $queries = $this->queries();
        $response = $this->call($method, $path, [], [], [], [...['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'text/html'], ...$server],
            $body ?? str_repeat('x', 1025));
        $this->privateJson($response)->assertStatus($status)->assertExactJson(['code' => 'MEMBERSHIP_UNAVAILABLE'])->assertDontSee('PRIVATE-REQUEST-KEY');
        $this->assertSame([], $queries(), 'Refused before any query.');
    }

    public function test_csrf_is_enforced_before_the_controller(): void
    {
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        $this->withSession(['_token' => str_repeat('t', 40)]);
        $this->privateJson($this->call('POST', '/production-memberships/periods/'.self::PERIOD.'/expire', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => str_repeat('z', 40)], '{}'))->assertStatus(419);
        // With the right token the request reaches the controller, which refuses with the one not-found response.
        $this->notFound($this->call('POST', '/production-memberships/periods/'.self::PERIOD.'/expire', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => str_repeat('t', 40)], '{}'));
    }

    public function test_reads_and_writes_have_separate_small_rate_limits(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->notFound($this->getMember('/production-memberships/periods'));
        }
        $limited = $this->privateJson($this->getMember('/production-memberships/periods'))->assertStatus(429)
            ->assertExactJson(['code' => 'MEMBERSHIP_UNAVAILABLE']);
        $this->assertMatchesRegularExpression('/\A[0-9]{1,3}\z/', (string) $limited->headers->get('Retry-After'));
        // The read budget is spent; the write tier still answers from its own budget.
        $this->notFound($this->postMember('/production-memberships/periods/'.self::PERIOD.'/expire', []));
        for ($i = 0; $i < 9; $i++) {
            $this->notFound($this->postMember('/production-memberships/periods/'.self::PERIOD.'/reservations/'.self::PERIOD.'/release', []));
        }
        $this->privateJson($this->postMember('/production-memberships/periods/'.self::PERIOD.'/expire', []))->assertStatus(429);
    }

    private function getMember(string $path): TestResponse
    {
        return $this->get($path, ['Accept' => 'application/json']);
    }

    private function postMember(string $path, array $body): TestResponse
    {
        return $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => session()->token()], json_encode((object) $body, JSON_THROW_ON_ERROR));
    }

    private function notFound(TestResponse $response): TestResponse
    {
        $this->privateJson($response)->assertNotFound();
        $this->assertSame(self::NOT_FOUND, $response->getContent());

        return $response;
    }

    private function privateJson(TestResponse $response): TestResponse
    {
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertContains('Cookie', $response->baseResponse->getVary());

        return $response;
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
