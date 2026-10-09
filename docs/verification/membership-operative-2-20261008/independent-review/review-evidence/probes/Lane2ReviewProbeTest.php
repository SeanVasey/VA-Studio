<?php

namespace Tests\Feature\ReviewProbes;

use App\Domain\Memberships\Billing\BillingException;
use App\Domain\Memberships\Billing\BillingHintSweep;
use App\Domain\Memberships\Billing\BillingLedger;
use App\Domain\Memberships\Billing\BillingPolicy;
use App\Domain\Memberships\Billing\BillingProviderPin;
use App\Domain\Memberships\Billing\BillingReconciliation;
use App\Domain\Memberships\Billing\BillingSchema;
use App\Domain\Memberships\Billing\BillingWebhookIntake;
use App\Jobs\RetrieveMembershipInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Stripe\Event;
use Stripe\WebhookSignature;
use Symfony\Component\Process\Process;
use Tests\Support\BillingStripeFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\InterleavingBillingGateway;
use Tests\Support\RehearsalBillingGateway;
use Tests\TestCase;

/**
 * Independent reviewer probes for membership operative lane 2 (PR #54, head 6219f283). Evidence only; not part of the suite.
 * Each probe asserts the OBSERVED behaviour and writes one OBSERVATION line to STDERR. Synthetic values only. Methods named
 * test_native_* need MySQL (two processes) and skip elsewhere; the others are driver-agnostic and are run on SQLite.
 */
