<?php

namespace Tests\Feature\ReviewProbes;

use App\Domain\Memberships\Billing\BillingException;
use App\Domain\Memberships\Billing\BillingLedger;
use App\Domain\Memberships\Billing\BillingProviderGateway;
use App\Domain\Memberships\Billing\BillingProviderPin;
use App\Domain\Memberships\Billing\BillingReconciliation;
use App\Domain\Memberships\Billing\BillingWebhookIntake;
use App\Jobs\RetrieveMembershipInvoice;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Stripe\Event as StripeEvent;
use Stripe\WebhookSignature;
use Symfony\Component\Process\Process;
use Tests\Support\BillingStripeFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\RehearsalBillingGateway;
use Tests\TestCase;

/**
 * Independent reviewer probes for Addendum 1 (delta 3092880b..9c2f9928, conditions R-2 and R-3). Evidence only; not part
 * of the suite. Each test asserts the OBSERVED behaviour at 9c2f9928 and writes one OBSERVATION line to STDERR so the text
 * log carries the result. Synthetic values only. The race probes need native MySQL and are skipped on SQLite.
 */
class Addendum1ProbeTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const SECRET = 'whsec_SYNTHETICREHEARSAL';

    private const T0 = F::PERIOD_START + 3600;

    private const WRONG_SUBSCRIPTION = 'sub_WRONGSYNTHETIC';

    protected function setUp(): void
    {
        parent::setUp();
        F::configure();
        $this->at(0);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** Same second: the job ran inline in the hint's own second, so its observation does not cover the hint (strict >). */
    public function test_probe_same_second_observation_does_not_cover_the_hint(): void
    {
        $binding = F::binding();
        $this->app->instance(BillingProviderGateway::class, new RehearsalBillingGateway(F::graph()));
        $payload = $this->event('evt_SYNTHETIC1');
        $first = $this->receive($payload);
        $afterFirst = $this->observationCount();
        $sameSecond = $this->receive($payload);
        $afterSameSecond = $this->observationCount();
        $this->at(1);
        $nextSecond = $this->receive($payload);
        $afterNextSecond = $this->observationCount();
        $this->at(2);
        $later = $this->receive($payload);
        $afterLater = $this->observationCount();
        $chain = (new BillingLedger)->observations(DB::table('production_membership_billing_invoices')->value('id'));
        $this->observe(sprintf('same-second: first scheduled=%s obs=%d; dup@T0 scheduled=%s obs=%d; dup@T0+1 scheduled=%s obs=%d; dup@T0+2 scheduled=%s obs=%d; created_at=%s; event received_at=%s; chain_ok=%d credit_events=%d',
            $this->flag($first['scheduled']), $afterFirst, $this->flag($sameSecond['scheduled']), $afterSameSecond, $this->flag($nextSecond['scheduled']), $afterNextSecond,
            $this->flag($later['scheduled']), $afterLater, implode(',', array_column($chain, 'created_at')), $first['event']['received_at'], count($chain),
            DB::table('production_membership_credit_events')->count()));
        $this->assertSame([1, 2, 3, 3], [$afterFirst, $afterSameSecond, $afterNextSecond, $afterLater]);
        $this->assertNotNull($sameSecond['scheduled']);
        $this->assertNotNull($nextSecond['scheduled']);
        $this->assertNull($later['scheduled']);
        $this->assertSame(0, DB::table('production_membership_credit_events')->count());
    }

    /**
     * Stale read: a retrieval that read the invoice BEFORE the hint arrived, but appended AFTER it, covers the hint. The hint's
     * own dispatch was lost, so its redelivery is suppressed although no retrieval read provider state after the hint.
     */
    public function test_probe_observation_from_a_read_that_predates_the_hint_covers_it(): void
    {
        $binding = F::binding();
        $payload = $this->event('evt_SYNTHETIC1');
        $lost = 'not attempted';
        $gateway = $this->hookedGateway(new RehearsalBillingGateway(F::graph(['invoice' => ['status' => 'open']])), function () use ($payload, &$lost): void {
            // The invoice was read at T0 (status open). invoice.paid arrives at T0+1; its dispatch is lost.
            $this->at(1);
            try {
                $this->receive($payload);
                $lost = 'no';
            } catch (BindingResolutionException) {
                $lost = 'yes';
            }
            $this->at(2);
        });
        $observation = (new BillingReconciliation($gateway))->retrieve($binding['id'], F::INVOICE);
        $event = (array) DB::table('production_membership_billing_events')->first();
        $this->at(3);
        Queue::fake();
        $redelivery = $this->receive($payload);
        $this->observe(sprintf('stale-read: invoice read at T0 (open); hint received_at=%s dispatch_lost=%s; observation outcome=%s reason=%s retrieved_at=%s created_at=%s; redelivery@T0+3 duplicate=%s scheduled=%s pushed=%d',
            $event['received_at'], $lost, $observation['outcome'], $this->reason($observation), $observation['retrieved_at'], $observation['created_at'],
            $redelivery['duplicate'] ? 'true' : 'false', $this->flag($redelivery['scheduled']), Queue::pushed(RetrieveMembershipInvoice::class)->count()));
        $this->assertSame('yes', $lost);
        $this->assertSame('not_settled', $observation['outcome']);
        $this->assertNull($redelivery['scheduled']);
    }

    /** A hint whose retrieval is a first-retrieval binding refusal leaves no observation, so every redelivery re-dispatches. */
    public function test_probe_binding_refusal_is_redispatched_on_every_redelivery(): void
    {
        $wrong = F::binding(['subscription_ref' => self::WRONG_SUBSCRIPTION]);
        $gateway = new RehearsalBillingGateway(F::graph());
        $this->app->instance(BillingProviderGateway::class, $gateway);
        $payload = $this->event('evt_SYNTHETIC2', 'invoice.paid', ['parent' => ['type' => 'subscription_details', 'quote_details' => null,
            'subscription_details' => ['metadata' => [], 'subscription' => self::WRONG_SUBSCRIPTION]]]);
        $reasons = [];
        foreach (range(0, 5) as $delivery) {
            $this->at(10 * $delivery);
            try {
                $this->receive($payload);
                $reasons[] = 'none';
            } catch (BillingException $error) {
                $reasons[] = $error->reason.'|msg='.$error->getMessage();
            }
        }
        $retrievals = count(array_keys($gateway->calls, 'account', true));
        $this->observe(sprintf('refusal-loop: deliveries=6 retrievals=%d reasons=%s events=%d invoices=%d observations=%d wrong_binding=%s',
            $retrievals, implode(';', array_unique($reasons)), DB::table('production_membership_billing_events')->count(),
            DB::table('production_membership_billing_invoices')->count(), $this->observationCount(), substr($wrong['id'], 0, 8)));
        $this->assertSame(6, $retrievals);
        $this->assertSame([1, 0, 0], [DB::table('production_membership_billing_events')->count(), DB::table('production_membership_billing_invoices')->count(), $this->observationCount()]);
    }

    /** Deviation (a): what an operator can find after an async first-retrieval binding refusal (database queue + one worker pass). */
    public function test_probe_failed_job_record_of_a_binding_refusal(): void
    {
        F::binding(['subscription_ref' => self::WRONG_SUBSCRIPTION]);
        $this->app->instance(BillingProviderGateway::class, new RehearsalBillingGateway(F::graph()));
        config(['queue.default' => 'database']);
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$logged): void {
            $logged[] = $message->level.':'.$message->message.(isset($message->context['exception']) ? '|ctx_reason='.($message->context['exception']->reason ?? '-') : '');
        });
        $payload = $this->event('evt_SYNTHETIC2', 'invoice.paid', ['parent' => ['type' => 'subscription_details', 'quote_details' => null,
            'subscription_details' => ['metadata' => [], 'subscription' => self::WRONG_SUBSCRIPTION]]]);
        $received = $this->receive($payload);
        $queued = DB::table('jobs')->count();
        $status = Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--stop-when-empty' => true, '--tries' => 1]);
        $failed = DB::table('failed_jobs')->get();
        $exception = (string) ($failed[0]->exception ?? '');
        $jobPayload = (string) ($failed[0]->payload ?? '');
        $this->observe(sprintf('failed-job: scheduled=%s queued=%d worker_status=%d failed_jobs=%d first_line="%s" mentions_binding_refused=%s mentions_line58=%s payload_has_plain_invoice_ref=%s logged=[%s] invoices=%d observations=%d',
            $this->flag($received['scheduled']), $queued, $status, count($failed), strtok($exception, "\n"), str_contains($exception, 'binding_refused') ? 'yes' : 'no',
            str_contains($exception, 'BillingReconciliation.php(58)') || str_contains($exception, 'BillingReconciliation.php:58') ? 'yes' : 'no',
            str_contains($jobPayload, F::INVOICE) ? 'yes' : 'no', implode(' || ', array_map(fn ($l) => substr($l, 0, 120), $logged)),
            DB::table('production_membership_billing_invoices')->count(), $this->observationCount()));
        $this->assertCount(1, $failed);
        $this->assertFalse(str_contains($exception, 'binding_refused'));
    }

    /** R-3 gap: settlement refuses `mode` before it compares customer or subscription, and `mode` still claims the identity. */
    public function test_probe_mode_refusal_under_a_wrong_binding_still_pins_the_invoice(): void
    {
        $wrong = F::binding(['subscription_ref' => self::WRONG_SUBSCRIPTION]);
        $right = F::binding();
        $first = (new BillingReconciliation(new RehearsalBillingGateway(F::graph(['invoice' => ['livemode' => true]]))))->retrieve($wrong['id'], F::INVOICE);
        $owner = DB::table('production_membership_billing_invoices')->value('subscription_binding_id');
        try {
            (new BillingReconciliation(new RehearsalBillingGateway(F::graph())))->retrieve($right['id'], F::INVOICE);
            $then = 'saved';
        } catch (BillingException $error) {
            $then = $error->reason;
        }
        $this->observe(sprintf('mode-first: wrong binding outcome=%s reason=%s identity_owner=%s; right binding then=%s invoices=%d',
            $first['outcome'], $this->reason($first), $owner === $wrong['id'] ? 'wrong' : 'right', $then, DB::table('production_membership_billing_invoices')->count()));
        $this->assertSame($wrong['id'], $owner);
        $this->assertSame('conflicting_invoice', $then);
    }

    /** Residual (reported by the implementer at 9c2f9928): does an unknown first retrieval under a wrong binding pin the invoice? */
    public function test_probe_unknown_first_retrieval_under_a_wrong_binding(): void
    {
        $wrong = F::binding(['subscription_ref' => self::WRONG_SUBSCRIPTION]);
        $right = F::binding();
        try {
            $first = (new BillingReconciliation(new RehearsalBillingGateway(F::graph(), 'account')))->retrieve($wrong['id'], F::INVOICE);
            $firstResult = 'saved '.$first['outcome'].'/'.$this->reason($first);
        } catch (BillingException $error) {
            $firstResult = 'threw '.$error->reason;
        }
        $invoicesAfterFirst = DB::table('production_membership_billing_invoices')->count();
        try {
            $then = 'saved '.(new BillingReconciliation(new RehearsalBillingGateway(F::graph())))->retrieve($right['id'], F::INVOICE)['outcome'];
        } catch (BillingException $error) {
            $then = 'threw '.$error->reason;
        }
        $this->observe(sprintf('unknown-first: wrong binding first=%s invoices_after_first=%d; right binding then=%s', $firstResult, $invoicesAfterFirst, $then));
        $this->addToAssertionCount(1);
    }

    /** e26d4cb7 semantics: what an operator can find after an async FIRST retrieval that ended unknown (database queue + one worker pass). */
    public function test_probe_failed_job_record_of_a_first_unknown(): void
    {
        F::binding();
        $this->app->instance(BillingProviderGateway::class, new RehearsalBillingGateway(F::graph(), 'invoice'));
        config(['queue.default' => 'database']);
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$logged): void {
            $logged[] = $message->level.':'.$message->message.(isset($message->context['exception']) ? '|ctx_reason='.($message->context['exception']->reason ?? '-') : '');
        });
        $payload = $this->event('evt_SYNTHETIC1');
        $received = $this->receive($payload);
        $status = Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--stop-when-empty' => true, '--tries' => 1]);
        $failed = DB::table('failed_jobs')->get();
        $exception = (string) ($failed[0]->exception ?? '');
        $this->at(30);
        config(['queue.default' => 'sync']);
        Queue::fake();
        $redelivery = $this->receive($payload);
        $this->observe(sprintf('first-unknown failed-job: scheduled=%s worker_status=%d failed_jobs=%d first_line="%s" mentions_provider_unavailable=%s logged=[%s] events=%d invoices=%d observations=%d; redelivery scheduled=%s',
            $this->flag($received['scheduled']), $status, count($failed), strtok($exception, "\n"), str_contains($exception, 'provider_unavailable') ? 'yes' : 'no',
            implode(' || ', array_map(fn ($l) => substr($l, 0, 120), $logged)), DB::table('production_membership_billing_events')->count(),
            DB::table('production_membership_billing_invoices')->count(), $this->observationCount(), $this->flag($redelivery['scheduled'])));
        $this->addToAssertionCount(1);
    }

    /** e26d4cb7 semantics: once the identity exists, an unknown observation newer than the hint does not cover it. */
    public function test_probe_unknown_observation_does_not_cover_a_hint(): void
    {
        $binding = F::binding();
        (new BillingReconciliation(new RehearsalBillingGateway(F::graph())))->retrieve($binding['id'], F::INVOICE);
        $this->at(10);
        $payload = $this->event('evt_SYNTHETIC1');
        try {
            $this->receive($payload);
        } catch (BindingResolutionException) {
            // Lost first dispatch.
        }
        $this->at(20);
        $unknown = (new BillingReconciliation(new RehearsalBillingGateway(F::graph(), 'invoice')))->retrieve($binding['id'], F::INVOICE);
        $this->at(30);
        Queue::fake();
        $redelivery = $this->receive($payload);
        $this->observe(sprintf('unknown-cover: newer observation outcome=%s seq=%d; redelivery scheduled=%s', $unknown['outcome'], $unknown['sequence'], $this->flag($redelivery['scheduled'])));
        $this->addToAssertionCount(1);
    }

    /** Deviation (b): N duplicates before any retrieval runs give N dispatches; nothing deduplicates the job. */
    public function test_probe_duplicates_before_the_first_retrieval_each_dispatch(): void
    {
        F::binding();
        $payload = $this->event('evt_SYNTHETIC1');
        try {
            $this->receive($payload);
        } catch (BindingResolutionException) {
            // Lost first dispatch (sync queue, no gateway bound).
        }
        Queue::fake();
        foreach (range(1, 25) as $delivery) {
            $this->at($delivery);
            $this->receive($payload);
        }
        $pushed = Queue::pushed(RetrieveMembershipInvoice::class)->count();
        $this->observe(sprintf('storm: duplicates=25 pushed=%d (bound per invoice only by MAX_OBSERVATIONS=%d once jobs run)', $pushed, BillingLedger::MAX_OBSERVATIONS));
        $this->assertSame(25, $pushed);
    }

    /** Native: two bindings both reach the claim. A `mode` refusal precedes the customer/subscription checks, so both verdicts may claim. */
    public function test_probe_native_two_bindings_race_the_claim(): void
    {
        $this->requireMysql();
        $right = F::binding();
        $wrong = F::binding(['subscription_ref' => self::WRONG_SUBSCRIPTION]);
        $graph = ['invoice' => ['livemode' => true]];
        $results = $this->race([['binding_id' => $right['id'], 'graph' => $graph], ['binding_id' => $wrong['id'], 'graph' => $graph]]);
        $invoices = DB::table('production_membership_billing_invoices')->get();
        $this->observe('race-two-bindings-mode: '.$this->summarise($results).' invoices='.count($invoices).' observations='.$this->observationCount());
        $this->assertCount(1, $invoices);
        $this->assertSame(['denied', 'saved'], $this->sorted(array_column($results, 'result')));
        $loser = array_values(array_filter($results, fn ($r) => $r['result'] === 'denied'))[0];
        $this->assertSame('conflicting_invoice', $loser['reason']);
        $this->assertSame(1, $this->observationCount());
    }

    /** Native: two bindings, provider unavailable for both on a first retrieval. Records how many identities result. */
    public function test_probe_native_two_bindings_unknown_first(): void
    {
        $this->requireMysql();
        $right = F::binding();
        $wrong = F::binding(['subscription_ref' => self::WRONG_SUBSCRIPTION]);
        $results = $this->race([['binding_id' => $right['id'], 'fail' => 'account'], ['binding_id' => $wrong['id'], 'fail' => 'account']]);
        $invoices = DB::table('production_membership_billing_invoices')->count();
        $this->observe('race-two-bindings-unknown: '.$this->summarise($results).' invoices='.$invoices.' observations='.$this->observationCount());
        $this->assertLessThanOrEqual(1, $invoices);
        $this->assertNotContains('unexpected', array_column($results, 'result'));
    }

    /** Native: the right binding (settled graph) races a wrong binding (refused subscription) for the first claim. */
    public function test_probe_native_right_and_wrong_binding_race(): void
    {
        $this->requireMysql();
        $right = F::binding();
        $wrong = F::binding(['subscription_ref' => self::WRONG_SUBSCRIPTION]);
        $results = $this->race([['binding_id' => $right['id']], ['binding_id' => $wrong['id']]]);
        $invoices = DB::table('production_membership_billing_invoices')->get();
        $this->observe('race-right-vs-wrong: '.$this->summarise($results).' invoices='.count($invoices).' owner='.($invoices[0]->subscription_binding_id === $right['id'] ? 'right' : 'wrong'));
        $this->assertCount(1, $invoices);
        $this->assertSame($right['id'], $invoices[0]->subscription_binding_id);
        $byBinding = array_column($results, null, 'binding_id');
        $this->assertSame(['saved', 'settled'], [$byBinding[$right['id']]['result'], $byBinding[$right['id']]['outcome']]);
        $this->assertSame(['denied', 'binding_refused_subscription'], [$byBinding[$wrong['id']]['result'], $byBinding[$wrong['id']]['reason']]);
    }

    /** Native: the same binding races itself through the claim (both settled): one identity, a contiguous two-row chain. */
    public function test_probe_native_same_binding_races_the_claim(): void
    {
        $this->requireMysql();
        $binding = F::binding();
        $results = $this->race([['binding_id' => $binding['id']], ['binding_id' => $binding['id']]]);
        $invoices = DB::table('production_membership_billing_invoices')->get();
        $chain = count($invoices) === 1 ? (new BillingLedger)->observations($invoices[0]->id) : [];
        $this->observe('race-same-binding-settled: '.$this->summarise($results).' invoices='.count($invoices).' chain='.implode(',', array_column($chain, 'sequence')));
        $this->assertCount(1, $invoices);
        $this->assertSame(['saved', 'saved'], array_column($results, 'result'));
        $this->assertSame([1, 2], array_column($chain, 'sequence'));
    }

    /**
     * Native, head af089d40+: two deliveries of ONE event id race the INSERT. The test holds a gap lock on the event id so both
     * pass the duplicate pre-check and block on INSERT; the loser takes the concurrent-insert branch. Every sync dispatch is
     * lost (no gateway bound). Expect one event row, the winner's dispatch from the main path and the loser's from recovery.
     */
    public function test_probe_native_concurrent_insert_loser_recovers_the_lost_dispatch(): void
    {
        $this->requireMysql();
        F::binding();
        $payload = $this->event('evt_SYNTHETICRACE');
        $signature = WebhookSignature::generateSignatureHeader($payload, self::SECRET);
        $table = (new \App\Domain\Memberships\Billing\BillingSchema)->table(\App\Domain\Memberships\Billing\BillingSchema::TABLES[3]);
        $hash = \App\Domain\Memberships\Billing\BillingValues::hash('event', F::ACCOUNT, 'test', 'evt_SYNTHETICRACE');
        $waiters = -1;
        $results = $this->race([['payload' => $payload, 'signature' => $signature], ['payload' => $payload, 'signature' => $signature]],
            'addendum1-intake-race-worker.php', function (array $connections) use (&$waiters): void {
                $deadline = microtime(true) + 100;
                do {
                    usleep(50000);
                    $waiters = (int) DB::selectOne("SELECT COUNT(*) AS c FROM information_schema.PROCESSLIST WHERE ID IN (?, ?) AND INFO LIKE 'INSERT INTO%'", $connections)->c;
                } while ($waiters < 2 && microtime(true) < $deadline);
                $states = DB::select('SELECT ID, COMMAND, STATE, TIME, LEFT(INFO, 40) AS info FROM information_schema.PROCESSLIST WHERE ID IN (?, ?)', $connections);
                $lockWaits = (int) DB::selectOne("SELECT COUNT(*) AS c FROM information_schema.innodb_trx WHERE trx_state = 'LOCK WAIT' AND trx_mysql_thread_id IN (?, ?)", $connections)->c;
                fwrite(STDERR, 'DIAG intake-race before release: inserting='.$waiters.' innodb_lock_wait='.$lockWaits.' '.json_encode($states).PHP_EOL);
                DB::commit();
            }, false, function () use ($table, $hash): void {
                DB::beginTransaction();
                DB::select('SELECT id FROM '.$table.' WHERE provider_event_ref_hash = ? FOR UPDATE', [$hash]);
            });
        $source = file(base_path('app/Domain/Memberships/Billing/BillingWebhookIntake.php'));
        $loserLine = null;
        foreach ($source as $index => $line) {
            if (str_contains($line, 'recoverLostDispatch($winner')) {
                $loserLine = $index + 1;
            }
        }
        $recovered = array_values(array_filter($results, fn ($r) => (bool) array_filter($r['intake_frames'], fn ($f) => str_starts_with($f, 'recoverLostDispatch@'))));
        $main = array_values(array_filter($results, fn ($r) => ! array_filter($r['intake_frames'], fn ($f) => str_starts_with($f, 'recoverLostDispatch@'))));
        $events = DB::table('production_membership_billing_events')->count();
        Queue::fake();
        $this->at(30);
        $later = $this->receive($payload);
        $this->observe(sprintf('intake-insert-race: lock_waiters_before_release=%d %s events=%d loser_branch_line=%s; sequential redelivery afterwards scheduled=%s pushed=%d observations=%d',
            $waiters, implode(' ', array_map(fn ($r) => sprintf('[w%d %s %s frames=%s]', $r['worker'], $r['outcome'], $r['error_class'] ?? $r['reason'] ?? '-', implode('>', $r['intake_frames'])), $results)),
            $events, (string) $loserLine, $this->flag($later['scheduled']), Queue::pushed(RetrieveMembershipInvoice::class)->count(), $this->observationCount()));
        $this->assertSame(2, $waiters);
        $this->assertSame(1, $events);
        $this->assertCount(1, $recovered);
        $this->assertCount(1, $main);
        $this->assertContains('recoverLostDispatch@BillingWebhookIntake.php:'.$loserLine, $recovered[0]['intake_frames']);
        $this->assertSame(['threw', 'threw'], array_column($results, 'outcome'));
    }

    /**
     * Native (Codex, R-6 candidate): two overlapping retrievals of one invoice. The older SETTLED snapshot is appended after a
     * newer REVERSED one. Records which observation currentSettled() names.
     */
    public function test_probe_native_stale_settled_appended_after_reversal(): void
    {
        $this->requireMysql();
        CarbonImmutable::setTestNow();
        $binding = F::binding();
        $results = $this->race([['role' => 'stale', 'binding_id' => $binding['id']], ['role' => 'fresh', 'binding_id' => $binding['id']]],
            'addendum1-stale-race-worker.php', null, false);
        $invoiceId = DB::table('production_membership_billing_invoices')->value('id');
        $ledger = new BillingLedger;
        $chain = $ledger->observations($invoiceId);
        $current = $ledger->currentSettled($invoiceId, time());
        $this->observe(sprintf('stale-after-reversal: %s chain=%s currentSettled=%s',
            implode(' ', array_map(fn ($r) => sprintf('[%s %s seq=%s outcome=%s retrieved_at=%s created_at=%s err=%s]', $r['role'], $r['result'], $r['sequence'] ?? '-', $r['outcome'] ?? '-',
                $r['retrieved_at'] ?? '-', $r['created_at'] ?? '-', $r['reason'] ?? $r['error_class'] ?? '-'), $results)),
            implode(',', array_map(fn ($o) => $o['sequence'].':'.$o['outcome'].'@retrieved '.$o['retrieved_at'], $chain)),
            $current === null ? 'null' : 'seq '.$current['sequence'].' '.$current['outcome'].' retrieved '.$current['retrieved_at']));
        $this->assertSame(['reversed', 'settled'], array_column($chain, 'outcome'));
        $this->assertLessThan($chain[0]['retrieved_at'], $chain[1]['retrieved_at']);
        $this->assertNotNull($current);
        $this->assertSame(2, $current['sequence']);
    }

    private function race(array $inputs, string $script = 'addendum1-race-worker.php', ?Closure $released = null, bool $standardChecks = true, ?Closure $beforeStart = null): array
    {
        $this->assertSame(0, DB::transactionLevel());
        $directory = storage_path('framework/testing/review-a1-race-'.Str::uuid());
        $filesystem = new Filesystem;
        $filesystem->makeDirectory($directory, 0700, true);
        $processes = [];
        try {
            foreach ($inputs as $worker => $input) {
                $process = new Process([PHP_BINARY, base_path('docs/verification/membership-operative-1-20261007/independent-review/review-evidence/addendum1/probes/'.$script)],
                    base_path(), $this->environment($directory, $worker), json_encode($input, JSON_THROW_ON_ERROR), 420);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 60;
            do {
                clearstatcache();
                if (is_file($directory.'/ready-0') && is_file($directory.'/ready-1')) {
                    break;
                }
                foreach ($processes as $process) {
                    if (! $process->isRunning()) {
                        $this->fail('Reviewer race worker exited: '.$process->getOutput().$process->getErrorOutput());
                    }
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            if ($beforeStart !== null) {
                $beforeStart();
            }
            touch($directory.'/start');
            if ($released !== null) {
                $released([(int) file_get_contents($directory.'/ready-0'), (int) file_get_contents($directory.'/ready-1')]);
            }
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
            }
            $this->assertCount(3, array_unique([...array_column($results, 'pid'), getmypid()]));
            $this->assertCount(2, array_unique(array_column($results, 'connection_id')));
            $this->assertSame([0, 0], array_column($results, 'transaction_level'));
            if ($standardChecks) {
                $this->assertSame([true, true], array_column($results, 'barrier_passed'));
            }

            return $results;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            $filesystem->deleteDirectory($directory);
        }
    }

    private function environment(string $directory, int $worker): array
    {
        $db = DB::connection()->getConfig();

        return ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => (string) config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
            'DB_HOST' => (string) $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => (string) $db['database'],
            'DB_USERNAME' => (string) $db['username'], 'DB_PASSWORD' => (string) $db['password'], 'DB_SOCKET' => (string) ($db['unix_socket'] ?? ''),
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'VA_REVIEW_A1_RACE_ONLY' => '1', 'VA_REVIEW_A1_RACE_DIRECTORY' => $directory, 'VA_REVIEW_A1_RACE_WORKER' => (string) $worker];
    }

    private function requireMysql(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Native MySQL race probe: two processes behind a barrier.');
        }
    }

    private function hookedGateway(RehearsalBillingGateway $inner, Closure $afterInvoice): BillingProviderGateway
    {
        return new class($inner, $afterInvoice) implements BillingProviderGateway
        {
            public function __construct(private RehearsalBillingGateway $inner, private Closure $afterInvoice) {}

            public function provenance(): string
            {
                return $this->inner->provenance();
            }

            public function account(): array
            {
                return $this->inner->account();
            }

            public function retrieveInvoice(string $ref): array
            {
                $invoice = $this->inner->retrieveInvoice($ref);
                ($this->afterInvoice)();

                return $invoice;
            }

            public function listInvoicePayments(string $invoiceRef): array
            {
                return $this->inner->listInvoicePayments($invoiceRef);
            }

            public function retrievePaymentIntent(string $ref): array
            {
                return $this->inner->retrievePaymentIntent($ref);
            }

            public function retrieveCharge(string $ref): array
            {
                return $this->inner->retrieveCharge($ref);
            }

            public function retrieveBalanceTransaction(string $ref): array
            {
                return $this->inner->retrieveBalanceTransaction($ref);
            }

            public function retrieveSubscription(string $ref): array
            {
                return $this->inner->retrieveSubscription($ref);
            }
        };
    }

    private function summarise(array $results): string
    {
        return implode(' ', array_map(fn ($r) => sprintf('[w%d %s seq=%s outcome=%s reason=%s err=%s]', $r['worker'], $r['result'], $r['sequence'] ?? '-',
            $r['outcome'] ?? '-', $r['reason'] ?? '-', $r['error_class'] ?? '-'), $results));
    }

    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    private function reason(array $observation): string
    {
        return (string) (\App\Domain\Memberships\Billing\BillingValues::decrypt($observation['payload_ciphertext'])['reason'] ?? '-');
    }

    private function flag(?array $scheduled): string
    {
        return $scheduled === null ? 'null' : 'dispatched';
    }

    private function observationCount(): int
    {
        return DB::table('production_membership_billing_observations')->count();
    }

    private function at(int $offset): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC(self::T0 + $offset));
    }

    private function receive(string $payload): array
    {
        return (new BillingWebhookIntake)->receive($payload, WebhookSignature::generateSignatureHeader($payload, self::SECRET));
    }

    private function event(string $id, string $type = 'invoice.paid', array $object = []): string
    {
        $values = ['id' => $id, 'object' => 'event', 'api_version' => BillingProviderPin::API_VERSION, 'created' => F::PERIOD_START,
            'livemode' => false, 'pending_webhooks' => 1, 'request' => ['id' => null, 'idempotency_key' => null], 'type' => $type,
            'data' => ['object' => [...F::graph()['invoice'], ...$object]]];

        return json_encode(F::sdk(StripeEvent::class, $values), JSON_THROW_ON_ERROR);
    }

    private function observe(string $line): void
    {
        fwrite(STDERR, 'OBSERVATION '.$line.PHP_EOL);
        $this->addToAssertionCount(0);
    }
}
