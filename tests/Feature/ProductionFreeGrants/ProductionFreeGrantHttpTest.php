<?php

namespace Tests\Feature\ProductionFreeGrants;

use App\Domain\Grants\ProductionFree\ProductionFreeGrantDocuments;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantRendererProcess;
use App\Domain\Grants\ProductionFree\ProductionFreeGrants;
use App\Http\ProductionFree\ProductionFreeDownloadResponse;
use App\Http\ProductionFree\ProductionFreeHttpMount;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ProductionFreeGrantFixtures;
use Tests\TestCase;

/**
 * The family 256 customer mount through the real root, web stack and routes: default-off (unmounted, non-boolean,
 * production), a synthetic journey with the fake sources, CSRF, Range and body bounds, contention as a retryable 503,
 * replay, foreign and withdrawn owners, and no file byte after a post-commit withdrawal.
 */
final class ProductionFreeGrantHttpTest extends TestCase
{
    use ProductionFreeGrantFixtures;

    private mixed $freeMount = true;

    private string $freeEnvironment = 'testing';

    public function createApplication()
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';
        $this->traitsUsedByTest = class_uses_recursive(static::class);
        $app->afterBootstrapping(LoadConfiguration::class, function (Application $app): void {
            $app['env'] = $this->freeEnvironment;
            $app['config']->set('production-free-grants.http_mounted', $this->freeMount);
        });
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->freeSetup();
    }

    /** @return array<string, array{0:mixed,1:string}> */
    public static function mountCases(): array
    {
        return ['shipped false' => [false, 'testing'], 'string true' => ['true', 'testing'], 'integer one' => [1, 'testing'],
            'null' => [null, 'testing'], 'array' => [[true], 'testing'], 'production environment' => [true, 'production']];
    }

    #[DataProvider('mountCases')]
    public function test_cold_default_off_registers_no_route_and_refuses_before_identity_or_sql(mixed $flag, string $environment): void
    {
        $this->freeMount = $flag;
        $this->freeEnvironment = $environment;
        $this->refreshApplication();
        $this->assertFalse((require base_path('config/production-free-grants.php'))['http_mounted']);
        $this->assertFalse(ProductionFreeHttpMount::admitted());
        foreach (['index', 'show', 'review', 'accept', 'authorize', 'redeem'] as $name) {
            $this->assertFalse(Route::has('production-free-grants.'.$name), $name);
        }
        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });
        $id = (string) Str::uuid();
        $this->privateJson($this->get('/production-free-grants/library', ['Accept' => 'text/html']))->assertNotFound();
        $this->privateJson($this->call('POST', '/production-free-grants/authorizations/'.$id.'/redeem', [], [], [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], 'token=PRIVATE-TOKEN&_token=x'))->assertNotFound()->assertDontSee('PRIVATE-TOKEN');
        $this->assertSame([], $queries, 'The refused request performed no query.');
        // The renderer binding is inert while unmounted: no route renders, and the shipped policy refuses a render before
        // the renderer is ever resolved.
        $this->assertTrue($this->app->bound(ProductionFreeGrantRendererProcess::class));
        $this->assertFalse($this->app->resolved(ProductionFreeGrantRendererProcess::class));
        $this->assertFalse((require base_path('config/production-free-grants.php'))['enabled']);
    }

    #[DataProvider('mountCases')]
    public function test_runtime_withdrawal_refuses_previously_registered_routes(mixed $flag, string $environment): void
    {
        $this->assertTrue(Route::has('production-free-grants.redeem'));
        $owner = $this->signIn();
        config(['production-free-grants.http_mounted' => $flag]);
        $this->app->instance('env', $environment);
        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });
        $this->privateJson($this->getFree('/production-free-grants/library'))->assertNotFound()->assertJsonPath('code', 'PRODUCTION_FREE_GRANT_UNAVAILABLE');
        $this->privateJson($this->postFree('/production-free-grants/definitions/'.Str::uuid().'/review', ['declaredName' => 'Declared Synthetic Buyer']))->assertNotFound();
        $this->assertSame([], $queries);
        $this->assertNotNull($owner);
    }

    public function test_synthetic_journey_through_the_real_mounted_routes(): void
    {
        $definition = $this->openDefinition();
        $owner = $this->signIn();
        $review = $this->privateJson($this->postFree('/production-free-grants/definitions/'.$definition['id'].'/review', ['declaredName' => 'Declared Synthetic Buyer']))
            ->assertOk()->json('review');
        $input = $this->assentInput($review);
        $origin = $this->privateJson($this->postFree('/production-free-grants/definitions/'.$definition['id'].'/accept', $input))->assertOk()
            ->assertJsonPath('origin.documentStatus', 'pending')->assertJsonPath('origin.amountMinor', 0)->json('origin');
        // Replay of the same request key returns the same origin and writes nothing more.
        $this->postFree('/production-free-grants/definitions/'.$definition['id'].'/accept', $input)->assertOk()->assertJsonPath('origin.id', $origin['id']);
        $this->assertSame(1, DB::table('production_free_origins')->count());
        // Rendering is worker-side; render-worker wiring is outside this mount.
        (new ProductionFreeGrantDocuments)->render($origin['id']);
        $this->privateJson($this->getFree('/production-free-grants/library'))->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.documentStatus', 'complete')->assertJsonMissingPath('items.0.originSeal');
        $show = $this->privateJson($this->getFree('/production-free-grants/origins/'.$origin['id']))->assertOk()->json('origin');
        $this->assertSame('unknown', $show['marketingConsent']);
        $authorization = $this->privateJson($this->postFree('/production-free-grants/origins/'.$origin['id'].'/authorizations',
            ['originSeal' => $show['originSeal'], 'role' => 'master_wav']))->assertOk()->json('authorization');
        $this->assertSame(hash('sha256', $authorization['token']), DB::table('production_free_authorizations')->value('token_hash'));
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $this->assertStringNotContainsString('token', $route->uri());
        }

        $response = $this->redeem($authorization);
        $this->assertInstanceOf(ProductionFreeDownloadResponse::class, $response->baseResponse);
        $response->assertOk()->assertHeader('Content-Type', 'audio/wav')->assertHeader('Content-Disposition', 'attachment; filename="'.$authorization['filename'].'"');
        $this->privateHeaders($response);
        $this->assertSame($this->sources->bytes['synthetic-master_wav'], $response->streamedContent());
        // One-use: the same token cannot be redeemed again.
        $this->privateJson($this->redeem($authorization))->assertStatus(409)->assertJsonPath('reason', 'already_redeemed');
        $this->assertSame(1, DB::table('production_free_redemptions')->count());
        // The same redemption also accepts JSON with exactly the token.
        $second = $this->postFree('/production-free-grants/origins/'.$origin['id'].'/authorizations', ['originSeal' => $show['originSeal'], 'role' => 'contract'])->json('authorization');
        $pdf = $this->postFree('/production-free-grants/authorizations/'.$second['id'].'/redeem', ['token' => $second['token']]);
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-1.', $pdf->streamedContent());
    }

    public function test_csrf_is_enforced_for_json_and_for_the_native_redemption_form(): void
    {
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        [, $authorization] = $this->authorized();
        $this->privateJson($this->call('POST', '/production-free-grants/definitions/'.Str::uuid().'/review', [], [], [],
            ['CONTENT_TYPE' => 'application/json'], '{"declaredName":"Declared Synthetic Buyer"}'))->assertStatus(419);
        $this->privateJson($this->call('POST', '/production-free-grants/authorizations/'.$authorization['id'].'/redeem', [], [], [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], 'token='.$authorization['token'].'&_token='.str_repeat('z', 40)))->assertStatus(419);
        $this->assertSame(0, DB::table('production_free_redemptions')->count());
        $this->assertSame($this->sources->bytes['synthetic-master_wav'], $this->redeem($authorization)->assertOk()->streamedContent());
    }

    /** @return array<string, array{0:string,1:string,2:?string,3:array,4:int}> */
    public static function intakeCases(): array
    {
        $review = '/production-free-grants/definitions/11111111-1111-4111-8111-111111111111/review';
        $redeem = '/production-free-grants/authorizations/11111111-1111-4111-8111-111111111111/redeem';

        return [
            'duplicate key' => ['POST', $review, '{"declaredName":"a","declaredName":"b"}', [], 422],
            'escaped duplicate key' => ['POST', $review, '{"declaredName":"a","\\u0064eclaredName":"b"}', [], 422],
            'array body' => ['POST', $review, '[]', [], 422],
            'malformed' => ['POST', $review, '{', [], 422],
            'oversized json' => ['POST', $review, null, [], 413],
            'declared oversize' => ['POST', $review, '{}', ['CONTENT_LENGTH' => '4097'], 413],
            'range' => ['GET', '/production-free-grants/library', null, ['HTTP_RANGE' => 'bytes=0-1'], 422],
            'if-range' => ['POST', $redeem, 'token=x&_token=y', ['HTTP_IF_RANGE' => 'x', 'CONTENT_TYPE' => 'application/x-www-form-urlencoded'], 422],
            'query string token' => ['POST', $redeem.'?token=PRIVATE-TOKEN', '{}', [], 422],
            'get with a body' => ['GET', '/production-free-grants/library', 'x', [], 422],
            'compressed body' => ['POST', $review, '{}', ['HTTP_CONTENT_ENCODING' => 'gzip'], 415],
            'foreign origin' => ['POST', $review, '{}', ['HTTP_ORIGIN' => 'https://foreign.invalid'], 403],
            'cross-site fetch' => ['POST', $review, '{}', ['HTTP_SEC_FETCH_SITE' => 'cross-site'], 403],
            'wrong media type' => ['POST', $review, '{}', ['CONTENT_TYPE' => 'text/plain'], 415],
            'form outside the redemption' => ['POST', $review, 'declaredName=a', ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], 415],
            'form with an extra field' => ['POST', $redeem, 'token='.str_repeat('a', 43).'&_token='.str_repeat('b', 40).'&x=1', ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], 422],
            'form with a repeated field' => ['POST', $redeem, 'token='.str_repeat('a', 43).'&token='.str_repeat('a', 43), ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], 422],
            'method override' => ['POST', $review, '{}', ['HTTP_X_HTTP_METHOD_OVERRIDE' => 'PUT'], 405],
            'put' => ['PUT', $review, '{}', [], 405],
        ];
    }

    #[DataProvider('intakeCases')]
    public function test_raw_intake_is_bounded_before_identity(string $method, string $path, ?string $body, array $server, int $status): void
    {
        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });
        $response = $this->call($method, $path, [], [], [], [...['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'text/html'], ...$server],
            $body ?? str_repeat('x', 4097));
        $this->privateJson($response)->assertStatus($status)->assertDontSee('PRIVATE-TOKEN');
        $this->assertSame([], $queries, 'Refused before any query.');
    }

    public function test_contention_is_a_retryable_503_and_the_same_request_key_then_succeeds(): void
    {
        $definition = $this->openDefinition();
        $this->signIn();
        $review = $this->postFree('/production-free-grants/definitions/'.$definition['id'].'/review', ['declaredName' => 'Declared Synthetic Buyer'])->json('review');
        $input = $this->assentInput($review);
        $calls = $this->contendAfterWrite('production_free_origins');
        $response = $this->postFree('/production-free-grants/definitions/'.$definition['id'].'/accept', $input);
        $this->privateJson($response)->assertStatus(503)->assertHeader('Retry-After', '1')
            ->assertJsonPath('code', 'PRODUCTION_FREE_GRANT_CONTENTION')->assertJsonPath('reason', 'contention');
        $this->assertSame(1, $calls());
        $this->assertSame(0, DB::table('production_free_origins')->count());
        $retry = $this->postFree('/production-free-grants/definitions/'.$definition['id'].'/accept', $input)->assertOk()->json('origin');
        $this->assertSame(1, DB::table('production_free_origins')->count());
        $this->assertSame($retry['id'], DB::table('production_free_origins')->value('id'));
    }

    public function test_library_views_do_not_spend_the_write_or_redemption_rate_limit(): void
    {
        [, $authorization, $origin, $seal] = $this->authorized();
        // More reads than the write and redemption limits (20 a minute) allow; each tier has its own budget.
        for ($i = 0; $i < 25; $i++) {
            $this->getFree('/production-free-grants/library')->assertOk();
        }
        $this->postFree('/production-free-grants/origins/'.$origin['id'].'/authorizations', ['originSeal' => $seal, 'role' => 'contract'])->assertOk();
        $this->redeem($authorization)->assertOk();
        $this->assertSame(1, DB::table('production_free_redemptions')->count());
    }

    public function test_foreign_and_withdrawn_owners_are_refused(): void
    {
        [$owner, $authorization, $origin, $seal] = $this->authorized();
        $other = $this->customer('other@example.test');
        $this->actAs($other);
        $this->privateJson($this->getFree('/production-free-grants/origins/'.$origin['id']))->assertNotFound()->assertJsonPath('reason', 'not_found');
        $this->postFree('/production-free-grants/origins/'.$origin['id'].'/authorizations', ['originSeal' => $seal, 'role' => 'master_wav'])->assertNotFound();
        $this->privateJson($this->redeem($authorization))->assertNotFound();
        $this->getFree('/production-free-grants/library')->assertOk()->assertJsonPath('total', 0);
        // A forged marker for the right actor, and a withdrawn account, are both identity refusals.
        Auth::guard('customer')->setUser($owner['user']);
        $this->withSession(['_production_customer_identity' => ['binding_digest' => str_repeat('a', 64)]]);
        $this->privateJson($this->getFree('/production-free-grants/library'))->assertForbidden()->assertJsonPath('reason', 'identity_refused');
        $this->actAs($owner);
        DB::table('customer_accounts')->where('id', $owner['principal']->accountId)->update(['active' => false, 'access_version' => 2]);
        $this->privateJson($this->getFree('/production-free-grants/library'))->assertForbidden();
        $this->assertSame(0, DB::table('production_free_redemptions')->count());
    }

    /** @return array<string, array{0:string}> */
    public static function postCommitWithdrawals(): array
    {
        return ['staff revocation' => ['revocation'], 'session marker withdrawn' => ['marker'], 'mount withdrawn' => ['mount']];
    }

    #[DataProvider('postCommitWithdrawals')]
    public function test_a_withdrawal_after_the_redemption_commit_sends_no_header_or_file_byte(string $withdrawal): void
    {
        [, $authorization, $origin, $seal] = $this->authorized();
        $response = $this->redeem($authorization);
        $response->assertOk();
        $this->assertSame(1, DB::table('production_free_redemptions')->count());
        match ($withdrawal) {
            'revocation' => (new ProductionFreeGrants)->revoke($origin['id'], ['originSeal' => $seal, 'reason' => 'Synthetic withdrawal after redemption'], $this->staff()),
            'marker' => $this->app['session.store']->forget('_production_customer_identity'),
            'mount' => config(['production-free-grants.http_mounted' => false]),
        };
        $body = $response->streamedContent();
        $this->assertSame(503, $response->baseResponse->getStatusCode());
        $this->assertFalse($response->baseResponse->headers->has('Content-Disposition'));
        $this->assertSame('application/json', $response->baseResponse->headers->get('Content-Type'));
        $this->assertSame(['code' => 'PRODUCTION_FREE_GRANT_OUTCOME_UNKNOWN', 'reason' => 'outcome_unknown'], json_decode($body, true, 4, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($this->sources->bytes['synthetic-master_wav'], $body);
        $this->assertSame(1, DB::table('production_free_redemptions')->count(), 'The committed redemption is retained.');
    }

    private function signIn(): array
    {
        $owner = $this->customer();
        $token = str_repeat('t', 40);
        $this->withSession(['_token' => $token]);
        $this->postJson('/customer/sign-in', ['email' => 'free-owner@example.test', 'password' => 'MailboxPassword123'], ['X-CSRF-TOKEN' => $token])->assertOk();
        $this->assertIsArray(session('_production_customer_identity'));

        return $owner;
    }

    private function actAs(array $owner): void
    {
        Auth::guard('customer')->setUser($owner['user']);
        $this->withSession(['_token' => str_repeat('c', 40), '_production_customer_identity' => ['binding_digest' => $owner['principal']->sessionBindingDigest()]]);
    }

    /** @return array{0:array,1:array,2:array,3:string} */
    private function authorized(): array
    {
        $definition = $this->openDefinition();
        $owner = $this->customer();
        $grants = new ProductionFreeGrants;
        $review = $grants->review($definition['id'], 'Declared Synthetic Buyer', $owner['principal'], $owner['user']);
        $origin = $grants->accept($definition['id'], $this->assentInput($review), $owner['principal'], $owner['user']);
        (new ProductionFreeGrantDocuments)->render($origin['id']);
        $this->actAs($owner);
        $seal = $this->getFree('/production-free-grants/origins/'.$origin['id'])->assertOk()->json('origin.originSeal');
        $authorization = $this->postFree('/production-free-grants/origins/'.$origin['id'].'/authorizations', ['originSeal' => $seal, 'role' => 'master_wav'])
            ->assertOk()->json('authorization');

        return [$owner, $authorization, $origin, $seal];
    }

    /** A plain GET: no body (the JSON test helper would send `[]`, which the mount refuses on GET). */
    private function getFree(string $path): TestResponse
    {
        return $this->get($path, ['Accept' => 'application/json']);
    }

    private function postFree(string $path, array $body): TestResponse
    {
        return $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => session()->token()], json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function redeem(array $authorization): TestResponse
    {
        return $this->call('POST', '/production-free-grants/authorizations/'.$authorization['id'].'/redeem', [], [], [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_ACCEPT' => 'text/html'],
            'token='.$authorization['token'].'&_token='.session()->token());
    }

    private function privateJson(TestResponse $response): TestResponse
    {
        $response->assertHeader('Content-Type', 'application/json');
        $this->privateHeaders($response);

        return $response;
    }

    private function privateHeaders(TestResponse $response): void
    {
        $response->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertContains('Cookie', $response->baseResponse->getVary());
    }

    /** A MySQL deadlock (1213) from the first decrypt after the command wrote `$table`, inside its transaction. */
    private function contendAfterWrite(string $table): \Closure
    {
        $threw = 0;
        $hook = function () use (&$threw, $table): void {
            if ($threw === 0 && DB::table($table)->count() > 0) {
                $threw++;
                $error = new PDOException('Deadlock found when trying to get lock; try restarting transaction');
                $error->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];

                throw $error;
            }
        };
        $original = $this->app->make('encrypter');
        $decorated = new class($original->getKey(), $this->app->make('config')->get('app.cipher'), $hook) extends Encrypter
        {
            public function __construct($key, $cipher, private readonly \Closure $hook)
            {
                parent::__construct($key, $cipher);
            }

            public function decrypt($payload, $unserialize = true)
            {
                ($this->hook)();

                return parent::decrypt($payload, $unserialize);
            }
        };
        $decorated->previousKeys($original->getPreviousKeys());
        Crypt::swap($decorated);
        $this->beforeApplicationDestroyed(fn () => Crypt::clearResolvedInstance('encrypter'));

        return function () use (&$threw): int {
            return $threw;
        };
    }

    private function refuses(callable $operation, string $reason): void
    {
        try {
            $operation();
            $this->fail('Must refuse '.$reason);
        } catch (ProductionFreeGrantException $error) {
            $this->assertSame($reason, $error->reason);
        }
    }
}
