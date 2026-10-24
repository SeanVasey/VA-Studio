<?php

namespace Tests\Feature;

use App\Console\Commands\TestCommerceEvidence;
use App\Domain\Commerce\Checkout\HostedCheckout;
use App\Domain\Commerce\Finalization\FinalizeTestPayment;
use App\Domain\Commerce\Models\CheckoutIntent;
use App\Domain\Commerce\Models\VerifiedPayment;
use App\Domain\Commerce\Payments\ProcessStripeReceipt;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Support\Audit\AuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
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
        $this->assertReadsOnly($this->statements, 1);
        $this->assertSame($before, $this->database(), 'The snapshot writes nothing.');
        $this->assertSame($calls, $this->gateway->calls, 'The snapshot makes no Stripe request.');
    }

    /**
     * Every statement of one evidence run is a plain SELECT, framed on MySQL (and only there) by one repeatable-read read-only
     * transaction: `SET TRANSACTION ISOLATION LEVEL REPEATABLE READ`, `START TRANSACTION READ ONLY`, the reads, `ROLLBACK`.
     *
     * @param  list<string>  $statements  the statements of `$runs` consecutive runs
     */
    private function assertReadsOnly(array $statements, int $runs): void
    {
        $framing = ['set transaction isolation level repeatable read', 'start transaction read only', 'rollback'];
        $normalized = array_map(fn (string $sql): string => strtolower(trim($sql)), $statements);
        $reads = array_values(array_filter($statements, fn (string $sql): bool => ! in_array(strtolower(trim($sql)), $framing, true)));
        $this->assertNotEmpty($reads);
        foreach ($reads as $sql) {
            $this->assertTrue(TestCommerceEvidence::isPlainSelect($sql), $sql);
        }
        if (DB::getDriverName() !== 'mysql') {
            $this->assertSame([], array_intersect($normalized, $framing), 'Only MySQL frames the reads.');

            return;
        }
        $this->assertSame($runs, count(array_keys($normalized, 'start transaction read only', true)));
        $this->assertSame($runs, count(array_keys($normalized, 'set transaction isolation level repeatable read', true)));
        $this->assertSame($runs, count(array_keys($normalized, 'rollback', true)));
        foreach (array_keys($normalized, 'start transaction read only', true) as $at) {
            $this->assertSame('set transaction isolation level repeatable read', $normalized[$at - 1], 'The isolation level is set for exactly that transaction.');
        }
        $this->assertSame('rollback', end($normalized));
    }

    public function test_the_statement_guard_refuses_locking_and_into_clauses_and_stays_a_single_inert_callback(): void
    {
        foreach (['select 1', 'SELECT `id` FROM `checkout_intents` WHERE `public_id` = ? LIMIT 1', "select count(*) as total from t where a = 'x'"] as $sql) {
            $this->assertTrue(TestCommerceEvidence::isPlainSelect($sql), $sql);
        }
        foreach (['update t set a = 1', 'insert into t values (1)', 'delete from t', 'with x as (select 1) select * from x for update', 'select 1 for update',
            'select 1 FOR SHARE', 'select 1 lock in share mode', 'select 1 for update skip locked', 'select 1 for no key update', 'select a into outfile \'/tmp/x\' from t',
            'select a into dumpfile \'/tmp/x\' from t', 'select a into @variable from t', 'select a from t into @variable', '  show tables', 'set @a = 1'] as $sql) {
            $this->assertFalse(TestCommerceEvidence::isPlainSelect($sql), $sql);
        }
        $f = F::started($this->gateway);
        $statement = 'select 1';
        CheckoutIntent::retrieved(function () use (&$statement): void {
            DB::select($statement);
        });
        foreach (['select id from checkout_intents for update', 'select id from checkout_intents lock in share mode', 'select 1 into @evidence_variable'] as $sql) {
            $statement = $sql;
            [$status, $output] = $this->evidence($f['order']->public_id);
            $this->assertSame([1, 'Evidence snapshot unavailable. Nothing was written.'], [$status, trim($output)], $sql);
        }
        $statement = 'select 1';
        [$status] = $this->evidence($f['order']->public_id);
        $this->assertSame(0, $status, 'A plain select inside the snapshot is allowed.');

        // Laravel cannot remove a single callback; the guard is registered once for the connection, so repeated runs add none.
        $callbacks = fn (): int => count((fn () => $this->beforeExecutingCallbacks)->call(DB::connection()));
        $before = $callbacks();
        $this->evidence($f['order']->public_id);
        $this->evidence($f['order']->public_id, true);
        $this->assertSame($before, $callbacks());
        // And once the command has ended the guard is inert: a write by anything else in the process is not refused.
        $statement = 'select 1';
        AuditEvent::recordAttributed('synthetic.after_guard', $f['intent'], [], null);
        $this->assertSame(1, AuditEvent::where('action', 'synthetic.after_guard')->count());
    }

    public function test_the_snapshot_loads_only_the_checkout_intent_columns_it_uses(): void
    {
        $f = F::started($this->gateway);
        F::receipt($this->gateway->session, 'evt_EvidenceColumns');
        $loaded = [];
        CheckoutIntent::retrieved(function (CheckoutIntent $intent) use (&$loaded): void {
            $loaded[] = array_keys($intent->getAttributes());
        });
        foreach ([$f['order']->public_id, $f['intent']->public_id, CheckoutFixtures::SESSION, 'evt_EvidenceColumns'] as $reference) {
            $loaded = [];
            [$status, $output] = $this->evidence($reference, true);
            $this->assertSame(0, $status, $output);
            $this->assertSame([['id', 'public_id', 'order_id', 'provider_expires_at']], $loaded, $reference);
        }
    }

    public function test_stored_markup_prints_literally_in_text_and_json_output(): void
    {
        $snapshot = ['schema' => 'vasey-test-commerce-evidence-v1', 'captured_at' => '2026-10-24T12:00:00Z', 'environment' => 'testing',
            'stripe' => ['mode' => 'test', 'account_id' => 'acct_Synthetic'],
            'order' => ['id' => 'o', 'created_at' => '<error>c</error>', 'payment_window_ends_at' => null], 'reservation' => null,
            'checkout' => ['intent_id' => 'i', 'session_id' => 'cs_test_x', 'latest_status' => '<info>complete</info>', 'provider_expires_at' => null],
            'payment' => ['verified' => false, 'confirmed_at' => null, 'amount_minor' => null, 'currency' => null, 'payment_intent_id' => null,
                'source' => null, 'confirming_event_id' => null, 'observation_total' => 1, 'observation_outcomes' => ['<comment>confirmed</comment>' => 1],
                'observations' => [['observed_at' => null, 'outcome' => '<fg=red>x</>', 'source' => 'receipt', 'event_id' => '<bg=red>e</>']]],
            'receipts' => [['receipt_id' => 1, 'event_id' => 'evt_x', 'event_type' => '<info>t</info>', 'received_at' => null, 'work_state' => '<error>w</error>',
                'work_outcome' => null, 'attempts' => 1]],
            'finalization' => ['outcome' => 'paid', 'reason' => '<comment>why</comment>', 'finalized_at' => null],
            'grants' => ['count' => 0, 'pending_entitlements' => 0, 'contracts_recorded' => 0],
            'fulfillment' => ['activated' => false, 'activated_at' => null, 'delivery' => null, 'delivery_version' => null],
            'unpaid_release' => null, 'totals' => ['receipts' => 0, 'verified_payments' => 0, 'license_grants' => 0]];
        foreach (['text', 'json'] as $method) {
            $command = app(TestCommerceEvidence::class);
            $command->setLaravel($this->app);
            $buffer = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true); // decorated: a style tag would be consumed
            $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));
            (new ReflectionMethod($command, $method))->invoke($command, $snapshot);
            $printed = $buffer->fetch();
            foreach (['<info>complete</info>', '<comment>confirmed</comment>', '<fg=red>x</>', '<bg=red>e</>', '<info>t</info>', '<error>w</error>', '<comment>why</comment>'] as $markup) {
                $this->assertStringContainsString($markup, $printed, $method.' keeps '.$markup);
            }
            $this->assertStringNotContainsString("\e[", $printed, $method.' applied no style');
        }
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
