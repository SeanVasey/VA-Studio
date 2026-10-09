<?php

namespace Tests\Feature;

use App\Domain\Commerce\Inventory\ManageRightsScope;
use App\Domain\Commerce\Models\CheckoutIntent;
use App\Domain\Commerce\Models\LicenseGrant;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\Models\PaymentObservation;
use App\Domain\Commerce\Models\StripeWebhookReceipt;
use App\Domain\Commerce\Models\VerifiedPayment;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Contracts\ContractRenderer;
use App\Domain\Contracts\Models\GrantContract;
use App\Domain\Delivery\Models\TestFulfillmentActivation;
use Dotenv\Parser\Parser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\CheckoutFixtures;
use Tests\Support\ContractFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\PaymentFixtures;
use Tests\Support\QuoteFixtures;
use Tests\Support\StripeWebhookFixtures;
use Tests\TestCase;

/**
 * Synthetic end-to-end dry run of the staging guest purchase, configured ONLY from
 * ops/staging/test-commerce/env.test-commerce.example (placeholders replaced with synthetic values).
 * No fixture configure() helper is used, so a missing or wrong profile value fails here.
 * Stripe transport is the existing synthetic gateway and PDF bytes come from the existing synthetic
 * renderer; the real isolated v2 renderer is covered by TestContractIssuanceTest and by the
 * validator's contracts.renderer_runtime check.
 * Post-payment work is driven by the same artisan commands, in the same order, as
 * scripts/ops/run-test-commerce-pipeline.sh (its paging logic is covered by TestCommercePipelineRunnerTest).
 * Runtime keys (APP_ENV, APP_URL, queue, session) are covered by TestCommerceProfileValidatorTest under local.
 */
class StagingTestCommerceProfileJourneyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const PROFILE = 'ops/staging/test-commerce/env.test-commerce.example';

    private const ORIGIN = 'https://staging.synthetic.invalid';

    private const WEBHOOK_SECRET = 'whsec_JourneySyntheticSecret2026';

    /** Config files whose env() reads this profile owns. */
    private const FAMILIES = ['commerce', 'payments', 'contracts', 'delivery', 'customer'];

    private object $gateway;

    private string $csrf;

    /** @var array<string, string> event bodies by event ID */
    private array $events = [];

    /** @var array<string, string> */
    private array $profile = [];

    /** @var array<string, array{0: string|false, 1: mixed, 2: mixed}> process environment before the profile was applied */
    private array $previous = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        chmod(Storage::disk('local')->path(''), 0700);
        Queue::fake();
        $this->csrf = Str::random(40);
        $this->withSession(['_token' => $this->csrf]);
        $this->applyProfile();
        $this->gateway = PaymentFixtures::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $this->gateway);
        $this->app->instance(StripePaymentGateway::class, $this->gateway);
        $this->app->instance(ContractRenderer::class, ContractFixtures::renderer());
    }

    protected function tearDown(): void
    {
        foreach ($this->previous as $name => [$env, $superEnv, $server]) {
            $env === false ? putenv($name) : putenv($name.'='.$env);
            if ($superEnv === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $superEnv;
            }
            if ($server === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $server;
            }
        }
        parent::tearDown();
    }

    /** Load the committed profile exactly as dotenv would and re-evaluate the families' config files. */
    private function applyProfile(): void
    {
        $raw = strtr((string) file_get_contents(base_path(self::PROFILE)), [
            '<STAGING_HOST>' => 'staging.synthetic.invalid', '<acct_ID>' => CheckoutFixtures::ACCOUNT,
            '<sk_test_KEY>' => 'sk_test_JourneySyntheticKey0000', '<whsec_SECRET>' => self::WEBHOOK_SECRET,
            '<SELLER_LEGAL_NAME>' => 'Synthetic Staging Seller', '<ASSENT_TEXT>' => 'I accept these synthetic test-only terms.',
        ]);
        foreach ((new Parser)->parse($raw) as $entry) {
            $this->profile[$entry->getName()] = $entry->getValue()->isDefined() ? $entry->getValue()->get()->getChars() : '';
        }
        $this->assertSame('local', $this->profile['APP_ENV']);
        $this->assertSame(self::ORIGIN, $this->profile['APP_URL']);
        foreach ($this->profile as $name => $value) {
            // PHPUnit keeps "testing"; both satisfy the policies' local/testing gate.
            if ($name === 'APP_ENV') {
                continue;
            }
            $this->previous[$name] = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];
            putenv($name.'='.$value);
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
        foreach (self::FAMILIES as $family) {
            config([$family => require config_path($family.'.php')]);
        }
    }

    /** One pipeline sweep, in the runner's stage order; returns each stage's item lines. */
    private function sweep(): array
    {
        $lines = [];
        foreach (['receipts' => 'vasey:process-stripe-receipts', 'reconcile' => 'vasey:reconcile-test-payments',
            'finalize' => 'vasey:finalize-test-payments', 'contracts' => 'vasey:issue-test-contracts',
            'activate' => 'vasey:activate-test-fulfillment'] as $stage => $command) {
            $this->assertSame(0, Artisan::call($command, ['--limit' => $stage === 'contracts' ? '5' : '25']), $stage);
            $lines[$stage] = array_values(array_filter(explode("\n", trim(Artisan::output())),
                fn (string $line): bool => $line !== '' && ! str_starts_with($line, 'NEXT_AFTER=')));
        }

        return $lines;
    }

    /** Catalog setup through the real publication services, plus the documented operator scope link. */
    private function catalog(bool $link = true): array
    {
        $f = QuoteFixtures::selection();
        if ($link) {
            $scopes = app(ManageRightsScope::class);
            // Isolated single-right fixture: each unrelated offer here gets its own explicit identity.
            // Real operators use the reviewed command and verify any shared-rights relationship.
            $scope = $scopes->register('offer-revision-'.$f['revision']->id, 'STAGING-SCOPE-REVISION-'.$f['revision']->id, $f['actor']);
            $scopes->link($scope->id, $f['revision']->id, 'STAGING-LINK-REVISION-'.$f['revision']->id, $f['actor']);
        }

        return $f;
    }

    /** Guest browser steps: quote, price, review, order (with assent). Returns the order public ID. */
    private function guestOrder(array $f): TestResponse
    {
        $quote = $this->postJson('/quotes', ['items' => $f['items']], ['Idempotency-Key' => (string) Str::uuid()])->assertOk()->json('quote.id');
        $this->call('POST', '/quotes/'.$quote.'/pricing', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertOk();
        $review = $this->getJson('/quotes/'.$quote.'/order-review')->assertOk()->json('review');

        return $this->postJson('/orders', ['quoteId' => $quote, 'reviewHash' => $review['reviewHash'],
            'buyer' => ['legalName' => 'Synthetic Guest Buyer', 'email' => 'guest-buyer@example.invalid'], 'accepted' => true],
            ['Idempotency-Key' => (string) Str::uuid()]);
    }

    private function startCheckout(string $order): array
    {
        $checkout = $this->call('POST', '/orders/'.$order.'/checkout', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{}')
            ->assertOk()->json('checkout');
        $this->assertSame('open', $checkout['status']);
        $create = collect($this->gateway->calls)->firstWhere('operation', 'create');
        $this->assertSame(self::ORIGIN.'/orders/'.$order.'/checkout/return', $create['params']['success_url']);
        $this->assertSame(4999, $create['params']['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame(['enabled' => false], $create['params']['automatic_tax']);

        return $checkout;
    }

    /** What Stripe's hosted page does after a successful test card. */
    private function payAtStripe(): void
    {
        $this->gateway->session['status'] = 'complete';
        $this->gateway->session['payment_status'] = 'paid';
        $this->gateway->session['url'] = null;
        $this->gateway->session['payment_intent'] = PaymentFixtures::PAYMENT;
        $this->gateway->payment = PaymentFixtures::payment($this->gateway->session);
    }

    /** Stripe re-sends the identical event bytes; a redelivery is signed again with a fresh timestamp. */
    private function webhook(string $type, string $id): TestResponse
    {
        $body = $this->events[$id] ??= StripeWebhookFixtures::body(PaymentFixtures::event($this->gateway->session, $id, $type));

        return $this->call('POST', '/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => StripeWebhookFixtures::signature($body, null, self::WEBHOOK_SECRET)], $body);
    }

    private function download(string $order, string $grant, string $kind): string
    {
        $authorization = $this->call('POST', '/orders/'.$order.'/delivery/authorizations', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => (string) Str::uuid(),
            'HTTP_X_CSRF_TOKEN' => $this->csrf,
        ], json_encode(['grantId' => $grant, 'kind' => $kind], JSON_THROW_ON_ERROR))->assertCreated()->json('authorization');
        $fields = ['authorizationId' => $authorization['authorizationId'], 'token' => $authorization['token'], '_token' => $this->csrf];
        $response = $this->call('POST', '/orders/'.$order.'/delivery/download', $fields, [], [], [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_ACCEPT' => 'text/html',
        ], http_build_query($fields, '', '&', PHP_QUERY_RFC3986))->assertOk();

        return $response->streamedContent();
    }

    public function test_guest_purchase_runs_from_quote_to_exact_file_download_using_only_the_staging_profile(): void
    {
        $f = $this->catalog();
        $order = $this->guestOrder($f)->assertOk()->json('order.id');
        $this->startCheckout($order);
        $this->payAtStripe();

        // Stripe Dashboard endpoint delivers checkout.session.completed; a retry of the same event is idempotent.
        $this->webhook('checkout.session.completed', 'evt_JourneyCompleted')->assertOk()->assertExactJson(['received' => true]);
        $this->webhook('checkout.session.completed', 'evt_JourneyCompleted')->assertOk();
        $this->assertSame(1, StripeWebhookReceipt::count());
        $this->assertDatabaseCount('verified_payments', 0); // a receipt alone proves nothing

        $first = $this->sweep();
        $receipt = StripeWebhookReceipt::sole();
        $grant = LicenseGrant::sole();
        $this->assertSame([$receipt->id.' awaiting_finalization'], $first['receipts']);
        $this->assertSame([], $first['reconcile']);
        $this->assertSame([$order.' paid'], $first['finalize']);
        $this->assertSame([$grant->public_id.' ready'], $first['contracts']);
        $this->assertSame([$order.' activated'], $first['activate']);
        $this->assertSame('paid', OrderFinalization::sole()->outcome);
        $this->assertSame(1, TestFulfillmentActivation::count());
        // A second sweep finds nothing left: the backlog is drained and work is not repeated.
        $this->assertSame(['receipts' => [], 'reconcile' => [], 'finalize' => [], 'contracts' => [], 'activate' => []], $this->sweep());

        // The retained original contract PDF exists in private storage.
        $contract = GrantContract::sole();
        $pdf = Storage::disk('local')->get($contract->storage_path);
        $this->assertStringStartsWith('%PDF-', $pdf);

        // Downloads stay closed until the operator provisions and enables the order's delivery control.
        $this->get('/orders/'.$order.'/delivery')->assertOk()->assertJsonPath('delivery.status', 'unavailable');
        $this->assertSame(0, Artisan::call('vasey:control-test-delivery', ['order' => $order, 'action' => 'block', '--expected-version' => '0', '--reference' => 'staging-drill-001']));
        $this->assertStringEndsWith('blocked version=0', trim(Artisan::output()));
        $this->assertSame(0, Artisan::call('vasey:control-test-delivery', ['order' => $order, 'action' => 'enable', '--expected-version' => '0', '--reference' => 'staging-drill-001']));
        $this->assertStringEndsWith('enabled version=1', trim(Artisan::output()));
        $this->get('/orders/'.$order.'/delivery')->assertOk()->assertJsonPath('delivery.status', 'available');

        $this->assertSame(hash('sha256', $pdf), hash('sha256', $this->download($order, $grant->public_id, 'contract')));
        $master = $f['media']['master_wav']->refresh();
        $wav = $this->download($order, $grant->public_id, 'master_wav');
        $this->assertSame($master->sha256, hash('sha256', $wav));
        $this->assertSame(hash('sha256', Storage::disk('local')->get($master->storage_path)), hash('sha256', $wav));
    }

    public function test_reconcile_without_any_webhook_completes_the_same_chain(): void
    {
        $order = $this->guestOrder($this->catalog())->assertOk()->json('order.id');
        $this->startCheckout($order);
        $this->payAtStripe();

        $lines = $this->sweep();
        $this->assertSame([], $lines['receipts']);
        $intent = CheckoutIntent::sole();
        $this->assertSame([$intent->public_id.' awaiting_finalization'], $lines['reconcile']);
        $this->assertSame([$order.' paid'], $lines['finalize']);
        $this->assertSame([$order.' activated'], $lines['activate']);
        $this->assertSame(1, VerifiedPayment::count());
        $this->assertDatabaseCount('stripe_webhook_receipts', 0);
    }

    public function test_declined_card_leaves_the_session_open_and_nothing_is_verified_or_granted(): void
    {
        $order = $this->guestOrder($this->catalog())->assertOk()->json('order.id');
        $this->startCheckout($order);
        // A declined card keeps the Checkout Session open and unpaid; Stripe sends no completed event.
        $lines = $this->sweep();
        $intent = CheckoutIntent::sole();
        $this->assertSame([$intent->public_id.' pending'], $lines['reconcile']);
        $this->assertSame([], $lines['finalize']);
        $this->assertDatabaseCount('verified_payments', 0);
        $this->assertDatabaseCount('license_grants', 0);

        // The buyer retries in the same session with a good card: the next sweep completes the order.
        $this->payAtStripe();
        $retry = $this->sweep();
        $this->assertSame([$intent->public_id.' awaiting_finalization'], $retry['reconcile']);
        $this->assertSame([$order.' paid'], $retry['finalize']);
    }

    public function test_expired_session_is_recorded_without_payment_and_reconcile_keeps_observing_it(): void
    {
        $order = $this->guestOrder($this->catalog())->assertOk()->json('order.id');
        $this->startCheckout($order);
        $this->gateway->session['status'] = 'expired';
        $this->gateway->session['url'] = null;

        $this->webhook('checkout.session.expired', 'evt_JourneyExpired')->assertOk();
        $lines = $this->sweep();
        $receipt = StripeWebhookReceipt::sole();
        $this->assertSame([$receipt->id.' expired'], $lines['receipts']);
        $this->assertSame([], $lines['finalize']);
        $this->assertDatabaseCount('verified_payments', 0);
        $this->assertDatabaseCount('license_grants', 0);
        $this->getJson('/orders/'.$order.'/checkout')->assertOk()->assertJsonPath('checkout.status', 'expired');

        // Finding for operators: an expired, never-paid intent stays in reconcile's selection, so each
        // reconcile sweep makes provider GETs again and appends one more observation row.
        $observations = PaymentObservation::count();
        $this->sweep();
        $this->sweep();
        $this->assertSame($observations + 2, PaymentObservation::count());
    }

    public function test_payment_confirmed_after_the_fifteen_minute_attempt_window_becomes_a_paid_exception(): void
    {
        $order = $this->guestOrder($this->catalog())->assertOk()->json('order.id');
        $this->startCheckout($order);
        $this->travel(16)->minutes();
        $this->payAtStripe();

        $lines = $this->sweep();
        $this->assertSame([$order.' paid_exception'], $lines['finalize']);
        $this->assertSame([], $lines['activate']);
        $this->assertDatabaseCount('license_grants', 0);
    }

    public function test_an_unlinked_offer_cannot_be_ordered_until_the_operator_links_its_rights_scope(): void
    {
        $f = $this->catalog(false);
        $this->guestOrder($f)->assertStatus(409)->assertJsonPath('code', 'INVENTORY_SCOPE_UNAVAILABLE');
        $this->assertSame(0, Order::count());
    }

    public function test_an_abandoned_order_keeps_its_offer_reserved_while_a_paid_order_releases_it(): void
    {
        // Finding for operators: an unpaid order's reservation stays pending (no unpaid release in this
        // profile), so the same offer revision cannot be ordered again by anyone.
        $f = $this->catalog();
        $order = $this->guestOrder($f)->assertOk()->json('order.id');
        $this->startCheckout($order);
        $this->gateway->session['status'] = 'expired';
        $this->gateway->session['url'] = null;
        $this->webhook('checkout.session.expired', 'evt_JourneyAbandoned')->assertOk();
        $this->sweep();
        $this->flushSession();
        $this->withSession(['_token' => $this->csrf]);
        $this->guestOrder($f)->assertStatus(409)->assertJsonPath('code', 'INVENTORY_UNAVAILABLE');

        // A paid, finalized order consumes its reservation, so another guest can buy the same offer.
        $this->travel(2)->minutes(); // leave the per-IP quotes-create throttle window
        $g = $this->catalog();
        $paid = $this->guestOrder($g)->assertOk()->json('order.id');
        $this->gateway->calls = [];
        $this->gateway->onCreate = fn (array $params): array => CheckoutFixtures::session($params, 'cs_test_JourneySecondSession');
        $this->startCheckout($paid);
        $this->payAtStripe();
        $this->assertSame([$paid.' paid'], $this->sweep()['finalize']);
        $this->travel(2)->minutes();
        $this->flushSession();
        $this->withSession(['_token' => $this->csrf]);
        $this->guestOrder($g)->assertOk();
    }
}
