<?php

namespace Tests\Feature;

use App\Domain\Commerce\Checkout\HostedCheckout;
use App\Domain\Commerce\Models\CheckoutIntent;
use App\Domain\Commerce\Models\CheckoutObservation;
use App\Domain\Commerce\Models\CheckoutSession;
use App\Domain\Commerce\Models\InventoryReservation;
use App\Domain\Commerce\Models\OrderAttempt;
use App\Domain\Commerce\Orders\ReadOrder;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\QuoteException;
use App\Domain\Commerce\PriceQuote;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\CheckoutFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\InventoryFixtures;
use Tests\Support\OrderFixtures;
use Tests\Support\PricingFixtures;
use Tests\TestCase;

class HostedCheckoutTest extends TestCase
{
    // Provider I/O must have no enclosing transaction, including one introduced by the test harness.
    use FinalizationDatabaseMigrations;

    private StripeCheckoutGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp(); $this->withoutVite(); $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond()); F::configure();
        $this->gateway = F::gateway(); $this->app->instance(StripeCheckoutGateway::class, $this->gateway);
    }

    private function startCheckout(array $f): array { return app(HostedCheckout::class)->start($f['order']->public_id, InventoryFixtures::OWNER); }
    private function checkoutStatus(array $f): array { return app(HostedCheckout::class)->status($f['order']->public_id, InventoryFixtures::OWNER); }
    private function reconcileCheckout(array $f): array { return app(HostedCheckout::class)->reconcile($f['order']->public_id, InventoryFixtures::OWNER); }
    private function creates(): array { return array_values(array_filter($this->gateway->calls, fn ($call) => $call['operation'] === 'create')); }

    private function rejected(string $code, callable $operation, ?int $status = null): void
    {
        try { $operation(); $this->fail('Checkout operation unexpectedly succeeded.'); }
        catch (QuoteException $error) { $this->assertSame($code, $error->errorCode); if ($status !== null) { $this->assertSame($status, $error->status); } }
    }

    private function retained(): array
    {
        $rows = [];
        foreach (['orders', 'order_lines', 'order_attempts', 'quotes', 'quote_lines', 'quote_pricings', 'inventory_reservations', 'inventory_claims', 'promotion_uses'] as $table) {
            $rows[$table] = json_encode(DB::table($table)->orderBy('id')->get(), JSON_THROW_ON_ERROR);
        }

        return $rows;
    }

    private function assertPrivate(TestResponse $response): void
    {
        $response->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Vary', 'Cookie')->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    private function postEmpty(string $url, string $body = '{}', array $headers = []): TestResponse
    {
        return $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', ...$headers], $body);
    }

    public static function kinds(): array { return [[false, false], [false, true], [true, false], [true, true]]; }

    #[DataProvider('kinds')]
    public function test_hosted_test_session_uses_frozen_discounted_lines_and_changes_no_payment_or_order_evidence(bool $exclusive, bool $promoted): void
    {
        $f = F::prepared($exclusive, $promoted); Queue::fake(); $before = $this->retained();
        $this->assertSame('not_started', $this->checkoutStatus($f)['status']); $this->assertCount(0, $this->gateway->calls);
        $result = $this->startCheckout($f);
        $this->assertSame('open', $result['status']); $this->assertTrue($result['testOnly']);
        $this->assertSame('not_verified', $result['paymentStatus']); $this->assertSame('not_started', $result['fulfillmentStatus']);
        $this->assertSame($exclusive ? ($promoted ? 122956 : 123456) : ($promoted ? 4499 : 4999), $result['totalMinor']);
        $this->assertSame('USD', $result['currency']); $this->assertSame($f['order']->public_id, $result['orderId']);
        $this->assertSame('https://checkout.stripe.com/c/pay/'.F::SESSION, $result['url']);
        $this->assertDatabaseCount('checkout_intents', 1); $this->assertDatabaseCount('checkout_sessions', 1);
        $calls = $this->creates(); $this->assertCount(1, $calls); $params = $calls[0]['params'];
        $this->assertSame(1, $calls[0]['intent_count']);
        foreach ($this->gateway->calls as $call) { $this->assertSame(0, $call['transaction_level']); }
        $this->assertSame('payment', $params['mode']); $this->assertSame(['card'], $params['payment_method_types']);
        $this->assertSame($result['totalMinor'], array_sum(array_map(fn ($line) => $line['price_data']['unit_amount'] * $line['quantity'], $params['line_items'])));
        $this->assertSame(1, $params['line_items'][0]['quantity']); $this->assertSame('usd', $params['line_items'][0]['price_data']['currency']);
        $this->assertSame($f['order']->public_id, $params['client_reference_id']);
        $this->assertSame(['order_id', 'attempt_id', 'intent_id', 'schema_version'], array_keys($params['metadata']));
        $this->assertSame($f['order']->public_id, $params['metadata']['order_id']);
        $this->assertSame(OrderAttempt::sole()->public_id, $params['metadata']['attempt_id']);
        $this->assertSame(CheckoutIntent::sole()->public_id, $params['metadata']['intent_id']);
        $this->assertSame('1', $params['metadata']['schema_version']);
        foreach (['customer_email', 'customer', 'discounts', 'shipping_options', 'shipping_address_collection'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $params);
        }
        $this->assertFalse($params['allow_promotion_codes']);
        $this->assertFalse($params['automatic_tax']['enabled']);
        $this->assertFalse($params['adaptive_pricing']['enabled']);
        $this->assertSame(now()->addHour()->timestamp, $params['expires_at']);
        $intent = CheckoutIntent::sole();
        $this->assertSame($calls[0]['key'], $intent->idempotency_key);
        $this->assertSame(hash('sha256', $intent->request_ciphertext), $intent->request_hash);
        $this->assertSame(hash('sha256', CheckoutSession::sole()->evidence_ciphertext), CheckoutSession::sole()->evidence_hash);
        $this->assertIsArray(json_decode(Crypt::decryptString($intent->request_ciphertext), true, 128, JSON_THROW_ON_ERROR));
        $safe = json_encode([$result, $params, CheckoutIntent::sole()->toArray(), CheckoutSession::sole()->toArray(), DB::table('audit_events')->get()], JSON_THROW_ON_ERROR);
        foreach ([...array_values(OrderFixtures::buyer()), config('payments.stripe.secret_key')] as $private) { $this->assertStringNotContainsString($private, $safe); }
        $this->assertSame($before, $this->retained());
        $audits = DB::table('audit_events')->count(); $observations = CheckoutObservation::count(); $callCount = count($this->gateway->calls);
        $this->assertSame($result, $this->checkoutStatus($f));
        $this->assertSame($callCount, count($this->gateway->calls)); $this->assertSame($audits, DB::table('audit_events')->count());
        $this->assertSame($observations, CheckoutObservation::count());
        $this->assertSame('prepared', app(ReadOrder::class)->handle($f['order']->public_id, InventoryFixtures::OWNER)['status']);
        Queue::assertNothingPushed(); $this->assertDatabaseCount('stripe_webhook_receipts', 0);
    }

    public function test_provider_create_observes_a_committed_intent_from_an_independent_mysql_connection(): void
    {
        if (DB::getDriverName() !== 'mysql') { $this->markTestSkipped('Independent durable-intent visibility requires MySQL.'); }
        $f = F::prepared(); config(['database.connections.checkout_observer' => DB::connection()->getConfig()]);
        $this->gateway->onCreate = function (array $params, string $key): array {
            $observer = DB::connection('checkout_observer');
            $this->assertNotSame(DB::selectOne('SELECT CONNECTION_ID() AS id')->id, $observer->selectOne('SELECT CONNECTION_ID() AS id')->id);
            $this->assertSame(0, DB::transactionLevel());
            $row = $observer->table('checkout_intents')->sole(); $this->assertSame($key, $row->idempotency_key);
            $this->assertSame($params['metadata']['intent_id'], $row->public_id);

            return F::session($params);
        };
        try { $this->startCheckout($f); } finally { DB::purge('checkout_observer'); }
        $this->assertDatabaseCount('checkout_sessions', 1);
    }

    public function test_timeout_retains_one_intent_and_retries_exactly_after_original_inventory_deadline(): void
    {
        $f = F::prepared(false, true); $before = $this->retained();
        $this->gateway->onCreate = fn () => throw new QuoteException('CHECKOUT_UNAVAILABLE', 503);
        $this->rejected('CHECKOUT_UNAVAILABLE', fn () => $this->startCheckout($f), 503);
        $intent = CheckoutIntent::sole()->getAttributes(); $this->assertDatabaseCount('checkout_sessions', 0);
        $this->assertSame('pending', $this->checkoutStatus($f)['status']);
        $this->travelTo(InventoryReservation::sole()->expires_at->addSecond());
        $this->gateway->onCreate = null; $result = $this->startCheckout($f);
        $this->assertSame('open', $result['status']); $this->assertCount(2, $this->creates());
        $this->assertSame($this->creates()[0]['params'], $this->creates()[1]['params']);
        $this->assertSame($this->creates()[0]['key'], $this->creates()[1]['key']);
        $this->assertSame($intent, CheckoutIntent::sole()->getAttributes()); $this->assertSame($before, $this->retained());
    }

    public function test_unknown_provider_outcome_past_retry_window_requires_reconciliation_without_new_session(): void
    {
        $f = F::prepared(); $this->gateway->onCreate = fn () => throw new QuoteException('CHECKOUT_UNAVAILABLE', 503);
        $this->rejected('CHECKOUT_UNAVAILABLE', fn () => $this->startCheckout($f));
        $intent = CheckoutIntent::sole(); $this->travelTo($intent->retry_before->addSecond());
        foreach ([$this->checkoutStatus($f), $this->startCheckout($f), $this->reconcileCheckout($f)] as $result) {
            $this->assertSame('reconciliation_required', $result['status']); $this->assertNull($result['url']);
        }
        $this->assertCount(1, $this->creates()); $this->assertDatabaseCount('checkout_intents', 1); $this->assertDatabaseCount('checkout_sessions', 0);
        $this->assertSame('pending', InventoryReservation::sole()->state);
    }

    public function test_reconciliation_of_known_session_works_after_policy_withdrawal_and_never_finalizes_paid_evidence(): void
    {
        $f = F::prepared(true, true); $this->startCheckout($f); $before = $this->retained();
        $session = CheckoutSession::sole()->getAttributes(); $firstObservations = CheckoutObservation::count();
        $this->travelTo(now()->addHours(2));
        config(['commerce.test_checkout_policy' => null, 'payments.stripe.checkout_enabled' => false]);
        $this->gateway->session['status'] = 'complete'; $this->gateway->session['payment_status'] = 'paid';
        $this->gateway->session['url'] = null; $this->gateway->session['payment_intent'] = 'pi_SYNTHETIC';
        $result = $this->reconcileCheckout($f);
        $this->assertSame('complete', $result['status']); $this->assertNull($result['url']);
        $this->assertSame('not_verified', $result['paymentStatus']); $this->assertSame('not_started', $result['fulfillmentStatus']);
        $this->assertSame($session, CheckoutSession::sole()->getAttributes());
        $this->assertGreaterThan($firstObservations, CheckoutObservation::count());
        $this->assertSame($before, $this->retained()); $this->assertCount(1, $this->creates());
        $calls = $this->gateway->calls; $this->assertSame($result, $this->checkoutStatus($f)); $this->assertSame($calls, $this->gateway->calls);
    }


    public function test_explicit_operator_locator_recovers_a_lost_session_after_retry_window_without_recreating_it(): void
    {
        $f = F::prepared(); $accepted = null;
        $this->gateway->onCreate = function (array $params) use (&$accepted): array {
            $accepted = F::session($params);
            throw new QuoteException('CHECKOUT_UNAVAILABLE', 503);
        };
        $this->rejected('CHECKOUT_UNAVAILABLE', fn () => $this->startCheckout($f));
        $intent = CheckoutIntent::sole(); $this->travelTo($intent->retry_before->addSecond());
        $this->gateway->session = $accepted;
        $result = app(HostedCheckout::class)->recover($intent, F::SESSION);
        $this->assertSame('open', $result['status']); $this->assertCount(1, $this->creates());
        $retrievals = array_values(array_filter($this->gateway->calls, fn ($call) => $call['operation'] === 'retrieve'));
        $this->assertCount(1, $retrievals); $this->assertSame(F::SESSION, $retrievals[0]['id']);
        $this->assertDatabaseCount('checkout_sessions', 1); $this->assertSame('pending', InventoryReservation::sole()->state);
    }

    public function test_default_recovery_scan_skips_aged_unknown_intents_before_applying_its_limit(): void
    {
        $aged = [];
        $this->gateway->onCreate = fn () => throw new RuntimeException('Synthetic lost provider response.');
        foreach ([0, 1] as $_) {
            $f = F::prepared(); $this->rejected('CHECKOUT_UNAVAILABLE', fn () => $this->startCheckout($f));
            $aged[] = CheckoutIntent::where('order_id', $f['order']->id)->sole()->public_id;
        }
        $this->travelTo(now()->addMinutes(16));
        $fresh = F::prepared(); $this->rejected('CHECKOUT_UNAVAILABLE', fn () => $this->startCheckout($fresh));
        $freshId = CheckoutIntent::where('order_id', $fresh['order']->id)->sole()->public_id;
        $this->gateway->onCreate = null; $before = $this->retained();
        $this->assertSame(0, Artisan::call('vasey:reconcile-test-checkout', ['--limit' => '1']));
        $output = Artisan::output(); $this->assertStringContainsString($freshId.' open', $output);
        foreach ($aged as $id) { $this->assertStringNotContainsString($id, $output); }
        $this->assertCount(4, $this->creates()); $this->assertSame($freshId, $this->creates()[3]['params']['metadata']['intent_id']);
        $this->assertDatabaseCount('checkout_sessions', 1); $this->assertSame($before, $this->retained());
        $this->assertSame(2, CheckoutIntent::whereDoesntHave('session')->count());
    }

    public function test_operator_recovery_command_never_prints_provider_errors_private_urls_or_buyer_details(): void
    {
        $f = F::prepared(); $accepted = null;
        $this->gateway->onCreate = function (array $params) use (&$accepted): array {
            $accepted = F::session($params); throw new RuntimeException('Synthetic lost provider response.');
        };
        $this->rejected('CHECKOUT_UNAVAILABLE', fn () => $this->startCheckout($f));
        $intent = CheckoutIntent::sole(); $this->travelTo($intent->retry_before->addSecond());
        $this->gateway->onRetrieve = fn () => throw new RuntimeException('secret-provider-marker buyer-private@example.invalid https://private.invalid');
        $arguments = ['intent' => $intent->public_id, '--session' => F::SESSION];
        $this->assertSame(1, Artisan::call('vasey:reconcile-test-checkout', $arguments));
        $output = Artisan::output(); $this->assertStringContainsString($intent->public_id.' reconciliation unavailable', $output);
        foreach (['secret-provider-marker', 'buyer-private@example.invalid', 'https://private.invalid', ...array_values(OrderFixtures::buyer())] as $private) {
            $this->assertStringNotContainsString($private, $output);
        }
        $this->assertDatabaseCount('checkout_sessions', 0);
        $this->gateway->onRetrieve = null; $this->gateway->session = $accepted;
        $this->assertSame(0, Artisan::call('vasey:reconcile-test-checkout', $arguments));
        $this->assertStringContainsString($intent->public_id.' open', Artisan::output());
        $this->assertStringNotContainsString($accepted['url'], Artisan::output());
        $this->assertCount(1, $this->creates()); $this->assertDatabaseCount('checkout_sessions', 1);
    }

    public function test_provider_private_fields_are_omitted_from_retained_decrypted_observations_and_http_projection(): void
    {
        $f = F::prepared();
        $this->gateway->onCreate = static fn (array $params) => F::session($params) + [
            'customer_details' => ['email' => 'provider-private@example.invalid'], 'client_secret' => 'secret-provider-marker'];
        $result = $this->startCheckout($f);
        $retained = json_encode($result, JSON_THROW_ON_ERROR).Crypt::decryptString(CheckoutSession::sole()->evidence_ciphertext).
            Crypt::decryptString(CheckoutObservation::sole()->evidence_ciphertext).json_encode(DB::table('audit_events')->get(), JSON_THROW_ON_ERROR);
        foreach (['provider-private@example.invalid', 'secret-provider-marker', 'customer_details', 'client_secret'] as $private) {
            $this->assertStringNotContainsString($private, $retained);
        }
    }

    public static function invalidResponses(): array
    {
        return [['live'], ['object'], ['mode'], ['currency'], ['total'], ['subtotal'], ['metadata'], ['client_reference'],
            ['url_host'], ['url_credentials'], ['url_session'], ['expiry'], ['tax'], ['discount'], ['shipping'], ['quantity'], ['line_amount'], ['extra_line'], ['pagination']];
    }

    #[DataProvider('invalidResponses')]
    public function test_mismatched_provider_response_never_binds_or_leaks_and_keeps_pending_resources(string $scenario): void
    {
        $f = F::prepared(); $before = $this->retained();
        $this->gateway->onCreate = static function (array $params) use ($scenario): array {
            $value = F::session($params);
            match ($scenario) {
                'live' => $value['livemode'] = true,
                'object' => $value['object'] = 'payment_intent',
                'mode' => $value['mode'] = 'subscription',
                'currency' => $value['currency'] = 'eur',
                'total' => $value['amount_total']++,
                'subtotal' => $value['amount_subtotal']++,
                'metadata' => $value['metadata']['attempt_id'] = (string) Str::uuid(),
                'client_reference' => $value['client_reference_id'] = (string) Str::uuid(),
                'url_host' => $value['url'] = 'https://checkout.stripe.com.attacker.invalid/private',
                'url_credentials' => $value['url'] = 'https://buyer@example.invalid@checkout.stripe.com/private',
                'url_session' => $value['url'] = 'https://checkout.stripe.com/c/pay/cs_test_DIFFERENT',
                'expiry' => $value['expires_at']++,
                'tax' => $value['total_details']['amount_tax'] = 1,
                'discount' => $value['total_details']['amount_discount'] = 1,
                'shipping' => $value['total_details']['amount_shipping'] = 1,
                'quantity' => $value['line_items']['data'][0]['quantity'] = 2,
                'line_amount' => $value['line_items']['data'][0]['amount_total']++,
                'extra_line' => $value['line_items']['data'][] = $value['line_items']['data'][0],
                'pagination' => $value['line_items']['has_more'] = true,
            };

            return $value;
        };
        $this->rejected('CHECKOUT_CHANGED', fn () => $this->startCheckout($f), 409);
        $this->assertDatabaseCount('checkout_intents', 1); $this->assertDatabaseCount('checkout_sessions', 0); $this->assertDatabaseCount('checkout_observations', 0);
        $this->assertSame($before, $this->retained()); $this->assertSame('pending', $this->checkoutStatus($f)['status']);
    }

    public static function unavailableConfigurations(): array { return [['disabled'], ['policy'], ['production'], ['mode'], ['account']]; }

    #[DataProvider('unavailableConfigurations')]
    public function test_new_checkout_requires_explicit_configuration_test_scope_and_matching_account(string $scenario): void
    {
        $f = F::prepared();
        if ($scenario === 'disabled') { config(['payments.stripe.checkout_enabled' => false]); }
        if ($scenario === 'policy') { config(['commerce.test_checkout_policy' => null]); }
        if ($scenario === 'production') { $this->app->instance('env', 'production'); }
        if ($scenario === 'mode') { config(['payments.stripe.mode' => 'live']); }
        if ($scenario === 'account') { $this->gateway->accountResponse['id'] = 'acct_DIFFERENT'; }
        try {
            $this->rejected($scenario === 'account' ? 'CHECKOUT_CHANGED' : 'CHECKOUT_UNAVAILABLE', fn () => $this->startCheckout($f), $scenario === 'account' ? 409 : 503);
            $this->assertCount(0, $this->creates()); $this->assertDatabaseCount('checkout_sessions', 0);
            $this->assertSame('pending', InventoryReservation::sole()->state);
        } finally {
            // DatabaseMigrations must roll back the disposable test schema without
            // inheriting this case's simulated production confirmation prompt.
            if ($scenario === 'production') { $this->app->instance('env', 'testing'); }
        }
    }

    public function test_nonzero_test_tax_and_expired_first_attempt_cannot_initiate_a_provider_session(): void
    {
        PricingFixtures::configure(PricingFixtures::policy()); $taxed = F::prepared();
        $this->rejected('CHECKOUT_UNSUPPORTED', fn () => $this->startCheckout($taxed), 409);
        F::configure(); $expired = F::prepared(); $this->travelTo($expired['order']->attempt()->sole()->expires_at);
        $this->rejected('CHECKOUT_EXPIRED', fn () => $this->startCheckout($expired), 410);
        $this->assertCount(0, $this->creates()); $this->assertDatabaseCount('checkout_intents', 0);
    }

    public static function amountBounds(): array { return [[49, false], [50, true], [99999999, true], [100000000, false]]; }

    #[DataProvider('amountBounds')]
    public function test_provider_amount_bounds_are_enforced_before_any_create_call(int $amount, bool $accepted): void
    {
        $f = F::preparedPrice($amount);
        if ($accepted) {
            $this->assertSame($amount, $this->startCheckout($f)['totalMinor']); $this->assertCount(1, $this->creates());
        } else {
            $this->rejected('CHECKOUT_UNSUPPORTED', fn () => $this->startCheckout($f), 409);
            $this->assertCount(0, $this->creates()); $this->assertDatabaseCount('checkout_intents', 0);
        }
        $this->assertSame('pending', InventoryReservation::sole()->state);
    }

    public static function failedWrites(): array { return [['checkout_intents'], ['checkout_sessions'], ['checkout_observations']]; }

    #[DataProvider('failedWrites')]
    public function test_database_failure_never_duplicates_provider_identity_or_partly_binds_a_session(string $table): void
    {
        $f = F::prepared(); $before = $this->retained(); $failed = false;
        DB::connection()->beforeExecuting(function (string $query) use ($table, &$failed): void {
            if (! $failed && preg_match('/\Ainsert into ["`]'.preg_quote($table, '/').'["`]/i', $query)) {
                $failed = true; throw new RuntimeException('Synthetic checkout persistence failure.');
            }
        });
        try { $this->startCheckout($f); $this->fail('Expected checkout persistence failure.'); }
        catch (QuoteException $error) { $this->assertSame('CHECKOUT_UNAVAILABLE', $error->errorCode); }
        catch (RuntimeException $error) { $this->assertSame('Synthetic checkout persistence failure.', $error->getMessage()); }
        $this->assertTrue($failed); $this->assertDatabaseCount('checkout_sessions', 0); $this->assertDatabaseCount('checkout_observations', 0);
        $this->assertDatabaseCount('checkout_intents', $table === 'checkout_intents' ? 0 : 1);
        $first = $this->creates(); $this->assertCount($table === 'checkout_intents' ? 0 : 1, $first);
        $this->assertSame($before, $this->retained());
        $this->assertSame('open', $this->startCheckout($f)['status']);
        if ($first !== []) { $this->assertSame($first[0]['params'], $this->creates()[1]['params']); $this->assertSame($first[0]['key'], $this->creates()[1]['key']); }
        $this->assertDatabaseCount('checkout_sessions', 1);
    }

    public function test_foreign_owner_and_unknown_order_are_indistinguishable_before_provider_io(): void
    {
        $f = F::prepared();
        foreach (['start', 'status', 'reconcile'] as $operation) {
            $this->rejected('ORDER_NOT_FOUND', fn () => app(HostedCheckout::class)->$operation($f['order']->public_id, str_repeat('b', 64)), 404);
            $this->rejected('ORDER_NOT_FOUND', fn () => app(HostedCheckout::class)->$operation((string) Str::uuid(), InventoryFixtures::OWNER), 404);
        }
        $this->assertSame([], $this->gateway->calls); $this->assertDatabaseCount('checkout_intents', 0);
    }

    private function ownedHttpOrder(): array
    {
        $f = InventoryFixtures::selection();
        $quoteId = $this->postJson('/quotes', ['items' => $f['items']], ['Idempotency-Key' => (string) Str::uuid()])->assertOk()->json('quote.id');
        $quote = \App\Domain\Commerce\Models\Quote::where('public_id', $quoteId)->sole();
        app(PriceQuote::class)->create($quoteId, $quote->owner_key);
        $request = OrderFixtures::request($quote, $quote->owner_key);
        $orderId = $this->postJson('/orders', $request, ['Idempotency-Key' => (string) Str::uuid()])->assertOk()->json('order.id');

        return ['id' => $orderId, 'owner' => $quote->owner_key];
    }

    public function test_http_checkout_is_owned_private_and_browser_success_return_only_reads_unverified_status(): void
    {
        $f = $this->ownedHttpOrder(); $url = '/orders/'.$f['id'].'/checkout';
        $this->assertPrivate($this->getJson($url)->assertOk()->assertJsonPath('checkout.status', 'not_started'));
        $created = $this->postEmpty($url)->assertOk()->assertJsonPath('checkout.status', 'open'); $this->assertPrivate($created);
        $before = $this->retained(); $calls = $this->gateway->calls; $audits = DB::table('audit_events')->count();
        $this->getJson($url.'?session_id=cs_test_ATTACKER&success=true')->assertOk()->assertJsonPath('checkout.paymentStatus', 'not_verified');
        $return = $this->get($url.'/return?session_id=cs_test_ATTACKER&success=true')->assertOk();
        $return->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        // Symfony may retain Vary as multiple field lines; get() reads only the first.
        $vary = $return->baseResponse->getVary();
        $this->assertContains('Cookie', $vary); $this->assertContains('X-Inertia', $vary);
        $this->assertSame($calls, $this->gateway->calls); $this->assertSame($before, $this->retained());
        $this->assertSame($audits, DB::table('audit_events')->count());
        foreach ([...array_values(OrderFixtures::buyer()), F::ACCOUNT, $f['owner'], CheckoutIntent::sole()->idempotency_key] as $private) { $created->assertDontSee($private, false); }
        $this->flushSession();
        $foreign = $this->getJson($url)->assertNotFound(); $this->assertPrivate($foreign);
        $unknown = $this->getJson('/orders/'.Str::uuid().'/checkout')->assertNotFound(); $this->assertSame($unknown->json(), $foreign->json());
        $this->postEmpty($url)->assertNotFound(); $this->postEmpty($url.'/reconcile')->assertNotFound();
    }

    public function test_real_csrf_exact_empty_json_and_unexpected_errors_preserve_private_boundaries(): void
    {
        $f = $this->ownedHttpOrder(); $url = '/orders/'.$f['id'].'/checkout';
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery {
            protected function runningUnitTests(): bool { return false; }
        });
        $this->assertPrivate($this->postEmpty($url)->assertStatus(419));
        $token = Str::random(40); $this->withSession(['_token' => $token]); $headers = ['HTTP_X_CSRF_TOKEN' => $token];
        foreach (['[]', 'null', '{', '{"amount":1}', '{"session_id":"cs_test_ATTACKER"}'] as $body) {
            $this->assertPrivate($this->postEmpty($url, $body, $headers)->assertUnprocessable());
        }
        $this->postEmpty($url.'?amount=1', '{}', $headers)->assertUnprocessable();
        $this->assertDatabaseCount('checkout_intents', 0);
        config(['app.debug' => true]); Log::spy();
        $this->gateway->onCreate = fn () => throw new RuntimeException('secret buyer@example.invalid private-key-marker');
        $failure = $this->postEmpty($url, '{}', $headers)->assertStatus(503); $this->assertPrivate($failure);
        foreach (['buyer@example.invalid', 'private-key-marker', 'trace'] as $private) { $failure->assertDontSee($private, false); }
        $this->assertDatabaseCount('checkout_intents', 1); $this->assertDatabaseCount('checkout_sessions', 0);
    }
}
