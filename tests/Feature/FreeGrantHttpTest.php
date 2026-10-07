<?php

namespace Tests\Feature;

use App\Domain\Customers\CustomerAccess;
use App\Domain\Grants\Free\FreeGrantHttpIdentity;
use App\Domain\Grants\Free\FreeGrantRecords;
use App\Http\Middleware\FreeGrantPrivacy;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CustomerFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\FreeGrantFixtures;
use Tests\TestCase;

final class FreeGrantHttpTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->app->make(Kernel::class)->prependMiddleware(FreeGrantPrivacy::class);
        // Isolated equivalent of root's required generic framework exception privacy registration.
        $this->app->make(ExceptionHandler::class)->respondUsing(static function ($response, $error, $request) {
            return FreeGrantPrivacy::matches($request) ? FreeGrantPrivacy::error($response->getStatusCode()) : $response;
        });
        Route::middleware('web')->group(base_path('routes/free-grants.php'));
        $this->fakePrivateMediaStorage();
    }

    private function login(array $f): void
    {
        $p = app(CustomerAccess::class)->principal($f['user']);
        $this->actingAs($f['user'], 'customer')->withSession(['_token' => str_repeat('c', 40), '_customer_access' => [
            'account_id' => $p->accountId, 'access_version' => $p->accessVersion, 'credential_stamp' => $p->credentialStamp,
        ]]);
    }

    private function assertPrivate($response): void
    {
        $response->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertContains('Cookie', $response->baseResponse->getVary());
    }

    public function test_actual_customer_page_review_assent_real_pdf_and_native_attachment_preserve_exact_free_origin(): void
    {
        $f = FreeGrantFixtures::source();
        $d = FreeGrantFixtures::publish($f);
        $this->login($f['customer']);
        $this->assertPrivate($this->get('/free-grants')->assertOk()->assertSee('FreeGrants'));
        $index = $this->get('/free-grants/index')->assertOk();
        $this->assertPrivate($index);
        $this->assertSame($d, $index->json('definitions.0'));
        $name = 'Explicit HTTP synthetic declaration';
        $review = $this->postJson('/free-grants/definitions/'.$d['id'].'/review', ['declaredName' => $name])->assertOk()->json();
        $request = ['requestKey' => (string) Str::uuid(), 'definitionHash' => $d['definitionHash'], 'reviewHash' => $d['reviewHash'],
            'expectedVersion' => $d['version'], 'declaredName' => $name, 'affirmed' => true, 'assentHash' => $review['assentHash']];
        $accepted = $this->postJson('/free-grants/definitions/'.$d['id'].'/accept', $request)->assertOk()->json('origin');
        $originBefore = (array) DB::table('free_origins')->sole();
        $issued = $this->postJson('/free-grants/origins/'.$accepted['id'].'/document', ['originHash' => $accepted['originHash']])->assertOk()->json('origin');
        $this->assertSame('complete', $issued['documentStatus']);
        $originalBefore = (array) DB::table('free_originals')->sole();
        foreach (['master_wav', 'contract'] as $kind) {
            $authInput = ['requestKey' => (string) Str::uuid(), 'originHash' => $accepted['originHash'], 'kind' => $kind, 'nonce' => bin2hex(random_bytes(32))];
            $auth = $this->postJson('/free-grants/origins/'.$accepted['id'].'/authorize', $authInput)->assertOk()->json('authorization');
            $this->assertSame($auth, $this->postJson('/free-grants/origins/'.$accepted['id'].'/authorize', $authInput)->assertOk()->json('authorization'));
            $path = $kind === 'master_wav' ? $f['assets']['master_wav']->storage_path : FreeGrantRecords::decode($originalBefore)['artifact']['storage_path'];
            $bytes = Storage::disk('local')->get($path);
            $body = http_build_query(['token' => $auth['token'], '_token' => str_repeat('c', 40)], '', '&', PHP_QUERY_RFC3986);
            parse_str($body, $fields);
            $url = '/free-grants/authorizations/'.$auth['id'].'/redeem';
            $response = $this->call('POST', $url, $fields, [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], $body)->assertOk();
            $this->assertPrivate($response);
            $response->assertHeader('Content-Type', $auth['mimeType'])->assertHeader('Content-Length', (string) strlen($bytes))
                ->assertHeader('X-Free-Grant-Sha256', hash('sha256', $bytes));
            $this->assertStringContainsString($auth['filename'], $response->headers->get('Content-Disposition'));
            $this->assertSame($bytes, $response->streamedContent());
            $this->postJson($url, ['token' => $auth['token']])->assertStatus(409);
        }
        $this->assertSame($originBefore, (array) DB::table('free_origins')->sole());
        $this->assertSame($originalBefore, (array) DB::table('free_originals')->sole());
        $status = $this->get('/free-grants/origins/'.$accepted['id'].'/downloads')->assertOk();
        $this->assertPrivate($status);
        $status->assertJsonPath('status.attemptCount', 2)->assertJsonCount(2, 'status.history')->assertDontSee($auth['token'], false);
        $this->assertDatabaseCount('free_redemptions', 2);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('license_grants', 0);
        $this->login(CustomerFixtures::account());
        $response = $this->get('/free-grants/origins/'.$accepted['id'])->assertNotFound();
        $this->assertPrivate($response);
        $response->assertDontSee($name, false);
    }

    public function test_unexpected_controller_failure_reports_only_the_fixed_exception_class_without_private_request_or_message(): void
    {
        CustomerFixtures::configure();
        config(['free-grants.test_enabled' => true]);
        $f = CustomerFixtures::account();
        $this->login($f);
        app()->instance(FreeGrantHttpIdentity::class, new class implements FreeGrantHttpIdentity
        {
            public function forRequest(Request $request): array
            {
                throw new \RuntimeException('PRIVATE-EXCEPTION-SENTINEL '.$request->getContent());
            }
        });
        Log::shouldReceive('error')->once()->with('Free grant request failed.', ['exception_class' => \RuntimeException::class]);
        $response = $this->postJson('/free-grants/definitions/'.Str::uuid().'/review', ['declaredName' => 'PRIVATE-REQUEST-SENTINEL'])->assertStatus(503)
            ->assertDontSee('PRIVATE-REQUEST-SENTINEL', false)->assertDontSee('PRIVATE-EXCEPTION-SENTINEL', false);
        $this->assertPrivate($response);
    }

    public static function boundaries(): array
    {
        return [['duplicate'], ['escaped-duplicate'], ['nested-duplicate'], ['oversized'], ['query'], ['get-body'], ['range'], ['origin'], ['csrf'], ['production'], ['credential'], ['native-duplicate']];
    }

    #[DataProvider('boundaries')]
    public function test_request_privacy_and_authority_refuse_before_free_origin_or_private_projection(string $case): void
    {
        // Source authoring is unnecessary for malformed requests; one current account proves session boundaries.
        CustomerFixtures::configure();
        config(['free-grants.test_enabled' => true]);
        $f = CustomerFixtures::account();
        $this->login($f);
        $uuid = (string) Str::uuid();
        $url = '/free-grants/definitions/'.$uuid.'/review';
        $raw = fn ($body) => $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);
        if ($case === 'csrf') {
            $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
            {
                protected function runningUnitTests(): bool
                {
                    return false;
                }
            });
        }
        if ($case === 'production') {
            $this->app->detectEnvironment(fn () => 'production');
        }
        if ($case === 'credential') {
            DB::table('users')->where('id', $f['user']->id)->update(['password' => 'PRIVATE-CHANGED-CREDENTIAL']);
        }
        $response = match ($case) {
            'duplicate' => $raw('{"declaredName":"PRIVATE-SENTINEL","declaredName":"other"}'),
            'escaped-duplicate' => $raw('{"declaredName":"PRIVATE-SENTINEL","\\u0064eclaredName":"other"}'),
            'nested-duplicate' => $raw('{"declaredName":{"nested":1,"nested":2}}'),
            'oversized' => $raw(str_repeat('x', 65537)),
            'query' => $this->get('/free-grants/index?owner_key=PRIVATE-SENTINEL'),
            'get-body' => $this->call('GET', '/free-grants/not-real', [], [], [], [], str_repeat('x', 100000)),
            'range' => $this->get('/free-grants/index', ['Range' => 'bytes=0-1']),
            'origin' => $this->postJson($url, ['declaredName' => 'PRIVATE-SENTINEL'], ['Origin' => 'https://foreign.invalid']),
            'native-duplicate' => $this->call('POST', '/free-grants/authorizations/'.$uuid.'/redeem', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], 'token='.str_repeat('a', 43).'&%74oken='.str_repeat('b', 43).'&_token='.str_repeat('c', 40)),
            default => $this->postJson($url, ['declaredName' => 'PRIVATE-SENTINEL'], $case === 'production' ? ['X-CSRF-TOKEN' => str_repeat('c', 40)] : []),
        };
        $expected = match ($case) {
            'oversized' => 413, 'origin', 'credential' => 403, 'production' => 404, 'csrf' => 419, default => 422
        };
        $response->assertStatus($expected)->assertJsonPath('code', 'FREE_GRANT_UNAVAILABLE')->assertDontSee('PRIVATE-SENTINEL', false)
            ->assertDontSee($f['user']->email, false)->assertDontSee($f['account']->owner_key, false);
        $this->assertPrivate($response);
        $this->assertDatabaseCount('free_origins', 0);
        $this->app->detectEnvironment(fn () => 'testing');
    }
}
