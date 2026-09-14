<?php

namespace Tests\Feature;

use App\Domain\Commerce\Models\PromotionCampaign;
use App\Domain\Commerce\Models\Quote;
use App\Domain\Commerce\Models\QuotePricing;
use App\Domain\Commerce\PriceQuote;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Support\PromotionFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class PromotionPricingHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp(); $this->withoutVite(); $this->fakePrivateMediaStorage();
        config(['commerce.test_pricing_policy' => null]);
        PromotionFixtures::configure([PromotionFixtures::policy()]);
    }

    private function quote(): array
    {
        return $this->postJson('/quotes', ['items' => QuoteFixtures::selection()['items']], ['Idempotency-Key' => (string) Str::uuid()])->assertOk()->json('quote');
    }

    private function postPromotion(string $id, string $body = '{"promotionCode":"SYNTHETIC"}', array $headers = []): TestResponse
    {
        return $this->call('POST', '/quotes/'.$id.'/pricing/promotions', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', ...$headers], $body);
    }

    private function assertPrivate(TestResponse $response): void
    {
        $response->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Vary', 'Cookie')->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_identifier_only_command_returns_versioned_private_pricing_and_preserves_old_quote_contract(): void
    {
        $quote = $this->quote();
        $response = $this->postPromotion($quote['id'])->assertOk()->assertJsonPath('pricing.pricingSchema', 2)
            ->assertJsonPath('pricing.discountMinor', 500)->assertJsonPath('pricing.taxBasisMinor', 4499)
            ->assertJsonPath('pricing.taxMinor', null)->assertJsonPath('pricing.payable', false)->assertJsonPath('pricing.testOnly', true);
        $this->assertPrivate($response);
        $this->assertSame(['key', 'version', 'code', 'hash'], array_keys($response->json('pricing.promotion')));
        $this->postPromotion($quote['id'])->assertOk()->assertExactJson($response->json());
        $this->getJson('/quotes/'.$quote['id'].'/pricing', ['X-Inertia' => 'true'])->assertOk()->assertExactJson($response->json());
        $this->getJson('/quotes/'.$quote['id'])->assertOk()->assertExactJson(['quote' => $quote]);
        foreach ([Quote::sole()->owner_key, Quote::sole()->snapshot_hash, QuotePricing::sole()->snapshot_hash, 'max_uses', 'attempt_id', 'discount_trace', 'storage_path'] as $private) {
            $response->assertDontSee($private, false);
        }
        $this->assertSame(PromotionCampaign::sole()->snapshot_hash, $response->json('pricing.promotion.hash'));
        $this->postJson('/quotes/'.$quote['id'].'/pricing', ['promotionCode' => 'SYNTHETIC'])->assertUnprocessable();
    }

    public function test_foreign_sessions_and_login_context_cannot_access_or_reserve_promotions(): void
    {
        $quote = $this->quote(); $this->postPromotion($quote['id'])->assertOk();
        $this->flushSession(); config(['commerce.test_promotions' => 'private broken configuration']);
        $denied = $this->postPromotion($quote['id'])->assertNotFound()->assertJsonPath('code', 'QUOTE_NOT_FOUND');
        $this->postPromotion((string) Str::uuid())->assertNotFound()->assertExactJson($denied->json());
        $this->assertPrivate($denied);
        $this->actingAs(User::factory()->create())->getJson('/quotes/'.$quote['id'].'/pricing')->assertNotFound();
        $this->assertDatabaseCount('promotion_uses', 1);
    }

    public function test_malformed_bodies_and_client_policy_amounts_are_rejected(): void
    {
        $quote = $this->quote();
        foreach (['{}', '[]', 'null', '{', '{"promotionCode":"synthetic"}', '{"promotionCode":1}',
            '{"promotionCode":"SYNTHETIC","discountMinor":4999}', str_repeat(' ', 1025).'{}'] as $body) {
            $this->assertPrivate($this->postPromotion($quote['id'], $body)->assertUnprocessable());
        }
        $this->postJson('/quotes/'.$quote['id'].'/pricing/promotions?code=OTHER', ['promotionCode' => 'SYNTHETIC'])->assertUnprocessable();
        $this->assertDatabaseCount('promotion_uses', 0);
        $this->assertDatabaseCount('quote_pricings', 0);
    }

    public function test_real_csrf_and_shared_quote_creation_throttle_apply(): void
    {
        $quote = $this->quote();
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery {
            protected function runningUnitTests(): bool { return false; }
        });
        $this->assertPrivate($this->postPromotion($quote['id'])->assertStatus(419));
        $token = Str::random(40); $this->withSession(['_token' => $token]);
        $headers = ['HTTP_X_CSRF_TOKEN' => $token];
        $this->postPromotion($quote['id'], headers: $headers)->assertOk();
        for ($i = 0; $i < 8; $i++) { $this->postPromotion($quote['id'], '{}', $headers)->assertUnprocessable(); }
        $this->assertPrivate($this->postPromotion($quote['id'], headers: $headers)->assertStatus(429)->assertJsonPath('code', 'RATE_LIMITED'));
        $this->assertDatabaseCount('promotion_uses', 1);
    }

    public function test_production_rejection_and_unexpected_errors_keep_private_generic_responses(): void
    {
        $quote = $this->quote();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->app->instance('env', 'production');
        $this->assertPrivate($this->postPromotion($quote['id'])->assertStatus(503)->assertJsonPath('code', 'PROMOTION_UNAVAILABLE'));
        $this->assertDatabaseCount('promotion_campaigns', 0);
        config(['app.debug' => true]);
        $this->app->bind(PriceQuote::class, fn () => throw new RuntimeException('private account-secret master.wav'));
        $failed = $this->postPromotion($quote['id'])->assertStatus(500)->assertJsonPath('code', 'QUOTE_UNAVAILABLE');
        $failed->assertDontSee('account-secret')->assertDontSee('master.wav'); $this->assertPrivate($failed);
    }
}
