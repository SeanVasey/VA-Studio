<?php

namespace Tests\Feature;

use App\Domain\Commerce\Checkout\HostedCheckout;
use App\Domain\Commerce\Finalization\FinalizeTestPayment;
use App\Domain\Commerce\Models\CheckoutIntent;
use App\Domain\Commerce\Models\VerifiedPayment;
use App\Domain\Commerce\Payments\ProcessStripeReceipt;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Support\Audit\AuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CheckoutFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\FinalizationFixtures;
use Tests\Support\InventoryFixtures;
use Tests\Support\OrderFixtures;
use Tests\Support\PaymentFixtures as F;
use Tests\Support\StripeWebhookFixtures;
use Tests\TestCase;

/** The drill evidence helper is read-only, minimized and refuses anything but Stripe test mode outside production. */
class TestCommerceEvidenceCommandTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private StripePaymentGateway $gateway;

    /** @var list<string> */
    private array $statements = [];

    private bool $recording = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        FinalizationFixtures::configure();
        Queue::fake();
        $this->gateway = F::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $this->gateway);
        $this->app->instance(StripePaymentGateway::class, $this->gateway);
        DB::listen(function (QueryExecuted $query): void {
            if ($this->recording) {
                $this->statements[] = $query->sql;
            }
        });
    }

    public function test_every_reference_form_gives_the_same_minimized_snapshot_using_only_select_statements(): void
    {
        $f = F::started($this->gateway);
        $receipt = F::receipt($this->gateway->session, 'evt_EvidenceCompleted');
        $this->assertSame('awaiting_finalization', app(ProcessStripeReceipt::class)->handle($receipt->id));
        $this->assertSame('paid', app(FinalizeTestPayment::class)->handle(VerifiedPayment::sole()->id));
        $calls = $this->gateway->calls;
        $before = $this->database();

        $snapshots = [];
        foreach ([$f['order']->public_id, $f['intent']->public_id, CheckoutFixtures::SESSION, 'evt_EvidenceCompleted'] as $reference) {
            [$status, $output] = $this->evidence($reference, true);
            $this->assertSame(0, $status, $output);
            $this->assertMinimized($output, $f);
            $snapshot = json_decode($output, true, 16, JSON_THROW_ON_ERROR);
            $this->assertMatchesRegularExpression('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $snapshot['captured_at']);
            unset($snapshot['captured_at']);
            $snapshots[] = $snapshot;
        }
        $this->assertSame([$snapshots[0], $snapshots[0], $snapshots[0]], array_slice($snapshots, 1));
        $s = $snapshots[0];
        $this->assertSame(['vasey-test-commerce-evidence-v1', 'testing', ['mode' => 'test', 'account_id' => CheckoutFixtures::ACCOUNT]],
            [$s['schema'], $s['environment'], $s['stripe']]);
        $this->assertSame(['id' => $f['order']->public_id, 'created_at' => $this->iso($f['order']->created_at),
            'payment_window_ends_at' => $this->iso($f['order']->attempt()->sole()->expires_at)], $s['order']);
        $this->assertSame(['state' => 'consumed', 'consumed' => true], $s['reservation']);
        $this->assertSame([$f['intent']->public_id, CheckoutFixtures::SESSION, 'complete'],
            [$s['checkout']['intent_id'], $s['checkout']['session_id'], $s['checkout']['latest_status']]);
        $payment = VerifiedPayment::sole();
        $this->assertSame([true, $this->iso($payment->confirmed_at), $payment->amount_minor, 'USD', F::PAYMENT, 'webhook_receipt', 'evt_EvidenceCompleted', 1, ['confirmed' => 1]],
            [$s['payment']['verified'], $s['payment']['confirmed_at'], $s['payment']['amount_minor'], $s['payment']['currency'],
                $s['payment']['payment_intent_id'], $s['payment']['source'], $s['payment']['confirming_event_id'],
                $s['payment']['observation_total'], $s['payment']['observation_outcomes']]);
        $this->assertSame([['receipt_id' => $receipt->id, 'event_id' => 'evt_EvidenceCompleted', 'event_type' => 'checkout.session.completed',
            'received_at' => $this->iso($receipt->received_at), 'work_state' => 'processed', 'work_outcome' => 'awaiting_finalization', 'attempts' => 1]], $s['receipts']);
        $this->assertSame(['paid', null], [$s['finalization']['outcome'], $s['finalization']['reason']]);
        $this->assertSame(['count' => 1, 'pending_entitlements' => 1, 'contracts_recorded' => 0], $s['grants']);
        $this->assertSame(['activated' => false, 'activated_at' => null, 'delivery' => null, 'delivery_version' => null], $s['fulfillment']);
        $this->assertNull($s['unpaid_release']);
        $this->assertSame(['receipts' => 1, 'verified_payments' => 1, 'license_grants' => 1], $s['totals']);

        // Text form: one key=value line per record, for the evidence packet.
        [$status, $text] = $this->evidence($f['order']->public_id);
        $this->assertSame(0, $status, $text);
        $this->assertMinimized($text, $f);
        foreach (['order id='.$f['order']->public_id, 'reservation state=consumed consumed=yes',
            'checkout intent_id='.$f['intent']->public_id.' session_id='.CheckoutFixtures::SESSION.' latest_status=complete',
            'source=webhook_receipt confirming_event_id=evt_EvidenceCompleted', 'observation_outcomes=confirmed:1',
            'receipt receipt_id='.$receipt->id.' event_id=evt_EvidenceCompleted event_type=checkout.session.completed',
            'work_state=processed work_outcome=awaiting_finalization attempts=1', 'finalization outcome=paid reason=-',
            'grants count=1 pending_entitlements=1 contracts_recorded=0', 'fulfillment activated=no activated_at=- delivery=- delivery_version=-',
            'unpaid_release none', 'totals receipts=1 verified_payments=1 license_grants=1'] as $line) {
            $this->assertStringContainsString($line, $text);
        }

        $this->assertNotEmpty($this->statements);
        foreach ($this->statements as $sql) {
            $this->assertMatchesRegularExpression('/\A\s*select\s/i', $sql);
            $this->assertDoesNotMatchRegularExpression('/\bfor\s+update\b|\block\s+in\s+share\s+mode\b/i', $sql);
        }
        $this->assertSame($before, $this->database(), 'The snapshot writes nothing.');
        $this->assertSame($calls, $this->gateway->calls, 'The snapshot makes no Stripe request.');
    }

    public function test_a_write_attempted_while_the_snapshot_reads_is_refused_and_the_guard_ends_with_the_command(): void
    {
        $f = F::started($this->gateway);
        $audits = AuditEvent::count();
        // Any listener that tries to write during the snapshot's reads meets the SELECT-only statement guard.
        CheckoutIntent::retrieved(fn (CheckoutIntent $intent) => AuditEvent::recordAttributed('synthetic.write_attempt', $intent, [], null));
        [$status, $output] = $this->evidence($f['order']->public_id);
        $this->assertSame([1, 'Evidence snapshot unavailable. Nothing was written.'], [$status, trim($output)]);
        $this->assertSame($audits, AuditEvent::count());
        $this->assertNotContains(false, array_map(fn ($sql) => preg_match('/\A\s*select\s/i', $sql) === 1, $this->statements));
        // The guard is scoped to the command: ordinary writes in the same process still work afterwards.
        AuditEvent::recordAttributed('synthetic.after_snapshot', $f['intent'], [], null);
        $this->assertSame($audits + 1, AuditEvent::count());
    }

    public function test_an_unpaid_order_without_a_session_binding_reports_only_what_exists(): void
    {
        $f = CheckoutFixtures::prepared();
        $this->gateway->onCreate = fn () => throw new \RuntimeException('Synthetic lost create response.');
        try {
            app(HostedCheckout::class)->start($f['order']->public_id, InventoryFixtures::OWNER);
        } catch (\Throwable) {
            // The intent is retained without a session binding.
        }
        $intent = CheckoutIntent::sole();
        [$status, $output] = $this->evidence($intent->public_id, true);
        $this->assertSame(0, $status, $output);
        $s = json_decode($output, true, 16, JSON_THROW_ON_ERROR);
        $this->assertSame([null, null, false, null, [], null, 0], [$s['checkout']['session_id'], $s['checkout']['latest_status'],
            $s['payment']['verified'], $s['payment']['source'], $s['receipts'], $s['finalization'], $s['grants']['count']]);
        $this->assertSame(['state' => 'pending', 'consumed' => false], $s['reservation']);
        $this->assertStringContainsString('"observation_outcomes": {}', $output);
        [, $text] = $this->evidence($intent->public_id);
        $this->assertStringContainsString('receipt none', $text);
        $this->assertStringContainsString('finalization none', $text);
    }

    public function test_unknown_malformed_or_foreign_account_references_disclose_nothing(): void
    {
        $f = F::started($this->gateway);
        F::receipt($this->gateway->session, 'evt_EvidenceForeign');
        foreach ([(string) Str::uuid(), 'cs_test_UnknownSession', 'evt_UnknownEvent'] as $reference) {
            [$status, $output] = $this->evidence($reference);
            $this->assertSame([1, 'No Stripe test order matches that reference for the configured account.'], [$status, trim($output)]);
        }
        foreach (['', 'order', 'cs_live_Session', 'evt-bad', 'pi_SYNTHETICPAYMENT', $f['order']->public_id.' '] as $reference) {
            [$status, $output] = $this->evidence($reference);
            $this->assertSame(1, $status);
            $this->assertStringStartsWith('Supply an order or checkout intent UUID', trim($output));
        }
        // Another configured Stripe test account cannot read this account's orders, sessions or events.
        config(['payments.stripe.account_id' => 'acct_OtherSyntheticAccount']);
        foreach ([$f['order']->public_id, $f['intent']->public_id, CheckoutFixtures::SESSION, 'evt_EvidenceForeign'] as $reference) {
            [$status, $output] = $this->evidence($reference);
            $this->assertSame([1, 'No Stripe test order matches that reference for the configured account.'], [$status, trim($output)]);
        }
    }

    #[DataProvider('refusals')]
    public function test_production_ambiguous_environments_and_stripe_live_configuration_are_refused_before_any_read(string $case, string $message): void
    {
        $f = F::started($this->gateway);
        $environment = $this->app['env'];
        try {
            match ($case) {
                'configured_production' => config(['app.env' => 'production']),
                'running_production' => [$this->app['env'] = 'production', config(['app.env' => 'production'])],
                'differing_environments' => config(['app.env' => 'local']),
                'outside_test_commerce' => [$this->app['env'] = 'demo', config(['app.env' => 'demo'])],
                'stripe_live_mode' => config(['payments.stripe.mode' => 'live']),
                'live_secret_key' => config(['payments.stripe.secret_key' => 'sk_live_SyntheticLiveShape0000']),
                'restricted_key' => config(['payments.stripe.secret_key' => 'rk_test_SyntheticRestricted000']),
                'production_live_funds' => config(['production_checkout.funds_mode' => 'live']),
                'production_live_key' => config(['production_checkout.secret_key' => 'rk_live_SyntheticLiveShape0000']),
                'missing_account' => config(['payments.stripe.account_id' => null]),
            };
            [$status, $output] = $this->evidence($f['order']->public_id);
        } finally {
            $this->app['env'] = $environment;
        }
        $this->assertSame(1, $status);
        $this->assertSame('Refused: '.$message, trim($output));
        $this->assertSame([], $this->statements, 'A refusal reads nothing.');
    }

    public static function refusals(): array
    {
        $production = 'the evidence snapshot never runs in production.';
        $live = 'Stripe test mode only: STRIPE_MODE=test, an sk_test_ key or none, and no live production checkout configuration.';

        return [
            'configured production' => ['configured_production', $production],
            'running production' => ['running_production', $production],
            'differing environments' => ['differing_environments', 'the configured environment (APP_ENV) and the running environment differ.'],
            'outside test commerce' => ['outside_test_commerce', 'the evidence snapshot runs only when APP_ENV is local, testing or staging.'],
            'Stripe live mode' => ['stripe_live_mode', $live],
            'live secret key' => ['live_secret_key', $live],
            'restricted key' => ['restricted_key', $live],
            'production live funds' => ['production_live_funds', $live],
            'production live key' => ['production_live_key', $live],
            'missing account' => ['missing_account', 'configure STRIPE_ACCOUNT_ID (acct_...).'],
        ];
    }

    private function evidence(string $reference, bool $json = false): array
    {
        $this->statements = [];
        $this->recording = true;
        try {
            $status = Artisan::call('vasey:test-commerce-evidence', ['reference' => $reference] + ($json ? ['--json' => true] : []));
        } finally {
            $this->recording = false;
        }

        return [$status, Artisan::output()];
    }

    private function assertMinimized(string $output, array $f): void
    {
        foreach ([...array_values(OrderFixtures::buyer()), InventoryFixtures::OWNER, 'sk_test_', StripeWebhookFixtures::SECRET,
            'checkout.stripe.com', 'ciphertext', 'owner_key', 'payload', 'evidence_hash', $f['order']->payload_hash] as $private) {
            $this->assertStringNotContainsString((string) $private, $output);
        }
    }

    /** Every table, so a write anywhere fails the comparison. */
    private function database(): array
    {
        $rows = [];
        foreach (array_column(Schema::getTables(), 'name') as $table) {
            $rows[$table] = json_encode(DB::table($table)->get(), JSON_THROW_ON_ERROR);
        }
        ksort($rows);

        return $rows;
    }

    private function iso(\DateTimeInterface $time): string
    {
        return CarbonImmutable::instance($time)->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
