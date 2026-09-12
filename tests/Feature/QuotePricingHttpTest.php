<?php

namespace Tests\Feature;

use App\Domain\Commerce\Models\Quote;
use App\Domain\Commerce\Models\QuotePricing;
use App\Domain\Commerce\PriceQuote;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Support\PricingFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class QuotePricingHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        PricingFixtures::configure(null);
    }

    private function review(): array
    {
        return $this->postJson('/quotes', ['items' => QuoteFixtures::selection()['items']], ['Idempotency-Key' => (string) Str::uuid()])->assertOk()->json('quote');
    }

    private function price(string $url, string $body = '{}', array $headers = []): TestResponse
    {
        return $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', ...$headers], $body);
    }

    private function assertPrivate(TestResponse $response): void
    {
        $response->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Vary', 'Cookie')->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_owned_pricing_roundtrips_safe_fields_without_changing_the_quote_contract(): void
    {
        PricingFixtures::configure(PricingFixtures::policy());
        $quote = $this->review();
        $url = '/quotes/'.$quote['id'].'/pricing';
        $this->getJson($url)->assertNotFound()->assertJsonPath('code', 'PRICING_NOT_FOUND');
        $created = $this->price($url)->assertOk();
        $created->assertJsonPath('pricing.taxMinor', 375)->assertJsonPath('pricing.totalMinor', 5374)->assertJsonPath('pricing.testOnly', true)->assertJsonPath('pricing.payable', false);
        $this->assertSame(['pricingSchema', 'id', 'quoteId', 'expiresAt', 'currency', 'subtotalMinor', 'discountMinor', 'taxBasisMinor', 'taxMinor', 'totalMinor', 'taxStatus', 'payable', 'testOnly', 'policy', 'items', 'pricingHash'], array_keys($created->json('pricing')));
        $this->assertPrivate($created);
        $this->price($url)->assertOk()->assertExactJson($created->json());
        $this->getJson($url, ['X-Inertia' => 'true'])->assertOk()->assertExactJson($created->json());
        $this->getJson('/quotes/'.$quote['id'])->assertOk()->assertExactJson(['quote' => $quote]);
        foreach ([Quote::sole()->owner_key, Quote::sole()->snapshot_hash, QuotePricing::sole()->snapshot_hash, 'acct_SYNTHETICONLY', 'authored_source', 'storage_path'] as $private) {
            $created->assertDontSee($private, false);
        }
        $this->assertDatabaseCount('quote_pricings', 1);
    }

    public function test_foreign_sessions_and_login_context_cannot_create_or_read_pricing(): void
    {
        $quote = $this->review();
        $url = '/quotes/'.$quote['id'].'/pricing';
        $this->price($url)->assertOk();
        $this->flushSession();
        $denied = $this->getJson($url)->assertNotFound()->assertJsonPath('code', 'QUOTE_NOT_FOUND');
        $this->price($url)->assertNotFound()->assertExactJson($denied->json());
        $this->getJson('/quotes/'.Str::uuid().'/pricing')->assertNotFound()->assertExactJson($denied->json());
        $this->assertPrivate($denied);
        $own = $this->review();
        $ownUrl = '/quotes/'.$own['id'].'/pricing';
        $this->price($ownUrl)->assertOk();
        $this->actingAs(User::factory()->create())->getJson($ownUrl)->assertNotFound();
        $this->assertDatabaseCount('quote_pricings', 2);
    }

    public function test_client_prices_policy_fields_json_arrays_and_query_overrides_are_rejected(): void
    {
        $quote = $this->review();
        $url = '/quotes/'.$quote['id'].'/pricing';
        foreach (['[]', 'null', '{', '{"totalMinor":1}', '{"policy":{"tax":0}}', '{"owner":"someone"}', str_repeat(' ', 1025).'{}'] as $body) {
            $this->assertPrivate($this->price($url, $body)->assertUnprocessable());
        }
        $this->price($url.'?taxMinor=0')->assertUnprocessable();
        $this->assertDatabaseCount('quote_pricings', 0);
    }

    public function test_real_csrf_enforcement_applies_to_pricing_creation(): void
    {
        $quote = $this->review();
        $url = '/quotes/'.$quote['id'].'/pricing';
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery {
            protected function runningUnitTests(): bool { return false; }
        });
        $this->assertPrivate($this->price($url)->assertStatus(419)->assertJsonPath('code', 'SESSION_EXPIRED'));
        $this->assertDatabaseCount('quote_pricings', 0);
        $token = Str::random(40);
        $this->withSession(['_token' => $token]);
        $this->price($url, headers: ['HTTP_X_CSRF_TOKEN' => $token])->assertOk();
    }

    public function test_pricing_routes_share_existing_quote_rate_limits(): void
    {
        for ($index = 0; $index < 10; $index++) {
            $this->postJson('/quotes', [])->assertUnprocessable();
        }
        $this->assertPrivate($this->price('/quotes/'.Str::uuid().'/pricing')->assertStatus(429)->assertJsonPath('code', 'RATE_LIMITED'));
        for ($index = 0; $index < 60; $index++) {
            $this->getJson('/quotes/'.Str::uuid())->assertNotFound();
        }
        $this->assertPrivate($this->getJson('/quotes/'.Str::uuid().'/pricing')->assertStatus(429)->assertHeader('Retry-After'));
    }

    public function test_debug_failure_uses_existing_generic_private_boundary(): void
    {
        config(['app.debug' => true]);
        $this->app->bind(PriceQuote::class, fn () => throw new RuntimeException('private pricing account-secret master.wav'));
        $failed = $this->price('/quotes/'.Str::uuid().'/pricing')->assertStatus(500)->assertJsonPath('code', 'QUOTE_UNAVAILABLE');
        $failed->assertDontSee('account-secret')->assertDontSee('master.wav');
        $this->assertPrivate($failed);
    }

    public function test_changed_policy_and_expired_quote_are_explicit_private_failures(): void
    {
        $quote = $this->review();
        $url = '/quotes/'.$quote['id'].'/pricing';
        $this->price($url)->assertOk()->assertJsonPath('pricing.taxStatus', 'unresolved')->assertJsonPath('pricing.totalMinor', null);
        PricingFixtures::configure(PricingFixtures::policy());
        $this->assertPrivate($this->price($url)->assertConflict()->assertJsonPath('code', 'PRICING_CHANGED'));
        $this->travelTo(Quote::sole()->expires_at);
        $this->assertPrivate($this->getJson($url)->assertStatus(410)->assertJsonPath('code', 'QUOTE_EXPIRED'));
    }
}
