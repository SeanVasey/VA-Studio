<?php

namespace Tests\Feature;

use App\Domain\Catalog\DeactivateOffer;
use App\Domain\Commerce\Inventory\ManageRightsScope;
use App\Domain\Commerce\Models\InventoryReservation;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderAttempt;
use App\Domain\Commerce\Models\OrderLine;
use App\Domain\Commerce\Models\PromotionUse;
use App\Domain\Commerce\Models\Quote;
use App\Domain\Commerce\Orders\PrepareOrder;
use App\Domain\Commerce\Orders\ReadOrder;
use App\Domain\Commerce\Orders\ReviewOrder;
use App\Domain\Commerce\PriceQuote;
use App\Domain\Commerce\QuoteException;
use App\Domain\Commerce\ReservePricedQuote;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\InventoryFixtures;
use Tests\Support\OrderFixtures as F;
use Tests\Support\PricingFixtures;
use Tests\TestCase;

class OrderPreparationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp(); $this->withoutVite(); $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond()); F::configure();
    }

    private function rejected(array $codes, callable $operation, ?int $status = null): void
    {
        try { $operation(); $this->fail('Order operation unexpectedly succeeded.'); }
        catch (QuoteException $error) {
            $this->assertContains($error->errorCode, $codes);
            if ($status !== null) { $this->assertSame($status, $error->status); }
        }
    }

    private function prepare(array $f, ?array $request = null, ?string $key = null): Order
    {
        return app(PrepareOrder::class)->handle(InventoryFixtures::OWNER, $key ?? (string) Str::uuid(), $request ?? F::request($f['quote']));
    }

    private function assertPrivate(TestResponse $response): void
    {
        $response->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Vary', 'Cookie')->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    private function httpQuote(): Quote
    {
        $f = InventoryFixtures::selection();
        $id = $this->postJson('/quotes', ['items' => $f['items']], ['Idempotency-Key' => (string) Str::uuid()])->assertOk()->json('quote.id');
        $this->call('POST', '/quotes/'.$id.'/pricing', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{}')->assertOk();

        return Quote::where('public_id', $id)->sole();
    }

    public static function kinds(): array { return [[false, false], [false, true], [true, false], [true, true]]; }

    #[DataProvider('kinds')]
    public function test_exact_priced_review_and_assent_commit_one_private_order_and_one_pending_attempt(bool $exclusive, bool $promoted): void
    {
        $f = F::priced($exclusive, $promoted); $request = F::request($f['quote']);
        // Media fixtures queue their own processing jobs; measure only order work below.
        Queue::fake();
        $review = app(ReviewOrder::class)->handle($f['quote']->public_id, InventoryFixtures::OWNER);
        $digest = $review['reviewHash']; unset($review['reviewHash']);
        $this->assertSame(CanonicalJson::hash($review), $digest);
        $this->assertSame('Synthetic Seller', $review['sellerName']);
        $this->assertSame(F::policy()['assent'], $review['assent']);
        $this->assertSame($exclusive ? 3 : ($promoted ? 2 : 1), $review['pricing']['pricingSchema']);
        $this->assertCount(1, $review['items']);
        $disclosure = $review['items'][0]['disclosure']; $disclosureHash = $disclosure['disclosureHash']; unset($disclosure['disclosureHash']);
        $this->assertSame(CanonicalJson::hash($disclosure), $disclosureHash);
        $this->assertFalse($review['payable']); $this->assertTrue($review['testOnly']);
        $order = $this->prepare($f, $request);
        $status = app(ReadOrder::class)->handle($order->public_id, InventoryFixtures::OWNER);
        $this->assertSame('prepared', $status['status']); $this->assertSame('not_started', $status['paymentStatus']);
        $this->assertSame($request['reviewHash'], $status['reviewHash']);
        $this->assertSame($f['pricing']->snapshot['total_minor'], $status['totalMinor']);
        $this->assertSame($exclusive ? ($promoted ? 132178 : 132715) : ($promoted ? 4836 : 5374), $status['totalMinor']);
        $this->assertSame('USD', $status['currency']); $this->assertFalse($status['payable']); $this->assertTrue($status['testOnly']);
        $this->assertSame($status, app(ReadOrder::class)->forQuote($f['quote']->public_id, InventoryFixtures::OWNER));
        $this->assertDatabaseCount('orders', 1); $this->assertDatabaseCount('order_lines', 1); $this->assertDatabaseCount('order_attempts', 1);
        $line = OrderLine::sole(); $attempt = OrderAttempt::sole(); $reservation = InventoryReservation::sole();
        $this->assertSame($order->id, $line->order_id); $this->assertSame($f['revision']->id, $line->offer_revision_id);
        $this->assertSame($f['quote']->lines()->sole()->id, $line->quote_line_id);
        $this->assertSame($order->id, $attempt->order_id); $this->assertTrue(Str::isUuid($attempt->public_id));
        $this->assertSame($attempt->public_id, $reservation->attempt_id); $this->assertSame('pending', $reservation->state);
        if ($promoted) {
            $this->assertSame($attempt->public_id, PromotionUse::sole()->attempt_id); $this->assertSame('pending', PromotionUse::sole()->state);
        } else { $this->assertDatabaseCount('promotion_uses', 0); }
        $payload = app(ReadOrder::class)->verify($order);
        $this->assertSame(CanonicalJson::encode($request), CanonicalJson::encode($payload['request']));
        $this->assertSame('unverified_guest', $payload['policy']['buyer_identity']);
        $this->assertSame(CanonicalJson::encode($f['quote']->snapshot), CanonicalJson::encode($payload['quote']['snapshot']));
        $this->assertSame(CanonicalJson::encode($f['pricing']->snapshot), CanonicalJson::encode($payload['pricing']['snapshot']));
        $this->assertSame($f['quote']->snapshot_hash, $payload['quote']['snapshot_hash']);
        $this->assertSame($f['pricing']->snapshot_hash, $payload['pricing']['snapshot_hash']);
        $this->assertSame($request['reviewHash'], $payload['review']['reviewHash']);
        $this->assertSame($disclosureHash, $payload['lines'][0]['disclosure']['disclosureHash']);
        $this->assertSame(CanonicalJson::hash($payload['lines'][0]), $line->line_hash);
        $this->assertSame(CanonicalJson::hash($payload['attempt']), $attempt->binding_hash);
        $this->assertSame(CanonicalJson::encode($payload['attempt']), CanonicalJson::encode($attempt->binding));
        $this->assertSame($attempt->public_id, $payload['attempt_id']);
        $plaintext = Crypt::decryptString($order->payload_ciphertext);
        $this->assertSame(hash('sha256', $order->payload_ciphertext), $order->payload_hash);
        $this->assertNotSame(CanonicalJson::hash($payload), $order->payload_hash);
        $this->assertSame(CanonicalJson::encode($payload), $plaintext);
        $this->assertStringContainsString(F::buyer()['legalName'], $plaintext); $this->assertStringContainsString(F::buyer()['email'], $plaintext);
        foreach ([F::buyer()['legalName'], F::buyer()['email']] as $private) {
            foreach (['orders', 'order_lines', 'order_attempts', 'audit_events'] as $table) {
                $this->assertStringNotContainsString($private, json_encode(DB::table($table)->get(), JSON_THROW_ON_ERROR));
            }
            $this->assertStringNotContainsString($private, json_encode($status, JSON_THROW_ON_ERROR));
        }
        foreach (['payload_ciphertext', 'payload_hash', 'owner_key', 'idempotency_key_hash'] as $private) {
            $this->assertArrayNotHasKey($private, $order->toArray());
        }
        Queue::assertNothingPushed(); $this->assertDatabaseCount('stripe_webhook_receipts', 0);
        $this->postJson('/checkout', ['orderId' => $order->public_id])->assertStatus(503)->assertJsonPath('code', 'COMMERCE_NOT_ENABLED');
    }

    public function test_review_is_read_only_and_does_not_create_inventory_or_assent(): void
    {
        $f = F::priced(); $before = DB::table('audit_events')->count();
        $review = app(ReviewOrder::class)->handle($f['quote']->public_id, InventoryFixtures::OWNER);
        $this->assertSame($review, app(ReviewOrder::class)->handle($f['quote']->public_id, InventoryFixtures::OWNER));
        foreach (['orders', 'order_lines', 'order_attempts', 'inventory_reservations', 'inventory_claims'] as $table) { $this->assertDatabaseCount($table, 0); }
        $this->assertSame($before, DB::table('audit_events')->count());
        $this->assertDatabaseCount('quote_pricings', 1);
    }

    public function test_replay_and_owned_status_retain_exact_evidence_after_expiry_configuration_and_publication_change(): void
    {
        $f = F::priced(true, true); $request = F::request($f['quote']); $key = (string) Str::uuid();
        $order = $this->prepare($f, $request, $key); $before = $order->refresh()->getAttributes();
        $status = app(ReadOrder::class)->handle($order->public_id, InventoryFixtures::OWNER);
        $reservation = InventoryReservation::sole()->getAttributes(); $promotion = PromotionUse::sole()->getAttributes();
        app(DeactivateOffer::class)->handle($f['offer'], $f['actor']);
        app(ManageRightsScope::class)->block($f['scope']->id, true, 0, 'POST-ORDER-SYNTHETIC-BLOCK', $f['actor']);
        $audits = DB::table('audit_events')->count();
        $this->travelTo($f['quote']->expires_at->addDay());
        config(['commerce.test_order_policy' => null, 'commerce.test_pricing_policy' => null,
            'commerce.test_inventory_policy' => null, 'commerce.test_exclusive_selection_policy' => null, 'commerce.test_promotions' => null]);
        $this->app->instance('env', 'production');
        $replayed = $this->prepare($f, $request, $key);
        $this->assertSame($before, $replayed->getAttributes());
        $this->assertSame($status, app(ReadOrder::class)->handle($order->public_id, InventoryFixtures::OWNER));
        $this->assertSame($status, app(ReadOrder::class)->forQuote($f['quote']->public_id, InventoryFixtures::OWNER));
        $this->assertSame($reservation, InventoryReservation::sole()->getAttributes());
        $this->assertSame($promotion, PromotionUse::sole()->getAttributes());
        $this->assertSame($audits, DB::table('audit_events')->count());
        $this->assertDatabaseCount('orders', 1); $this->assertDatabaseCount('order_attempts', 1);
    }

    public function test_idempotency_is_owned_and_rejects_changed_identity_assent_and_another_key_for_the_quote(): void
    {
        $f = F::priced(); $request = F::request($f['quote']); $key = (string) Str::uuid(); $this->prepare($f, $request, $key);
        $changed = $request; $changed['buyer']['email'] = 'changed@example.invalid';
        $this->rejected(['IDEMPOTENCY_CONFLICT'], fn () => $this->prepare($f, $changed, $key), 409);
        $changed = $request; $changed['buyer']['legalName'] = 'Changed Synthetic Buyer';
        $this->rejected(['IDEMPOTENCY_CONFLICT'], fn () => $this->prepare($f, $changed, $key), 409);
        $changed = $request; $changed['reviewHash'] = str_repeat('b', 64);
        $this->rejected(['IDEMPOTENCY_CONFLICT'], fn () => $this->prepare($f, $changed, $key), 409);
        $this->rejected(['ORDER_ALREADY_PREPARED'], fn () => $this->prepare($f, $request), 409);
        $this->rejected(['QUOTE_NOT_FOUND', 'ORDER_NOT_FOUND'], fn () => app(PrepareOrder::class)->handle(str_repeat('b', 64), $key, $request), 404);
        $this->assertDatabaseCount('orders', 1); $this->assertDatabaseCount('order_attempts', 1);
    }

    public static function unavailablePolicies(): array
    {
        return [['missing'], ['malformed'], ['extra'], ['buyer_identity'], ['environment']];
    }

    #[DataProvider('unavailablePolicies')]
    public function test_no_order_is_created_without_a_valid_explicit_test_policy(string $scenario): void
    {
        $f = F::priced(); $request = F::request($f['quote']); $policy = F::policy();
        if ($scenario === 'extra') { $policy['production'] = true; }
        if ($scenario === 'buyer_identity') { $policy['buyer_identity'] = 'verified'; }
        config(['commerce.test_order_policy' => match ($scenario) {
            'missing' => null, 'malformed' => '{broken', default => json_encode($policy, JSON_THROW_ON_ERROR),
        }]);
        if ($scenario === 'environment') { $this->app->instance('env', 'production'); }
        $this->rejected(['ORDER_POLICY_UNAVAILABLE'], fn () => $this->prepare($f, $request), 503);
        foreach (['orders', 'order_lines', 'order_attempts', 'inventory_reservations'] as $table) { $this->assertDatabaseCount($table, 0); }
    }

    public static function unknownTaxes(): array { return [[null], ['provider_calculated']]; }

    #[DataProvider('unknownTaxes')]
    public function test_unknown_or_provider_pending_totals_cannot_become_an_order(?string $mode): void
    {
        PricingFixtures::configure($mode === null ? null : PricingFixtures::policy($mode)); $f = F::priced();
        $before = DB::table('audit_events')->count();
        $this->rejected(['ORDER_PRICING_UNAVAILABLE'], fn () => app(ReviewOrder::class)->handle($f['quote']->public_id, InventoryFixtures::OWNER), 409);
        $request = ['quoteId' => $f['quote']->public_id, 'reviewHash' => str_repeat('a', 64), 'buyer' => F::buyer(), 'accepted' => true];
        $this->rejected(['ORDER_PRICING_UNAVAILABLE'], fn () => $this->prepare($f, $request), 409);
        $this->assertDatabaseCount('orders', 0); $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertSame($before, DB::table('audit_events')->count());
    }

    public function test_policy_and_review_drift_require_fresh_explicit_assent(): void
    {
        $f = F::priced(); $request = F::request($f['quote']);
        $policy = F::policy(); $policy['assent']['text'] = 'Different synthetic terms.';
        config(['commerce.test_order_policy' => json_encode($policy, JSON_THROW_ON_ERROR)]);
        $this->rejected(['ORDER_REVIEW_CHANGED'], fn () => $this->prepare($f, $request), 409);
        $this->assertDatabaseCount('orders', 0); $this->assertDatabaseCount('inventory_reservations', 0);
        $fresh = F::request($f['quote']); $this->assertNotSame($request['reviewHash'], $fresh['reviewHash']);
        $this->prepare($f, $fresh); $this->assertDatabaseCount('orders', 1);
    }

    public static function malformedRequests(): array
    {
        return [['no_assent'], ['string_assent'], ['extra'], ['buyer_extra'], ['empty_name'], ['invalid_email'], ['name_control'], ['bad_hash'], ['bad_quote'], ['long_name']];
    }

    #[DataProvider('malformedRequests')]
    public function test_untrusted_order_fields_and_missing_explicit_assent_are_rejected(string $scenario): void
    {
        $f = F::priced(); $request = F::request($f['quote']);
        match ($scenario) {
            'no_assent' => $request['accepted'] = false,
            'string_assent' => $request['accepted'] = 'true',
            'extra' => $request['totalMinor'] = 1,
            'buyer_extra' => $request['buyer']['marketingConsent'] = true,
            'empty_name' => $request['buyer']['legalName'] = '',
            'invalid_email' => $request['buyer']['email'] = 'not-an-email',
            'name_control' => $request['buyer']['legalName'] = "Synthetic\nBuyer",
            'bad_hash' => $request['reviewHash'] = 'bad',
            'bad_quote' => $request['quoteId'] = 123,
            'long_name' => $request['buyer']['legalName'] = str_repeat('A', 301),
        };
        $this->rejected(['INVALID_ORDER_REQUEST'], fn () => $this->prepare($f, $request), 422);
        $this->assertDatabaseCount('orders', 0); $this->assertDatabaseCount('inventory_reservations', 0);
    }

    public static function insertionFailures(): array { return [['orders'], ['order_lines'], ['order_attempts'], ['audit_events']]; }

    #[DataProvider('insertionFailures')]
    public function test_failed_order_or_audit_insert_rolls_back_pending_inventory_and_promotion_transitions(string $table): void
    {
        $f = F::priced(false, true); $request = F::request($f['quote']);
        $before = DB::table('audit_events')->count(); $failed = false;
        DB::connection()->beforeExecuting(function (string $query, array $bindings) use ($table, &$failed): void {
            if ($table === 'audit_events' && ! in_array('commerce.order.prepared', $bindings, true)) { return; }
            if (! $failed && preg_match('/\Ainsert into ["`]'.preg_quote($table, '/').'["`]/i', $query)) {
                $failed = true; throw new RuntimeException('Synthetic aggregate insertion failure.');
            }
        });
        try { $this->prepare($f, $request); $this->fail('Expected transactional failure.'); }
        catch (RuntimeException $error) { $this->assertSame('Synthetic aggregate insertion failure.', $error->getMessage()); }
        $this->assertTrue($failed);
        foreach (['orders', 'order_lines', 'order_attempts', 'inventory_reservations', 'inventory_claims'] as $name) { $this->assertDatabaseCount($name, 0); }
        $this->assertSame('held', PromotionUse::sole()->state); $this->assertNull(PromotionUse::sole()->attempt_id);
        $this->assertSame($before, DB::table('audit_events')->count());
        $this->assertDatabaseCount('quote_pricings', 1);
        $this->prepare($f, $request); $this->assertDatabaseCount('orders', 1); $this->assertSame('pending', PromotionUse::sole()->state);
    }

    public function test_failed_order_insert_restores_preexisting_exclusive_and_promotion_holds_exactly(): void
    {
        $f = F::priced(true, true); $request = F::request($f['quote']);
        $reservation = InventoryReservation::sole()->getAttributes(); $use = PromotionUse::sole()->getAttributes();
        $before = DB::table('audit_events')->count(); $failed = false;
        DB::connection()->beforeExecuting(function (string $query) use (&$failed): void {
            if (! $failed && preg_match('/\Ainsert into ["`]order_attempts["`]/i', $query)) {
                $failed = true; throw new RuntimeException('Synthetic order attempt failure.');
            }
        });
        try { $this->prepare($f, $request); $this->fail('Expected order attempt insertion failure.'); }
        catch (RuntimeException $error) { $this->assertSame('Synthetic order attempt failure.', $error->getMessage()); }
        $this->assertTrue($failed); $this->assertDatabaseCount('orders', 0);
        $this->assertSame($reservation, InventoryReservation::sole()->getAttributes()); $this->assertSame($use, PromotionUse::sole()->getAttributes());
        $this->assertSame($before, DB::table('audit_events')->count());
    }

    public function test_expiry_during_aggregate_writes_leaves_no_order_or_pending_resources(): void
    {
        $f = F::priced(); $request = F::request($f['quote']); $before = DB::table('audit_events')->count(); $advanced = false;
        DB::connection()->beforeExecuting(function (string $query) use (&$advanced, $f): void {
            if (! $advanced && preg_match('/\Ainsert into ["`]orders["`]/i', $query)) {
                $advanced = true; $this->travelTo($f['pricing']->expires_at);
            }
        });
        $this->rejected(['PRICING_EXPIRED', 'QUOTE_EXPIRED', 'INVENTORY_EXPIRED'], fn () => $this->prepare($f, $request));
        $this->assertTrue($advanced); $this->assertSame($before, DB::table('audit_events')->count());
        foreach (['orders', 'order_lines', 'order_attempts', 'inventory_reservations', 'inventory_claims'] as $table) { $this->assertDatabaseCount($table, 0); }
    }

    public static function immutableOperations(): array { return [['orm_update'], ['orm_delete'], ['sql_update'], ['sql_delete']]; }

    #[DataProvider('immutableOperations')]
    public function test_order_line_and_attempt_records_are_immutable_at_model_and_database_boundaries(string $operation): void
    {
        $f = F::priced(); $order = $this->prepare($f);
        foreach ([$order, OrderLine::sole(), OrderAttempt::sole()] as $model) {
            // Compare persisted representations, including database column/key ordering.
            $before = $model->refresh()->getAttributes();
            try {
                match ($operation) {
                    'orm_update' => $model->forceFill($model instanceof OrderLine
                        ? ['position' => $model->position + 1] : ['created_at' => now()->addDay()])->save(),
                    'orm_delete' => $model->delete(),
                    'sql_update' => DB::table($model->getTable())->where('id', $model->id)->update(['id' => $model->id]),
                    'sql_delete' => DB::table($model->getTable())->where('id', $model->id)->delete(),
                };
                $this->fail('Immutable '.$model->getTable().' evidence was changed.');
            } catch (LogicException|QueryException $error) {
                $this->assertInstanceOf(str_starts_with($operation, 'orm_') ? LogicException::class : QueryException::class, $error);
                $this->assertSame($before, $model->fresh()->getAttributes());
            }
        }
    }

    public static function evidenceTampering(): array { return [['ciphertext'], ['hash'], ['canonicalization'], ['quote_binding']]; }

    #[DataProvider('evidenceTampering')]
    public function test_private_snapshot_verification_fails_closed_on_tampering(string $scenario): void
    {
        $f = F::priced(); $order = $this->prepare($f); $storedHash = $order->payload_hash;
        if ($scenario === 'ciphertext') { $order->payload_ciphertext = 'corrupted'; }
        if ($scenario === 'hash') { $order->payload_hash = str_repeat('f', 64); }
        if ($scenario === 'canonicalization') { $order->canonicalization_version = 'unknown'; }
        if ($scenario === 'quote_binding') { $order->quote_id = $order->quote_id + 1; }
        $this->rejected(['ORDER_UNAVAILABLE', 'ORDER_CHANGED'], fn () => app(ReadOrder::class)->verify($order));
        $this->assertSame($storedHash, $order->refresh()->payload_hash);
        $this->assertSame('pending', InventoryReservation::sole()->state);
    }

    public function test_owned_http_review_preparation_and_status_are_private_and_never_expose_buyer_data(): void
    {
        $quote = $this->httpQuote(); $review = $this->getJson('/quotes/'.$quote->public_id.'/order-review')->assertOk();
        $this->assertPrivate($review); $this->assertDatabaseCount('orders', 0);
        $request = ['quoteId' => $quote->public_id, 'reviewHash' => $review->json('review.reviewHash'), 'buyer' => F::buyer(), 'accepted' => true];
        $key = (string) Str::uuid(); $created = $this->postJson('/orders', $request, ['Idempotency-Key' => $key])->assertOk();
        $this->assertPrivate($created); $id = $created->json('order.id');
        $created->assertJsonPath('order.paymentStatus', 'not_started')->assertJsonPath('order.payable', false);
        $this->postJson('/orders', $request, ['Idempotency-Key' => $key])->assertOk()->assertExactJson($created->json());
        $before = DB::table('audit_events')->count();
        $this->getJson('/orders/'.$id.'/status')->assertOk()->assertExactJson($created->json());
        $this->getJson('/quotes/'.$quote->public_id.'/order')->assertOk()->assertExactJson($created->json());
        $this->assertSame($before, DB::table('audit_events')->count());
        foreach ([...array_values(F::buyer()), $quote->owner_key, Order::sole()->payload_hash, OrderAttempt::sole()->public_id] as $private) {
            $created->assertDontSee($private, false);
        }
        $this->flushSession();
        $foreign = $this->getJson('/orders/'.$id.'/status')->assertNotFound()->assertJsonPath('code', 'ORDER_NOT_FOUND');
        $unknown = $this->getJson('/orders/'.Str::uuid().'/status')->assertNotFound();
        $this->assertSame($unknown->json(), $foreign->json()); $this->assertPrivate($foreign);
        $this->getJson('/quotes/'.$quote->public_id.'/order')->assertNotFound();
        $this->getJson('/quotes/'.$quote->public_id.'/order-review')->assertNotFound();
        $this->postJson('/orders', $request, ['Idempotency-Key' => $key])->assertNotFound();
    }

    public function test_authentication_context_change_invalidates_guest_order_access(): void
    {
        $quote = $this->httpQuote(); $request = F::request($quote, $quote->owner_key);
        $id = $this->postJson('/orders', $request, ['Idempotency-Key' => (string) Str::uuid()])->assertOk()->json('order.id');
        $this->actingAs(User::factory()->create())->getJson('/orders/'.$id.'/status')->assertNotFound();
        $this->getJson('/quotes/'.$quote->public_id.'/order')->assertNotFound();
    }

    public function test_order_creation_requires_real_csrf_and_accepts_only_the_declared_json_contract(): void
    {
        $quote = $this->httpQuote(); $request = F::request($quote, $quote->owner_key); $key = (string) Str::uuid();
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery {
            protected function runningUnitTests(): bool { return false; }
        });
        $this->assertPrivate($this->postJson('/orders', $request, ['Idempotency-Key' => $key])->assertStatus(419));
        $token = Str::random(40); $this->withSession(['_token' => $token]);
        $headers = ['Idempotency-Key' => $key, 'X-CSRF-TOKEN' => $token];
        $this->postJson('/orders?totalMinor=1', $request, $headers)->assertUnprocessable();
        $this->post('/orders', $request, $headers)->assertUnprocessable();
        $this->postJson('/orders', $request, ['X-CSRF-TOKEN' => $token])->assertUnprocessable();
        $this->call('POST', '/orders', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => $key, 'HTTP_X_CSRF_TOKEN' => $token], '{broken')->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
        $this->postJson('/orders', $request, $headers)->assertOk();
    }


    public function test_missing_persisted_pricing_cannot_be_created_implicitly_by_order_review(): void
    {
        $f = InventoryFixtures::selection(); $before = DB::table('audit_events')->count();
        $this->rejected(['PRICING_NOT_FOUND'], fn () => app(ReviewOrder::class)->handle($f['quote']->public_id, InventoryFixtures::OWNER), 404);
        $request = ['quoteId' => $f['quote']->public_id, 'reviewHash' => str_repeat('a', 64), 'buyer' => F::buyer(), 'accepted' => true];
        $this->rejected(['PRICING_NOT_FOUND'], fn () => $this->prepare($f, $request), 404);
        foreach (['orders', 'quote_pricings', 'inventory_reservations'] as $table) { $this->assertDatabaseCount($table, 0); }
        $this->assertSame($before, DB::table('audit_events')->count());
    }

    public function test_fresh_order_rehashes_deliverables_even_after_a_successful_cached_review(): void
    {
        $f = F::priced(); $request = F::request($f['quote']);
        $asset = $f['media']['master_wav'];
        $path = \Illuminate\Support\Facades\Storage::disk('local')->path($asset->storage_path);
        $bytes = file_get_contents($path); $mtime = filemtime($path);
        $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) ^ 1);
        $this->assertTrue(chmod($path, 0600));
        try {
            $this->assertSame(strlen($bytes), file_put_contents($path, $bytes));
            $this->assertTrue(touch($path, $mtime));
        } finally { chmod($path, 0440); }
        clearstatcache(true, $path);
        $before = DB::table('audit_events')->count();
        $this->rejected(['SELECTION_CHANGED'], fn () => $this->prepare($f, $request), 409);
        foreach (['orders', 'order_lines', 'order_attempts', 'inventory_reservations'] as $table) { $this->assertDatabaseCount($table, 0); }
        $this->assertSame($before, DB::table('audit_events')->count());
    }

    public function test_a_prior_unbound_pending_attempt_cannot_be_adopted_as_a_new_order(): void
    {
        $f = F::priced(false, true); $request = F::request($f['quote']);
        $service = app(ReservePricedQuote::class); $service->hold($f['quote']->public_id, InventoryFixtures::OWNER, 'SYNTHETIC');
        $attempt = (string) Str::uuid(); $service->beginAttempt($f['quote']->public_id, InventoryFixtures::OWNER, $attempt);
        $reservation = InventoryReservation::sole()->getAttributes(); $promotion = PromotionUse::sole()->getAttributes();
        $before = DB::table('audit_events')->count();
        $this->rejected(['PROMOTION_ATTEMPT_CONFLICT', 'INVENTORY_ATTEMPT_CONFLICT'], fn () => $this->prepare($f, $request), 409);
        $this->assertDatabaseCount('orders', 0); $this->assertDatabaseCount('order_attempts', 0);
        $this->assertSame($reservation, InventoryReservation::sole()->getAttributes()); $this->assertSame($promotion, PromotionUse::sole()->getAttributes());
        $this->assertSame($before, DB::table('audit_events')->count());
    }

    public function test_order_routes_keep_the_existing_mutation_and_read_rate_limits(): void
    {
        for ($index = 0; $index < 10; $index++) { $this->postJson('/quotes', [])->assertUnprocessable(); }
        $limited = $this->postJson('/orders', [], ['Idempotency-Key' => 'synthetic-key'])->assertStatus(429)->assertHeader('Retry-After');
        $this->assertPrivate($limited);
        for ($index = 0; $index < 60; $index++) { $this->getJson('/quotes/'.Str::uuid())->assertNotFound(); }
        $this->assertPrivate($this->getJson('/orders/'.Str::uuid().'/status')->assertStatus(429));
        $this->assertPrivate($this->getJson('/quotes/'.Str::uuid().'/order-review')->assertStatus(429));
        $this->assertDatabaseCount('orders', 0);
    }


    public function test_storefront_order_flag_is_disabled_without_policy_and_in_production_and_exposes_no_private_policy(): void
    {
        config(['commerce.test_order_policy' => null]);
        $disabled = $this->get('/', ['X-Inertia' => 'true'])->assertOk()
            ->assertJsonPath('props.testOrderPreparationEnabled', false)->assertJsonPath('props.commerceEnabled', false);
        F::configure();
        $enabled = $this->get('/', ['X-Inertia' => 'true'])->assertOk()
            ->assertJsonPath('props.testOrderPreparationEnabled', true)->assertJsonPath('props.commerceEnabled', false);
        $this->app->instance('env', 'production');
        $production = $this->get('/', ['X-Inertia' => 'true'])->assertOk()
            ->assertJsonPath('props.testOrderPreparationEnabled', false)->assertJsonPath('props.commerceEnabled', false);
        foreach ([$disabled, $enabled, $production] as $response) {
            foreach ([...array_values(F::buyer()), F::policy()['seller']['legal_name'], F::policy()['assent']['text'], 'buyer_identity', 'payload_ciphertext'] as $private) {
                $response->assertDontSee($private, false);
            }
        }
        foreach (['quotes', 'orders', 'order_attempts', 'inventory_reservations', 'audit_events'] as $table) { $this->assertDatabaseCount($table, 0); }
    }

    public function test_unexpected_order_errors_are_generic_even_with_debug_and_do_not_log_buyer_identity(): void
    {
        config(['app.debug' => true]); Log::spy();
        $this->app->bind(PrepareOrder::class, fn () => throw new RuntimeException('secret buyer@example.invalid private/master.wav'));
        $response = $this->postJson('/orders', [], ['Idempotency-Key' => 'synthetic-key'])->assertStatus(500);
        $this->assertPrivate($response);
        foreach (['buyer@example.invalid', 'master.wav', 'trace'] as $private) { $response->assertDontSee($private, false); }
        Log::shouldHaveReceived('error')->once()->with('Order request failed.', ['exception_class' => RuntimeException::class]);
        $this->assertDatabaseCount('orders', 0);
    }
}