class Lane2ReviewProbeTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const SECRET = 'whsec_SYNTHETICREHEARSAL';

    private const T0 = F::PERIOD_START + 3600;

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

    /**
     * Sweep reachability: scan() examines the OLDEST --limit retained hints (ORDER BY received_at, id). When those are all covered,
     * a newer uncovered hint is never examined, whatever --dispatch does; --limit is capped at MAX_HINTS (1000) and hints are retained
     * forever, so past 1000 retained hints the newest uncovered ones are unreachable.
     */
    public function test_probe_sweep_examines_only_the_oldest_hints_so_a_newer_uncovered_one_is_unreachable_past_the_limit(): void
    {
        $binding = F::binding();
        foreach (range(1, 6) as $n) {
            $this->at($n);
            $this->lose('evt_SYNTHETICOLD'.$n, 'invoice.updated');
        }
        $this->at(10);
        (new BillingReconciliation(new RehearsalBillingGateway(F::graph())))->retrieve($binding['id'], F::INVOICE);
        $this->at(20);
        $this->lose('evt_SYNTHETICNEW', 'invoice.paid', 'in_SYNTHETICNEWER');
        $this->at(30);
        Queue::fake();
        $exit5 = Artisan::call('membership-billing:sweep-hints', ['--dispatch' => true, '--limit' => 5]);
        $out5 = Artisan::output();
        $pushed5 = Queue::pushed(RetrieveMembershipInvoice::class)->count();
        Queue::fake();
        $exit7 = Artisan::call('membership-billing:sweep-hints', ['--dispatch' => true, '--limit' => 7]);
        $pushed7 = Queue::pushed(RetrieveMembershipInvoice::class)->count();
        $exitBig = Artisan::call('membership-billing:sweep-hints', ['--limit' => BillingHintSweep::MAX_HINTS + 1]);
        $this->observe(sprintf('sweep-oldest-first: hints=7 (6 covered old, 1 uncovered newest); limit=5 exit=%d pushed=%d truncated_warning=%s; limit=7 exit=%d pushed=%d; limit=%d exit=%d (cap)',
            $exit5, $pushed5, str_contains($out5, 'More retained hints') ? 'yes' : 'no', $exit7, $pushed7, BillingHintSweep::MAX_HINTS + 1, $exitBig));
        $this->assertSame([0, 1], [$pushed5, $pushed7]);
    }

    /**
     * Unroutable hints: a hint whose subscription no binding owns, and an invoice_payment hint for an invoice with no identity, are
     * counted in the dry run's "need nothing" total, so the operator cannot tell "covered" from "cannot be routed".
     */
    public function test_probe_sweep_counts_unroutable_hints_as_needing_nothing(): void
    {
        F::binding();
        $this->lose('evt_SYNTHETICUNBOUND', 'invoice.paid', 'in_SYNTHETICUNBOUND', 'sub_UNBOUNDSYNTHETIC');
        $this->lose('evt_SYNTHETICPAYMENT', 'invoice_payment.paid', 'in_SYNTHETICNOIDENTITY');
        $this->at(30);
        Queue::fake();
        $exit = Artisan::call('membership-billing:sweep-hints');
        $out = trim(Artisan::output());
        $events = DB::table('production_membership_billing_events')->pluck('disposition')->all();
        $this->observe(sprintf('sweep-unroutable: dispositions=%s exit=%d output="%s"', implode(',', $events), $exit, str_replace("\n", ' | ', $out)));
        $this->assertStringContainsString('0 uncovered hint(s)', $out);
    }

    /**
     * Superseded by an inconclusive retrieval: a definitive (reversed) read that began first is refused because a later retrieval
     * that ended `unknown` already appended. The job ends normally for the refused one; the tail is `unknown`.
     */
    public function test_probe_a_definitive_read_superseded_by_a_later_unknown_is_dropped(): void
    {
        $binding = F::binding();
        $seed = (new BillingReconciliation(new RehearsalBillingGateway(F::graph())))->retrieve($binding['id'], F::INVOICE);
        $this->at(5);
        $refunded = F::graph(['charge' => ['refunded' => true, 'amount_refunded' => F::AMOUNT]]);
        $fresh = null;
        $gateway = new InterleavingBillingGateway(new RehearsalBillingGateway($refunded), function () use ($binding, &$fresh): void {
            $this->at(8);
            $fresh = (new BillingReconciliation(new RehearsalBillingGateway(F::graph(), 'invoice')))->retrieve($binding['id'], F::INVOICE);
            $this->at(9);
        });
        try {
            (new RetrieveMembershipInvoice($binding['id'], F::INVOICE))->handle(new BillingReconciliation($gateway));
            $jobEnd = 'returned';
        } catch (\Throwable $error) {
            $jobEnd = 'threw '.$error::class;
        }
        $ledger = new BillingLedger;
        $chain = $ledger->observations($seed['invoice_id']);
        $this->observe(sprintf('superseded-by-unknown: fresh=%s seq=%d; stale(reversed) job=%s; chain=%s currentSettled=%s',
            $fresh['outcome'], $fresh['sequence'], $jobEnd, implode(',', array_column($chain, 'outcome')),
            $ledger->currentSettled($seed['invoice_id'], self::T0 + 10) === null ? 'null' : 'set'));
        $this->assertSame(['settled', 'unknown'], array_column($chain, 'outcome'));
        $this->assertSame('returned', $jobEnd);
    }

    /** APP_KEY rotation: the job's sealed invoice ref decrypts under APP_PREVIOUS_KEYS and fails closed (no plaintext) without it. */
    public function test_probe_job_ciphertext_under_app_key_rotation(): void
    {
        $binding = F::binding();
        $serialized = serialize(new RetrieveMembershipInvoice($binding['id'], F::INVOICE));
        $old = (string) config('app.key');
        $new = 'base64:'.base64_encode(str_repeat('R', 32));
        try {
            $this->rekey($new, [$old]);
            $withPrevious = unserialize($serialized)->invoiceRef();
            $this->rekey($new, []);
            try {
                unserialize($serialized)->invoiceRef();
                $without = 'decrypted';
            } catch (BillingException $error) {
                $without = 'refused '.$error->reason.' message="'.$error->getMessage().'"';
            }
        } finally {
            $this->rekey($old, []);
        }
        $this->observe(sprintf('app-key-rotation: plaintext_in_serialized=%s; with previous_keys=%s; without=%s',
            str_contains($serialized, F::INVOICE) ? 'yes' : 'no', $withPrevious === F::INVOICE ? 'decrypted' : 'other', $without));
        $this->assertSame(F::INVOICE, $withPrevious);
        $this->assertStringStartsWith('refused ciphertext', $without);
        $this->assertStringNotContainsString(F::INVOICE, $without);
    }

    /** Deterministic refusals are retried: a first-retrieval wrong binding throws on every attempt (no fail-fast). */
    public function test_probe_a_deterministic_first_retrieval_refusal_is_thrown_each_attempt(): void
    {
        $wrong = F::binding(['subscription_ref' => 'sub_WRONGSYNTHETIC']);
        $reasons = [];
        foreach (range(1, 3) as $attempt) {
            try {
                (new RetrieveMembershipInvoice($wrong['id'], F::INVOICE))->handle(new BillingReconciliation(new RehearsalBillingGateway(F::graph())));
                $reasons[] = 'none';
            } catch (BillingException $error) {
                $reasons[] = $error->reason;
            }
        }
        $this->observe('deterministic-refusal: attempts='.implode(',', $reasons).' (each a full provider read; the queue would retry with the 60/600 s backoff)');
        $this->assertSame(array_fill(0, 3, 'binding_refused_subscription'), $reasons);
    }

    /**
     * Native: two retrievals of one invoice reach the append at the same instant (barrier after both finished reading), the one that
     * began FIRST read settled, the second read refunded. Several rounds. The append lock must order them by start: the first may
     * save before the second, or be refused after it, but never become the tail after it. Any deadlock or contention error is recorded.
     */
    public function test_native_concurrent_appends_are_ordered_by_start_under_the_lock(): void
    {
        $this->requireMysql();
        CarbonImmutable::setTestNow();
        $binding = F::binding();
        $seed = (new BillingReconciliation(new RehearsalBillingGateway(F::graph())))->retrieve($binding['id'], F::INVOICE);
        $rounds = [];
        foreach (range(1, 6) as $round) {
            $results = $this->spawn('lane2-lock-race-worker.php', [['role' => 'first', 'binding_id' => $binding['id']], ['role' => 'second', 'binding_id' => $binding['id']]],
                function (string $directory): void {
                    $this->waitFor($directory, ['read-first', 'read-second'], 60);
                    $this->signal($directory, 'go');
                });
            $by = array_column($results, null, 'role');
            $rounds[] = sprintf('r%d[first %s %s seq=%s start=%s | second %s %s seq=%s start=%s]', $round, $by['first']['result'], $by['first']['reason'] ?? $by['first']['outcome'] ?? '-',
                $by['first']['sequence'] ?? '-', substr((string) ($by['first']['started_at'] ?? $by['first']['began'] ?? '-'), 11), $by['second']['result'], $by['second']['reason'] ?? $by['second']['outcome'] ?? '-',
                $by['second']['sequence'] ?? '-', substr((string) ($by['second']['started_at'] ?? $by['second']['began'] ?? '-'), 11));
            $this->assertNotContains('unexpected', array_column($results, 'result'), json_encode($results));
            $this->assertSame('saved', $by['second']['result'], json_encode($results));
            if ($by['first']['result'] === 'saved') {
                $this->assertLessThan($by['second']['sequence'], $by['first']['sequence']);
            } else {
                $this->assertSame('superseded_retrieval', $by['first']['reason']);
            }
        }
        $chain = (new BillingLedger)->observations($seed['invoice_id']);
        $starts = array_column($chain, 'retrieval_started_at');
        $sorted = $starts;
        sort($sorted, SORT_STRING);
        $this->observe('native-lock-race: '.implode(' ', $rounds).' chain='.implode(',', array_map(fn ($o) => $o['sequence'].':'.$o['outcome'], $chain))
            .' starts_monotonic='.($starts === $sorted ? 'yes' : 'no').' tail='.$chain[array_key_last($chain)]['outcome']);
        $this->assertSame($sorted, $starts);
        $this->assertSame('reversed', $chain[array_key_last($chain)]['outcome']);
    }

    /**
     * Native: while one connection holds the append lock (SELECT ... FOR UPDATE on the invoice identity row, in an open transaction),
     * a second process runs a duplicate webhook delivery for that invoice (coverage + dispatch), the sweep scan, a retrieval of the
     * same invoice, and a retrieval of another invoice. Records which of them wait for the lock.
     */
    public function test_native_append_lock_blocks_only_appends_of_the_same_invoice(): void
    {
        $this->requireMysql();
        CarbonImmutable::setTestNow();
        $binding = F::binding();
        $seed = (new BillingReconciliation(new RehearsalBillingGateway(F::graph())))->retrieve($binding['id'], F::INVOICE);
        $payload = $this->event('evt_SYNTHETICLOCK', 'invoice.paid', F::INVOICE);
        try {
            $this->receive($payload);
        } catch (BindingResolutionException) {
            // Lost dispatch: the duplicate delivery below takes the recovery path.
        }
        $hold = 8.0;
        $held = null;
        $results = $this->spawn('lane2-lock-reader-worker.php', [['binding_id' => $binding['id'], 'payload' => $payload,
            'signature' => WebhookSignature::generateSignatureHeader($payload, self::SECRET)]], function (string $directory) use ($seed, $hold, &$held): void {
                DB::beginTransaction();
                $statement = DB::connection()->getPdo()->prepare('SELECT id FROM '.(new BillingSchema)->table(BillingSchema::TABLES[1]).' WHERE id = ? FOR UPDATE');
                $statement->execute([$seed['invoice_id']]);
                $statement->fetchAll();
                $this->signal($directory, 'locked');
                $begin = microtime(true);
                usleep((int) ($hold * 1000000));
                $held = microtime(true) - $begin;
                DB::commit();
            }, 1);
        $r = $results[0];
        $this->observe(sprintf('native-lock-readers: lock held %.1fs; duplicate-delivery %.2fs (%s); sweep-scan %.2fs (%s); same-invoice retrieve %.2fs (%s); other-invoice claim+append %.2fs (%s)',
            $held, $r['intake_s'], $r['intake'], $r['sweep_s'], $r['sweep'], $r['same_s'], $r['same'], $r['other_s'], $r['other']));
        $this->assertLessThan(2.0, $r['intake_s']);
        $this->assertLessThan(2.0, $r['sweep_s']);
        $this->assertGreaterThan(3.0, $r['same_s']);
    }

    /**
     * Native: which half of a DIFFERENT invoice's write waits for another invoice's append lock. The parent holds SELECT ... FOR UPDATE
     * on invoice A's identity row for 8 s; the worker times the claim of invoice B's identity row, then an append to invoice B.
     */
    public function test_native_cross_invoice_wait_split_between_claim_and_append(): void
    {
        $this->requireMysql();
        CarbonImmutable::setTestNow();
        $binding = F::binding();
        $seed = (new BillingReconciliation(new RehearsalBillingGateway(F::graph())))->retrieve($binding['id'], F::INVOICE);
        $held = null;
        $results = $this->spawn('lane2-cross-invoice-worker.php', [['mode' => 'split', 'binding_id' => $binding['id'], 'invoice' => 'in_SYNTHETICSPLIT']],
            function (string $directory) use ($seed, &$held): void {
                DB::beginTransaction();
                $statement = DB::connection()->getPdo()->prepare('SELECT id FROM '.(new BillingSchema)->table(BillingSchema::TABLES[1]).' WHERE id = ? FOR UPDATE');
                $statement->execute([$seed['invoice_id']]);
                $statement->fetchAll();
                $this->signal($directory, 'locked');
                $begin = microtime(true);
                usleep(8000000);
                $held = microtime(true) - $begin;
                DB::commit();
            }, 1);
        $r = $results[0];
        $this->observe(sprintf('native-cross-invoice-split: lock on invoice A held %.1fs; claim of invoice B %.2fs (%s); append to invoice B %.2fs (%s)',
            $held, $r['claim_s'], $r['claim'], $r['append_s'], $r['append']));
        $this->assertStringStartsWith('claimed', $r['claim']);
    }

    /**
     * Native: four workers, four DIFFERENT invoices, released together; each makes five retrievals of its own invoice (first one
     * claims). Records every outcome and the server's InnoDB deadlock counter before and after.
     */
    public function test_native_concurrent_retrievals_of_different_invoices(): void
    {
        $this->requireMysql();
        CarbonImmutable::setTestNow();
        $binding = F::binding();
        $deadlocks = fn (): int => (int) DB::selectOne("SELECT COUNT AS c FROM information_schema.INNODB_METRICS WHERE NAME = 'lock_deadlocks'")->c;
        $enabled = (string) DB::selectOne("SELECT STATUS AS s FROM information_schema.INNODB_METRICS WHERE NAME = 'lock_deadlocks'")->s;
        $before = $deadlocks();
        $inputs = array_map(fn ($n) => ['mode' => 'storm', 'binding_id' => $binding['id'], 'invoice' => 'in_SYNTHETICSTORM'.$n, 'rounds' => 5], range(1, 4));
        $results = $this->spawn('lane2-cross-invoice-worker.php', $inputs, function (): void {}, 4);
        $after = $deadlocks();
        $all = array_merge(...array_column($results, 'outcomes'));
        $failed = array_values(array_filter($all, fn ($o) => ! str_starts_with($o, 'saved')));
        $status = DB::selectOne('SHOW ENGINE INNODB STATUS');
        $text = (string) ($status->Status ?? '');
        $hasDeadlock = str_contains($text, 'LATEST DETECTED DEADLOCK');
        $this->observe(sprintf('native-cross-invoice-storm: metric(lock_deadlocks,%s) before=%d after=%d; retrievals=%d failed=%d [%s]; innodb_status_latest_deadlock=%s; per-worker: %s',
            $enabled, $before, $after, count($all), count($failed), implode(' | ', array_unique(array_map(fn ($f) => preg_replace('/ \(.*\)$/', '', $f), $failed))),
            $hasDeadlock ? 'present' : 'none', implode(' ; ', array_map(fn ($r) => substr($r['invoice'], -6).'='.implode(',', array_map(fn ($o) => preg_replace('/^saved seq=(\d+) /', '$1', $o), $r['outcomes'])), $results))));
        if ($hasDeadlock) {
            $start = strpos($text, 'LATEST DETECTED DEADLOCK');
            fwrite(STDERR, 'DEADLOCK-SECTION '.str_replace("\n", ' / ', substr($text, $start, 2400)).PHP_EOL);
        }
        $this->assertCount(20, $all);
    }

    private function spawn(string $script, array $inputs, \Closure $parent, int $expected = 2): array
    {
        $this->assertSame(0, DB::transactionLevel());
        $directory = storage_path('framework/testing/review-lane2-'.Str::uuid());
        (new Filesystem)->makeDirectory($directory, 0700, true);
        $processes = [];
        try {
            foreach ($inputs as $worker => $input) {
                $process = new Process([PHP_BINARY, base_path('docs/verification/membership-operative-2-20261008/independent-review/review-evidence/probes/'.$script)],
                    base_path(), $this->environment($directory, $worker), json_encode($input, JSON_THROW_ON_ERROR), 300);
                $process->start();
                $processes[] = $process;
            }
            $this->waitFor($directory, array_map(fn ($w) => 'ready-'.$w, array_keys($inputs)), 90, $processes);
            $this->signal($directory, 'start');
            $parent($directory);
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
            }
            $this->assertCount($expected, $results);

            return $results;
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            (new Filesystem)->deleteDirectory($directory);
        }
    }

    private function waitFor(string $directory, array $names, int $seconds, array $processes = []): void
    {
        $deadline = microtime(true) + $seconds;
        while (true) {
            clearstatcache();
            if (array_filter($names, fn ($n) => ! is_file($directory.'/'.$n)) === []) {
                return;
            }
            foreach ($processes as $process) {
                if (! $process->isRunning()) {
                    $this->fail('Reviewer worker exited early: '.$process->getOutput().$process->getErrorOutput());
                }
            }
            if (microtime(true) > $deadline) {
                $this->fail('Reviewer barrier timed out: '.implode(',', $names));
            }
            usleep(5000);
        }
    }

    private function signal(string $directory, string $name): void
    {
        file_put_contents($directory.'/'.$name.'.tmp', '1');
        rename($directory.'/'.$name.'.tmp', $directory.'/'.$name);
    }

    private function environment(string $directory, int $worker): array
    {
        $db = DB::connection()->getConfig();

        return ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => (string) config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
            'DB_HOST' => (string) $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => (string) $db['database'],
            'DB_USERNAME' => (string) $db['username'], 'DB_PASSWORD' => (string) $db['password'], 'DB_SOCKET' => (string) ($db['unix_socket'] ?? ''),
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'VA_REVIEW_LANE2_ONLY' => '1', 'VA_REVIEW_LANE2_DIRECTORY' => $directory, 'VA_REVIEW_LANE2_WORKER' => (string) $worker];
    }

    private function requireMysql(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Native MySQL reviewer probe: separate processes and connections.');
        }
    }

    private function rekey(string $key, array $previous): void
    {
        config(['app.key' => $key, 'app.previous_keys' => $previous]);
        $this->app->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
    }

    private function lose(string $eventId, string $type = 'invoice.paid', string $invoiceRef = F::INVOICE, string $subscription = F::SUBSCRIPTION): void
    {
        try {
            $this->receive($this->event($eventId, $type, $invoiceRef, $subscription));
        } catch (BindingResolutionException) {
            // The lane binds no gateway, so the post-commit dispatch fails, as a queue outage would.
        }
    }

    private function receive(string $payload): array
    {
        return (new BillingWebhookIntake)->receive($payload, WebhookSignature::generateSignatureHeader($payload, self::SECRET));
    }

    private function event(string $id, string $type, string $invoiceRef, string $subscription = F::SUBSCRIPTION): string
    {
        $object = $type === 'invoice_payment.paid'
            ? ['id' => 'inpay_SYNTHETICPROBE', 'object' => 'invoice_payment', 'invoice' => $invoiceRef]
            : [...F::graph()['invoice'], 'id' => $invoiceRef, 'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => $subscription]]];
        $values = ['id' => $id, 'object' => 'event', 'api_version' => BillingProviderPin::API_VERSION, 'created' => F::PERIOD_START,
            'livemode' => false, 'pending_webhooks' => 1, 'request' => ['id' => null, 'idempotency_key' => null], 'type' => $type, 'data' => ['object' => $object]];

        return json_encode(F::sdk(Event::class, $values), JSON_THROW_ON_ERROR);
    }

    private function at(int $offset): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC(self::T0 + $offset));
    }

    private function observe(string $line): void
    {
        fwrite(STDERR, 'OBSERVATION '.$line.PHP_EOL);
        $this->addToAssertionCount(1);
    }
}
