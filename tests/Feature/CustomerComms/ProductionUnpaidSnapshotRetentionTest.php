<?php

namespace Tests\Feature\CustomerComms;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\CheckoutSchema;
use App\Domain\Commerce\ProductionCheckout\Evidence;
use App\Domain\Commerce\ProductionCheckout\ExecutionContextV1;
use App\Domain\Commerce\ProductionCheckout\HostedCheckout;
use App\Domain\Commerce\ProductionCheckout\ProviderGateway;
use App\Domain\Customers\DataRights\RetentionSchedule;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionCheckoutGatewayFixture;
use Tests\Support\ProductionCheckoutJourneyFixture;
use Tests\Support\ProductionCheckoutProviderFixtures;
use Tests\TestCase;

/**
 * FP-076, decision register "Unpaid order snapshots (retention)": the proof that a production (V1) order snapshot whose provider
 * session was observed expired can never take a payment afterwards, and the 30-day abandoned-checkout rule the read-only
 * retention schedule applies to exactly that state. The production chain has no webhook (HostedEvidence: "no client total,
 * redirect or webhook assertion is accepted"); its late paths are the buyer's reconcile and return, and the operator's
 * `vasey:production-checkout-reconcile`. Synthetic provider evidence only. Nothing is deleted.
 */
final class ProductionUnpaidSnapshotRetentionTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use ProductionCheckoutJourneyFixture;

    private const RETENTION = ['policy_reference' => 'Synthetic retention schedule SR-1 (test only)',
        'financial_records_years' => 7, 'consent_records_years' => 3, 'correspondence_records_years' => 2, 'security_records_years' => 1,
        'evidence_records_years' => 7, 'abandoned_checkout_days' => 30, 'operational_records_days' => 14];

    /** The order snapshot and every dependent row the schedule moves with it, and the payment table that holds them. */
    private const CHAIN = ['production_checkout_orders', 'production_checkout_order_lines', 'production_checkout_attempts',
        'production_checkout_provider_intents', 'production_checkout_sessions', 'production_checkout_observations'];

    /** Anything that would be a grant or a paid-order record of the production chain. */
    private const GRANTS = ['paid_order_origins', 'paid_grant_origins', 'paid_originals', 'license_grants', 'pending_entitlements', 'fulfillment_outbox'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('u', 32)),
            'production-customer-identity.enabled' => true, 'production-customer-identity.provenance' => IdentityPolicy::REHEARSAL,
            'production-customer-identity.public_origin' => 'http://localhost', 'production-customer-identity.notifications_enabled' => true,
            'production-customer-identity.transport_capability' => LoopbackSmtp::CAPABILITY,
            'production_checkout.fresh_checkout_enabled' => true, 'production_checkout.mounted' => true,
            'production_checkout.operator_reconciliation_enabled' => true, 'production_checkout.reconciliation_enabled' => true,
            'production_checkout.provider_io_enabled' => true, 'customer-data-rights.retention' => self::RETENTION]);
        Queue::fake();
        $this->travelTo(now()->startOfSecond());
    }

    public function test_an_observed_expiry_is_terminal_a_late_paid_reconcile_is_refused_with_evidence_and_never_pays_or_grants(): void
    {
        [$f, $gateway, $hosted] = $this->initiated();
        $order = $f['order']['orderId'];
        // Still open: in flight, and nothing terminal is recorded.
        $this->assertSame([0, 'open'], [$this->reconcile($order)[0], $this->reconcile($order)[1]['checkoutStatus']]);
        $this->assertSame(['session_open'], $this->kinds());

        // The provider session expires unpaid and the operator reconciles it: the terminal state.
        $this->travel(2)->hours();
        $gateway->state = 'expired';
        [$exit, $expired] = $this->reconcile($order);
        $this->assertSame([0, 'unverified', null, 'expired'], [$exit, $expired['paymentStatus'], $expired['outcome'], $expired['checkoutStatus']]);
        $this->assertSame(['session_open', RetentionSchedule::PRODUCTION_ABANDONED_KIND], $this->kinds());
        $terminal = self::evidence();

        // Every late path that reads "complete and paid" from the provider afterwards: the operator's reconcile, the buyer's
        // reconcile and the buyer returning to the checkout. Each is refused; none writes a payment.
        $gateway->state = 'paid';
        $this->assertSame([1, ['code' => 'refused', 'reason' => 'provider_uncertain']], $this->reconcile($order));
        $this->assertSame('provider_uncertain', $this->refusal(fn () => $hosted->reconcile($f['buyer']['principal'], $f['buyer']['user'], $order)));
        $this->refusal(fn () => $hosted->initiate($f['buyer']['principal'], $f['buyer']['user'], $order));
        $this->assertSame([1, ['code' => 'refused', 'reason' => 'provider_uncertain']], $this->reconcile($order, ['--session' => 'cs_test_SYNTHETIC']));
        $this->assertDatabaseCount(CheckoutSchema::TABLES['payment'], 0);
        foreach (self::GRANTS as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        // The refusal is retained, not silent: exactly one `uncertain` observation after the terminal one, and every earlier row
        // (order, intent, session binding, the open and the expired observation) is byte-identical.
        $this->assertSame(['session_open', 'session_expired', 'uncertain'], $this->kinds());
        $after = self::evidence();
        $this->assertSame($terminal['observation'], array_slice($after['observation'], 0, 2));
        unset($terminal['observation'], $after['observation']);
        $this->assertSame($terminal, $after);
        $this->assertSame(['unverified', 'unknown'], [$this->operatorStatus($order)['paymentStatus'], $this->operatorStatus($order)['checkoutStatus']]);

        // Long after, the same contradiction is still refused; and an honest re-read of the expiry changes nothing terminal.
        $this->travel(400)->days();
        $this->assertSame([1, ['code' => 'refused', 'reason' => 'provider_uncertain']], $this->reconcile($order));
        $gateway->state = 'expired';
        $this->assertSame([0, 'unverified', 'expired'], [$this->reconcile($order)[0], $this->operatorStatus($order)['paymentStatus'], $this->operatorStatus($order)['checkoutStatus']]);
        $this->assertDatabaseCount(CheckoutSchema::TABLES['payment'], 0);
        $this->assertSame(RetentionSchedule::PRODUCTION_ABANDONED_KIND, $this->kinds()[1]);
    }

    public function test_an_expiry_recorded_between_a_paid_provider_read_and_its_commit_wins_and_a_recorded_payment_is_never_expired(): void
    {
        // A reconcile reads "paid" from the provider; before it commits, another reconcile records the expiry. The paid read is
        // refused inside record(), under the intent row lock the first writer released.
        [$f, $gateway] = $this->initiated();
        $order = $f['order']['orderId'];
        $gateway->state = 'paid';
        $interleaved = false;
        $f['gateway']->afterPaymentRead = function () use ($gateway, $order, &$interleaved): void {
            if ($interleaved) {
                return;
            }
            $interleaved = true;
            $gateway->state = 'expired';
            app(HostedCheckout::class)->operatorReconcile($order);
            $gateway->state = 'paid';
        };
        $this->assertSame([1, ['code' => 'refused', 'reason' => 'provider_uncertain']], $this->reconcile($order));
        $this->assertTrue($interleaved);
        $this->assertSame(['session_open', 'session_expired', 'uncertain'], $this->kinds());
        $this->assertDatabaseCount(CheckoutSchema::TABLES['payment'], 0);
        foreach (self::GRANTS as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_a_paid_order_can_never_gain_an_expiry_and_is_never_counted_however_old(): void
    {
        [$f, $gateway] = $this->initiated();
        $order = $f['order']['orderId'];
        $gateway->state = 'paid';
        $this->assertSame([0, 'verified', 'on_time'], [$this->reconcile($order)[0], $this->operatorStatus($order)['paymentStatus'], $this->operatorStatus($order)['outcome']]);
        $paid = self::evidence();
        // The reverse contradiction: a later "expired" read of a paid session is refused too, and the payment stays.
        $gateway->state = 'expired';
        $this->assertSame([1, ['code' => 'refused', 'reason' => 'provider_uncertain']], $this->reconcile($order));
        $this->assertSame(['session_open', 'payment_confirmed', 'uncertain'], $this->kinds());
        $this->assertSame($paid['payment'], self::evidence()['payment']);
        $this->assertDatabaseCount(CheckoutSchema::TABLES['payment'], 1);

        $this->travel(50)->years();
        $this->assertNothingCounted($this->report());
        // Even a contradictory expired-session row on the paid intent (which record() can never write) would not release the
        // order: the payment row holds the observation, and through it the intent, session, order, lines and attempt.
        $this->travelBack();
        $this->travelTo(now()->startOfSecond());
        $this->forgeObservation(RetentionSchedule::PRODUCTION_ABANDONED_KIND);
        $this->travel(50)->years();
        $this->assertSame(1, DB::table('production_checkout_observations')->where('kind', 'session_expired')->count());
        $this->assertNothingCounted($this->report());
    }

    public function test_the_thirty_days_start_at_the_observed_expiry_and_count_the_snapshot_with_its_dependent_rows(): void
    {
        [$f, $gateway] = $this->initiated();
        $order = $f['order']['orderId'];
        $lines = DB::table('production_checkout_order_lines')->count();
        $this->assertGreaterThan(0, $lines);

        // In flight (an open session), however old: never counted. Mutation target: the `when` kind filter.
        $this->travel(90)->days();
        $this->assertNothingCounted($this->report());

        // The expiry is observed later than the session's own deadline; the clock starts at the observation, not the deadline.
        $gateway->state = 'expired';
        $this->assertSame(0, $this->reconcile($order)[0]);
        $expired = CarbonImmutable::parse(DB::table('production_checkout_observations')->where('kind', 'session_expired')->value('created_at'), 'UTC');
        $this->assertTrue($expired->equalTo(now()));
        $this->assertTrue($expired->greaterThan(CarbonImmutable::parse(DB::table('production_checkout_provider_intents')->value('provider_expires_at'), 'UTC')->addDays(30)));
        $this->assertNothingCounted($this->report());

        $this->travelTo($expired->addDays(30)->subSecond());
        $this->assertNothingCounted($this->report());
        $this->travelTo($expired->addDays(30));
        $before = self::evidence();
        $report = $this->report();
        $due = $expired->addDays(30)->toDateString();
        $this->assertSame(['past_period' => 1, 'oldest_due' => $due, 'newest_due' => $due, 'clock_index' => 'missing'], $report['production_checkout_observations']);
        $this->assertSame(['past_period' => 1, 'follows' => 'production_checkout_observations'], $report['production_checkout_provider_intents']);
        $this->assertSame(['past_period' => 1, 'follows' => 'production_checkout_provider_intents'], $report['production_checkout_sessions']);
        $this->assertSame(['past_period' => 1, 'follows' => 'production_checkout_provider_intents'], $report['production_checkout_orders']);
        $this->assertSame(['past_period' => $lines, 'follows' => 'production_checkout_orders'], $report['production_checkout_order_lines']);
        $this->assertSame(['past_period' => 1, 'follows' => 'production_checkout_orders'], $report['production_checkout_attempts']);
        // Read-only: nothing changed, and the review the order was built from, the exemption basis and the payment table are held.
        $this->assertSame($before, self::evidence());
        $this->assertSame(0, $report['production_checkout_reviews']['past_period']);
        foreach (['production_checkout_payments', 'production_checkout_exemption_bases', 'production_tax_checkout_orders'] as $held) {
            $this->assertArrayNotHasKey($held, $report, $held);
        }

        // A late contradicting reconcile after the 30 days is still refused, and its `uncertain` observation, newer than the
        // cutoff, is not itself counted: only the terminal observation is the clock row.
        $gateway->state = 'paid';
        $this->assertSame([1, ['code' => 'refused', 'reason' => 'provider_uncertain']], $this->reconcile($order));
        $this->assertDatabaseCount(CheckoutSchema::TABLES['payment'], 0);
        $this->travel(5)->years();
        $late = $this->report();
        $this->assertSame(1, $late['production_checkout_observations']['past_period']);
        $this->assertSame(1, $late['production_checkout_orders']['past_period']);
        $this->assertSame(3, DB::table('production_checkout_observations')->count());
    }

    public function test_states_that_are_not_provably_terminal_are_never_counted(): void
    {
        // No intent at all (the buyer accepted and never started the provider checkout): no observation, so no clock.
        $f = $this->payable();
        $this->assertSame([1, 0], [DB::table('production_checkout_orders')->count(), DB::table('production_checkout_provider_intents')->count()]);
        $this->travel(5)->years();
        $this->assertNothingCounted($this->report());
        $this->travelBack();
        $this->travelTo(now()->startOfSecond());

        // A canceled payment attempt while the session is still open is not terminal: the same session can still be paid.
        $gateway = new ExpirableGateway($f['gateway']);
        $this->app->instance(ProviderGateway::class, $gateway);
        (new HostedCheckout($f['access'], $gateway))->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $gateway->state = 'open_canceled';
        $this->assertSame(0, $this->reconcile($f['order']['orderId'])[0]);
        $this->assertSame(['session_open', 'canceled'], $this->kinds());
        $this->travel(31)->days();
        // An `uncertain` observation (a provider outage) is not terminal either.
        $gateway->state = 'down';
        $this->assertSame(1, $this->reconcile($f['order']['orderId'])[0]);
        $this->assertSame(['session_open', 'canceled', 'uncertain'], $this->kinds());
        $this->travel(31)->days();
        $this->assertNothingCounted($this->report());
        $gateway->state = 'paid';
        [$exit, $paid] = $this->reconcile($f['order']['orderId']);
        $this->assertSame([0, 'verified', 'paid_exception'], [$exit, $paid['paymentStatus'], $paid['outcome']]);
        $this->assertDatabaseCount(CheckoutSchema::TABLES['payment'], 1);
        $this->travel(5)->years();
        $this->assertNothingCounted($this->report());
    }

    public function test_an_expired_session_with_a_canceled_payment_attempt_is_terminal_in_code_but_stays_with_the_order(): void
    {
        // The observation kind is `canceled` whether the session is open or expired, so the kind column cannot tell this terminal
        // state from the non-terminal one above. It is refused like any expiry, and the schedule deliberately leaves it uncounted.
        [$f, $gateway] = $this->initiated();
        $order = $f['order']['orderId'];
        $gateway->state = 'expired_canceled';
        $this->assertSame([0, 'expired'], [$this->reconcile($order)[0], $this->operatorStatus($order)['checkoutStatus']]);
        $this->assertSame(['session_open', 'canceled'], $this->kinds());
        $gateway->state = 'paid';
        $this->assertSame([1, ['code' => 'refused', 'reason' => 'provider_uncertain']], $this->reconcile($order));
        $this->assertDatabaseCount(CheckoutSchema::TABLES['payment'], 0);
        $this->travel(5)->years();
        $this->assertNothingCounted($this->report());
    }

    public function test_the_schedule_pins_the_terminal_state_and_leaves_the_other_chains_with_the_order(): void
    {
        $byTable = RetentionSchedule::byTable();
        $this->assertSame('session_expired', RetentionSchedule::PRODUCTION_ABANDONED_KIND);
        $this->assertSame(['columns' => ['created_at'], 'format' => 'iso8601', 'when' => ['kind', 'session_expired'],
            'unless_referenced' => [['production_checkout_payments', 'intent_id', 'intent_id']]], $byTable['production_checkout_observations']['clock']);
        $this->assertSame(['via' => ['id', 'production_checkout_observations', 'intent_id']], $byTable['production_checkout_provider_intents']['clock']);
        $this->assertSame(['via' => ['id', 'production_checkout_provider_intents', 'order_id']], $byTable['production_checkout_orders']['clock']);
        $this->assertSame(['via' => ['intent_id', 'production_checkout_provider_intents']], $byTable['production_checkout_sessions']['clock']);
        foreach (['production_checkout_order_lines', 'production_checkout_attempts'] as $table) {
            $this->assertSame(['via' => ['order_id', 'production_checkout_orders']], $byTable[$table]['clock'], $table);
        }
        foreach (self::CHAIN as $table) {
            $this->assertSame('abandoned_checkout', $byTable[$table]['class'], $table);
        }
        // Payment evidence, the Stripe-test chain (no terminal unpaid state: a release keeps accepting late confirmed money) and
        // the tax (V2) chain (no stored expiry observation) stay with the order.
        foreach (['production_checkout_payments', 'orders', 'order_lines', 'order_attempts', 'checkout_intents', 'checkout_sessions', 'checkout_observations',
            'payment_observations', 'stripe_webhook_receipts', 'stripe_receipt_work', 'verified_payments', 'order_finalizations',
            'production_tax_checkout_orders', 'production_tax_checkout_requests', 'production_tax_checkout_bindings', 'production_tax_checkout_reviewed_sessions'] as $table) {
            $this->assertSame(['indefinite', null], [$byTable[$table]['class'], $byTable[$table]['clock']], $table);
        }
        $this->assertSame('not_applicable', $byTable['test_unpaid_releases']['class']);
    }

    /** @return array{0: array, 1: ExpirableGateway, 2: HostedCheckout} a payable order with one open provider session */
    private function initiated(): array
    {
        $f = $this->payable();
        $gateway = new ExpirableGateway($f['gateway']);
        $this->app->instance(ProviderGateway::class, $gateway);
        $hosted = new HostedCheckout($f['access'], $gateway);
        $hosted->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);

        return [$f, $gateway, $hosted];
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function reconcile(string $order, array $options = []): array
    {
        $exit = Artisan::call('vasey:production-checkout-reconcile', ['order' => $order, ...$options]);
        $lines = array_values(array_filter(explode("\n", Artisan::output())));
        $this->assertCount(1, $lines);

        return [$exit, json_decode($lines[0], true, 8, JSON_THROW_ON_ERROR)];
    }

    private function operatorStatus(string $order): array
    {
        return app(HostedCheckout::class)->operatorStatus($order);
    }

    private function refusal(\Closure $call): string
    {
        try {
            $call();
        } catch (CheckoutException $error) {
            return $error->reason;
        }
        $this->fail('A late provider read was accepted after the recorded expiry.');
    }

    /** @return list<string> */
    private function kinds(): array
    {
        return DB::table('production_checkout_observations')->orderBy('sequence')->pluck('kind')->all();
    }

    /** @return array<string, list<object>> */
    private static function evidence(): array
    {
        $tables = [];
        foreach (CheckoutSchema::TABLES as $kind => $table) {
            $tables[$kind] = json_decode(json_encode(DB::table($table)->orderBy('id')->get()->all(), JSON_THROW_ON_ERROR), true);
        }

        return $tables;
    }

    /**
     * A raw observation row of the given kind on the one intent, as only a direct database write could make it: the insert
     * trigger checks its shape, and no application path would accept or produce it. Used to prove the schedule's own holds.
     */
    private function forgeObservation(string $kind): void
    {
        $intent = DB::table('production_checkout_provider_intents')->sole();
        $sealed = Evidence::seal(['schema_version' => 1, 'purpose' => 'synthetic_forged_observation']);
        DB::table('production_checkout_observations')->insert(['public_id' => (string) Str::uuid(),
            'created_at' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z'), ...$sealed, 'intent_id' => $intent->id,
            'sequence' => DB::table('production_checkout_observations')->max('sequence') + 1, 'kind' => $kind]);
        $this->assertSame(CanonicalJson::VERSION, $sealed['canonicalization_version']);
    }

    /** @return array<string, array<string, int|string>> report lines keyed by table */
    private function report(): array
    {
        $this->assertSame(0, Artisan::call('vasey:retention-report'));
        $tables = [];
        foreach (explode("\n", Artisan::output()) as $line) {
            if (preg_match('/^  ([a-z_]+) (.+)$/', $line, $match) === 1) {
                parse_str(str_replace(' ', '&', $match[2]), $fields);
                $tables[$match[1]] = array_map(fn (string $value): int|string => ctype_digit($value) ? (int) $value : $value, $fields);
            }
        }

        return $tables;
    }

    private function assertNothingCounted(array $report): void
    {
        foreach (self::CHAIN as $table) {
            $this->assertSame(0, $report[$table]['past_period'], $table);
        }
    }
}

/** The synthetic provider with a session that can stay open, expire unpaid, lose a payment attempt, fail, or report payment. */
final class ExpirableGateway implements ProviderGateway
{
    /** open | open_canceled | expired | expired_canceled | paid | down */
    public string $state = 'open';

    public function __construct(private readonly ProductionCheckoutGatewayFixture $inner) {}

    public function provenance(ExecutionContextV1 $context): string
    {
        return $this->inner->provenance($context);
    }

    public function account(ExecutionContextV1 $context): array
    {
        return $this->inner->account($context);
    }

    public function create(ExecutionContextV1 $context, array $params, string $key): array
    {
        return $this->inner->create($context, $params, $key);
    }

    public function retrieve(ExecutionContextV1 $context, string $sessionId): array
    {
        $this->inner->provenance($context);
        if ($this->state === 'down') {
            throw new \RuntimeException('SYNTHETIC PROVIDER FAILURE');
        }
        CheckoutException::require($sessionId === 'cs_test_SYNTHETIC' && $this->inner->params !== []);
        if ($this->state === 'paid') {
            return ProductionCheckoutProviderFixtures::session($this->inner->params, 'complete', true);
        }
        $session = ProductionCheckoutProviderFixtures::session($this->inner->params, str_starts_with($this->state, 'expired') ? 'expired' : 'open');
        if (str_ends_with($this->state, '_canceled')) {
            $session['payment_intent'] = 'pi_SYNTHETIC';
        }

        return $session;
    }

    public function paymentIntent(ExecutionContextV1 $context, string $id): array
    {
        if ($this->state === 'paid') {
            $this->inner->paid = true;

            return $this->inner->paymentIntent($context, $id);
        }
        $this->inner->provenance($context);
        CheckoutException::require($id === 'pi_SYNTHETIC' && str_ends_with($this->state, '_canceled'));

        return ['status' => 'canceled', 'amount_received' => 0] + ProductionCheckoutProviderFixtures::payment($this->inner->params);
    }
}
