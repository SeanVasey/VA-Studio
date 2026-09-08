<?php

namespace Tests\Feature;

use App\Domain\Catalog\PublishOffer;
use App\Domain\Catalog\SaveOfferDraft;
use App\Domain\Commerce\CreateQuote;
use App\Domain\Commerce\Models\Quote;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class QuoteHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
    }

    private function create(array $items, ?string $key = null): TestResponse
    {
        return $this->postJson('/quotes', ['items' => $items], ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    private function assertPrivate(TestResponse $response): void
    {
        $response->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertContains('Cookie', $response->headers->all('Vary') === [] ? [] : array_map('trim', explode(',', $response->headers->get('Vary'))));
    }

    public function test_guest_can_create_replay_and_read_only_a_safe_nonpayable_projection(): void
    {
        $selection = QuoteFixtures::selection();
        $key = (string) Str::uuid();
        $response = $this->create($selection['items'], $key)->assertOk();
        $this->assertPrivate($response);
        $quote = $response->json('quote');
        $this->assertSame(['id', 'expiresAt', 'currency', 'subtotalMinor', 'taxMinor', 'totalMinor', 'taxStatus', 'payable', 'items'], array_keys($quote));
        $this->assertTrue(Str::isUuid($quote['id']));
        $this->assertSame('USD', $quote['currency']);
        $this->assertSame($selection['revision']->price_minor, $quote['subtotalMinor']);
        $this->assertNull($quote['taxMinor']);
        $this->assertNull($quote['totalMinor']);
        $this->assertSame('unresolved', $quote['taxStatus']);
        $this->assertFalse($quote['payable']);
        $this->assertSame(['trackId', 'offerId', 'offerRevisionId', 'licenseVersionId', 'title', 'artist', 'licenseName', 'priceMinor', 'currency', 'deliverableRoles', 'features'], array_keys($quote['items'][0]));
        $this->assertSame(['master_wav'], $quote['items'][0]['deliverableRoles']);
        $stored = Quote::sole();
        foreach ([$stored->owner_key, $stored->snapshot_hash, $selection['revision']->snapshot_hash, $selection['media']['master_wav']->sha256, $selection['media']['master_wav']->storage_path, $selection['revision']->snapshot['license']['authored_source']] as $private) {
            $response->assertDontSee($private, false);
        }
        $this->create($selection['items'], $key)->assertOk()->assertExactJson($response->json());
        $read = $this->getJson('/quotes/'.$quote['id'])->assertOk()->assertExactJson($response->json());
        $this->assertPrivate($read);
        $this->assertDatabaseCount('quotes', 1);
        $this->postJson('/checkout', ['quoteId' => $quote['id']])->assertStatus(503)->assertJsonPath('code', 'COMMERCE_NOT_ENABLED');
    }

    public function test_another_session_receives_the_same_not_found_response_as_an_unknown_quote(): void
    {
        $selection = QuoteFixtures::selection();
        $id = $this->create($selection['items'])->assertOk()->json('quote.id');
        $this->flushSession();
        $foreign = $this->getJson('/quotes/'.$id)->assertNotFound()->assertJsonPath('code', 'QUOTE_NOT_FOUND');
        $unknown = $this->getJson('/quotes/'.Str::uuid())->assertNotFound();
        $this->assertSame($unknown->json(), $foreign->json());
        $this->assertPrivate($foreign);
    }

    public function test_authentication_context_change_invalidates_guest_and_previous_user_quotes(): void
    {
        $selection = QuoteFixtures::selection();
        $guest = $this->create($selection['items'])->assertOk()->json('quote.id');
        $first = User::factory()->create();
        $this->actingAs($first)->getJson('/quotes/'.$guest)->assertNotFound();
        $authenticated = $this->create($selection['items'])->assertOk()->json('quote.id');
        $this->actingAs(User::factory()->create())->getJson('/quotes/'.$authenticated)->assertNotFound();
        $this->actingAs($first)->getJson('/quotes/'.$authenticated)->assertNotFound();
    }

    public function test_login_and_logout_invalidate_ownership_without_an_intervening_quote_request(): void
    {
        $selection = QuoteFixtures::selection();
        $guest = $this->create($selection['items'])->assertOk()->json('quote.id');
        // Real guard transitions dispatch the same lifecycle events as authentication.
        Auth::login(User::factory()->create());
        Auth::logout();
        $this->getJson('/quotes/'.$guest)->assertNotFound();
    }

    public function test_extra_price_owner_or_line_fields_are_rejected_without_creating_quotes(): void
    {
        $selection = QuoteFixtures::selection();
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        foreach ([
            ['items' => $selection['items'], 'subtotalMinor' => 1],
            ['items' => $selection['items'], 'ownerKey' => str_repeat('a', 64)],
            ['items' => [array_merge($selection['items'][0], ['priceMinor' => 1])]],
            ['items' => [array_merge($selection['items'][0], ['trackId' => true])]],
            ['items' => [array_merge($selection['items'][0], ['trackId' => '01'])]],
            ['items' => array_fill(0, 11, $selection['items'][0])],
        ] as $payload) {
            $response = $this->postJson('/quotes', $payload, $headers)->assertUnprocessable()->assertJsonPath('code', 'INVALID_QUOTE_REQUEST');
            $this->assertPrivate($response);
        }
        $this->assertDatabaseCount('quotes', 0);
    }

    public function test_missing_keys_invalid_json_and_form_or_query_overrides_are_rejected(): void
    {
        $items = [['trackId' => 1, 'offerId' => 1, 'licenseVersionId' => 1, 'offerRevisionId' => 1]];
        $this->postJson('/quotes', ['items' => $items])->assertUnprocessable();
        $this->create($items, 'has spaces')->assertUnprocessable();
        $this->create($items, str_repeat('a', 129))->assertUnprocessable();
        $this->call('POST', '/quotes', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'valid-key'], '{broken')->assertUnprocessable();
        $this->post('/quotes', ['items' => $items], ['Idempotency-Key' => 'valid-key'])->assertUnprocessable();
        $this->postJson('/quotes?ownerKey=forged', ['items' => $items], ['Idempotency-Key' => 'valid-key'])->assertUnprocessable();
        $this->assertDatabaseCount('quotes', 0);
    }

    public function test_stale_selections_and_expiration_have_explicit_private_restart_responses(): void
    {
        $selection = QuoteFixtures::selection();
        $key = (string) Str::uuid();
        $id = $this->create($selection['items'], $key)->assertOk()->json('quote.id');
        app(SaveOfferDraft::class)->handle($selection['offer'], ['price_minor' => $selection['offer']->price_minor + 100], $selection['actor']);
        $revision = app(PublishOffer::class)->handle($selection['offer'], $selection['actor']);
        $stale = $this->create($selection['items'])->assertConflict()->assertJsonPath('code', 'SELECTION_CHANGED');
        $this->assertPrivate($stale);
        $changed = $selection['items'];
        $changed[0]['offerRevisionId'] = $revision->id;
        $this->create($changed, $key)->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_CONFLICT');
        $this->travelTo(Quote::sole()->expires_at);
        $expired = $this->getJson('/quotes/'.$id)->assertStatus(410)->assertJsonPath('code', 'QUOTE_EXPIRED');
        $this->assertPrivate($expired);
    }

    public function test_real_csrf_middleware_rejects_a_missing_token_and_accepts_a_valid_session_token(): void
    {
        $selection = QuoteFixtures::selection();
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        $blocked = $this->create($selection['items'])->assertStatus(419)->assertJsonPath('code', 'SESSION_EXPIRED');
        $this->assertPrivate($blocked);
        $this->assertDatabaseCount('quotes', 0);
        $token = Str::random(40);
        $this->withSession(['_token' => $token])->postJson('/quotes', ['items' => $selection['items']], [
            'Idempotency-Key' => (string) Str::uuid(), 'X-CSRF-TOKEN' => $token,
        ])->assertOk();
    }

    public function test_create_and_read_have_independent_rate_limits_and_private_failures(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->create([])->assertUnprocessable();
        }
        $limited = $this->create([])->assertStatus(429)->assertJsonPath('code', 'RATE_LIMITED')->assertHeader('Retry-After');
        $this->assertPrivate($limited);
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/quotes/'.Str::uuid())->assertNotFound();
        }
        $this->assertPrivate($this->getJson('/quotes/'.Str::uuid())->assertStatus(429)->assertJsonPath('code', 'RATE_LIMITED'));
    }

    public function test_unexpected_failures_never_expose_debug_details_and_keep_private_headers(): void
    {
        config(['app.debug' => true]);
        $this->app->bind(CreateQuote::class, fn () => throw new RuntimeException('private/master.wav owner-secret-test-marker'));
        $response = $this->create([])->assertStatus(500)->assertExactJson(['code' => 'QUOTE_UNAVAILABLE', 'message' => 'Selection review is temporarily unavailable. Try again later.']);
        $response->assertDontSee('owner-secret-test-marker')->assertDontSee('master.wav')->assertDontSee('trace');
        $this->assertPrivate($response);
    }
}
