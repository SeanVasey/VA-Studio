<?php

namespace Tests\Feature;

use App\Domain\Customers\CustomerAccess;
use App\Domain\Grants\Free\FreeGrantRecords;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\CustomerFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\FreeGrantFixtures;
use Tests\TestCase;

/** Actual root registration: no test-only routes, privacy or exception-handler replacement. */
final class FreeGrantRegistrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
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

    public function test_registered_routes_preserve_actual_assent_original_pdf_and_exact_downloads(): void
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

    public function test_registered_page_changes_scope_and_account_link_requires_feature_enablement(): void
    {
        $f = FreeGrantFixtures::source();
        $this->login($f['customer']);
        $first = $this->get('/free-grants')->assertOk()->viewData('page');
        $second = $this->get('/free-grants')->assertOk()->viewData('page');
        $this->assertTrue($first['encryptHistory']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $first['props']['renderScope']);
        $this->assertNotSame($first['props']['renderScope'], $second['props']['renderScope']);
        $this->assertTrue($this->get('/account')->assertOk()->viewData('page')['props']['freeGrantsEnabled']);
        config(['free-grants.test_enabled' => false]);
        $this->assertFalse($this->get('/account')->assertOk()->viewData('page')['props']['freeGrantsEnabled']);
        $this->assertPrivate($this->get('/free-grants')->assertNotFound());
    }

    public function test_global_private_boundary_handles_missing_route_wrong_method_and_real_csrf_before_writes(): void
    {
        $this->assertPrivate($this->get('/free-grants/missing')->assertNotFound());
        $this->assertPrivate($this->call('PUT', '/free-grants/index', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertStatus(422));
        $f = FreeGrantFixtures::source();
        $d = FreeGrantFixtures::publish($f);
        $this->login($f['customer']);
        $this->app->instance('env', 'local');
        $this->withMiddleware(PreventRequestForgery::class);
        $response = $this->postJson('/free-grants/definitions/'.$d['id'].'/accept', ['declaredName' => 'PRIVATE-CSRF-SENTINEL'])->assertStatus(419);
        $this->assertPrivate($response);
        $response->assertDontSee('PRIVATE-CSRF-SENTINEL', false);
        $this->assertSame(0, DB::table('free_origins')->count());
        $route = Route::getRoutes()->getByName('free-grants.downloads');
        $this->assertNotNull($route);
        $this->assertTrue($route->locksFor() > 0);
        $this->assertContains('throttle:60,1,free-grants', $route->gatherMiddleware());
    }
}
