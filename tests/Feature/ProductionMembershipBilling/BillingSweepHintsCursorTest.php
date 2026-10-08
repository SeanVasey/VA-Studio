<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Memberships\Billing\BillingHintSweep;
use App\Domain\Memberships\Billing\BillingProviderPin;
use App\Domain\Memberships\Billing\BillingReconciliation;
use App\Domain\Memberships\Billing\BillingValues;
use App\Domain\Memberships\Billing\BillingWebhookIntake;
use App\Jobs\RetrieveMembershipInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Stripe\Event;
use Stripe\WebhookSignature;
use Tests\Support\BillingStripeFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\RehearsalBillingGateway;
use Tests\TestCase;

/**
 * Codex P1 on PR #54 (review L2-2). Hints are retained forever, so a sweep that only ever examined the oldest `--limit` hints
 * stopped reaching new uncovered ones once that many existed. The sweep now advances: a signed keyset cursor over
 * `(received_at, id)` continues where the previous page ended, and `--all` pages until exhausted under a hard overall bound.
 */
class BillingSweepHintsCursorTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const SECRET = 'whsec_SYNTHETICREHEARSAL';

    private const T0 = F::PERIOD_START + 3600;

    private const OTHER = 'in_SYNTHETICOTHER';

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

    public function test_an_uncovered_hint_beyond_the_first_window_is_reached_by_following_the_cursor(): void
    {
        $binding = $this->coveredHintsThenOneUncovered(4);
        Queue::fake();

        $this->assertSame(0, Artisan::call('membership-billing:sweep-hints', ['--limit' => 3]));
        $first = Artisan::output();
        $this->assertStringNotContainsString($this->prefix('evt_SYNTHETICLATEST'), $first, 'The first window holds only covered hints.');
        $this->assertStringContainsString('Dry run: 0 uncovered hint(s)', $first);
        $cursor = $this->cursor($first);

        $this->assertSame(0, Artisan::call('membership-billing:sweep-hints', ['--limit' => 3, '--after' => $cursor]));
        $second = Artisan::output();
        $this->assertStringContainsString($this->prefix('evt_SYNTHETICLATEST'), $second);
        $this->assertStringContainsString($binding['id'], $second);
        $this->assertStringContainsString('Scan complete', $second);
        $this->assertStringNotContainsString('Next cursor', $second);
        $this->assertNoProviderReference($first.$second);
        Queue::assertNothingPushed();
    }

    public function test_all_pages_until_exhausted_and_reaches_an_uncovered_hint_beyond_the_first_window(): void
    {
        $this->coveredHintsThenOneUncovered(4);
        Queue::fake();

        $this->assertSame(0, Artisan::call('membership-billing:sweep-hints', ['--limit' => 2, '--all' => true]));
        $output = Artisan::output();
        $this->assertStringContainsString($this->prefix('evt_SYNTHETICLATEST'), $output);
        $this->assertStringContainsString('Dry run: 1 uncovered hint(s) across 1 invoice(s); 4 examined hint(s) need nothing.', $output);
        $this->assertStringContainsString('Scan complete: 5 hint(s) examined in 3 page(s).', $output);
        $this->assertStringNotContainsString('Next cursor', $output);
        Queue::assertNothingPushed();
    }

    public function test_all_with_dispatch_queues_one_retrieval_per_uncovered_invoice_across_pages(): void
    {
        $binding = F::binding();
        $this->at(0);
        $this->lose('evt_SYNTHETICX1', self::OTHER);
        foreach ([1, 2, 3] as $offset) {
            $this->at($offset);
            $this->lose('evt_SYNTHETICCOVERED'.$offset, F::INVOICE);
        }
        $this->at(10);
        (new RetrieveMembershipInvoice($binding['id'], F::INVOICE))->handle(new BillingReconciliation(new RehearsalBillingGateway(F::graph())));
        $this->at(20);
        $this->lose('evt_SYNTHETICX2', self::OTHER, 'invoice.updated');
        $this->at(21);
        $this->lose('evt_SYNTHETICY1', 'in_SYNTHETICYONDER');
        $this->at(30);
        Queue::fake();

        // Pages of two: [X1, covered1], [covered2, covered3], [X2, Y1]. X has an uncovered hint on the first and on the last page.
        $this->assertSame(0, Artisan::call('membership-billing:sweep-hints', ['--limit' => 2, '--all' => true, '--dispatch' => true]));
        $output = Artisan::output();
        Queue::assertPushed(RetrieveMembershipInvoice::class, 2);
        Queue::assertPushed(RetrieveMembershipInvoice::class, fn (RetrieveMembershipInvoice $job) => $job->invoiceRef() === self::OTHER);
        Queue::assertPushed(RetrieveMembershipInvoice::class, fn (RetrieveMembershipInvoice $job) => $job->invoiceRef() === 'in_SYNTHETICYONDER');
        $this->assertStringContainsString('Dispatched 2 retrieval(s) for 3 uncovered hint(s).', $output);
        $this->assertStringContainsString('Scan complete: 6 hint(s) examined in 3 page(s).', $output);
        $this->assertSame(1, DB::table('production_membership_billing_observations')->count(), 'The sweep reads no provider and writes no ledger row.');
        $this->assertSame(6, DB::table('production_membership_billing_events')->count());
    }

    public function test_all_stops_at_the_overall_bound_and_reports_the_cursor_that_continues_it(): void
    {
        F::binding();
        foreach (range(1, 5) as $offset) {
            $this->at($offset);
            $this->lose('evt_SYNTHETICBOUND'.$offset, 'in_SYNTHETICBOUND'.$offset);
        }
        $this->at(30);
        $this->app->instance(BillingHintSweep::class, new BillingHintSweep(3));
        Queue::fake();

        $this->assertSame(0, Artisan::call('membership-billing:sweep-hints', ['--limit' => 2, '--all' => true, '--dispatch' => true]));
        $first = Artisan::output();
        $this->assertStringContainsString('Stopped at the overall bound of 3 hint(s)', $first);
        $this->assertStringContainsString('Dispatched 3 retrieval(s) for 3 uncovered hint(s).', $first);
        foreach ([1, 2, 3] as $offset) {
            $this->assertStringContainsString($this->prefix('evt_SYNTHETICBOUND'.$offset), $first);
        }
        $this->assertStringNotContainsString($this->prefix('evt_SYNTHETICBOUND4'), $first);
        Queue::assertPushed(RetrieveMembershipInvoice::class, 3);

        Queue::fake();
        $this->assertSame(0, Artisan::call('membership-billing:sweep-hints', ['--limit' => 2, '--all' => true, '--after' => $this->cursor($first)]));
        $second = Artisan::output();
        $this->assertStringContainsString($this->prefix('evt_SYNTHETICBOUND4'), $second);
        $this->assertStringContainsString($this->prefix('evt_SYNTHETICBOUND5'), $second);
        $this->assertStringNotContainsString($this->prefix('evt_SYNTHETICBOUND3'), $second);
        $this->assertStringContainsString('Scan complete: 2 hint(s) examined in 1 page(s).', $second);
        Queue::assertNothingPushed();
    }

    public function test_an_invalid_tampered_or_foreign_cursor_is_refused_before_anything_is_listed_or_dispatched(): void
    {
        $this->coveredHintsThenOneUncovered(4);
        Artisan::call('membership-billing:sweep-hints', ['--limit' => 3]);
        $cursor = $this->cursor(Artisan::output());
        [$version, $stamp, $id, $mac] = explode('.', $cursor);
        $foreignKey = 'base64:'.base64_encode(str_repeat("\x07", 32));
        $original = config('app.key');
        config(['app.key' => $foreignKey]);
        Artisan::call('membership-billing:sweep-hints', ['--limit' => 3]);
        $foreign = $this->cursor(Artisan::output());
        config(['app.key' => $original]);
        $this->assertNotSame($cursor, $foreign);

        $earlier = (string) DB::table('production_membership_billing_events')->orderBy('received_at')->orderBy('id')->value('id');
        foreach (['garbage', 'v1', '', '.', implode('.', [$version, $stamp, $id]), implode('.', ['v2', $stamp, $id, $mac]),
            implode('.', [$version, '20000101000000', $id, $mac]), implode('.', [$version, $stamp, $earlier, $mac]),
            implode('.', [$version, $stamp, $id, str_repeat('0', 64)]), implode('.', [$version, $stamp, strtoupper($id), $mac]),
            $cursor.'.extra', ' '.$cursor, $foreign] as $bad) {
            Queue::fake();
            $exit = Artisan::call('membership-billing:sweep-hints', ['--limit' => 3, '--after' => $bad, '--dispatch' => true]);
            $output = Artisan::output();
            $this->assertSame(1, $exit, $bad);
            $this->assertStringContainsString('Refused (cursor).', $output, $bad);
            $this->assertStringNotContainsString('uncovered event=', $output, $bad);
            Queue::assertNothingPushed();
        }
        // A cursor minted under another provider account is refused too.
        F::configure(['account_ref' => 'acct_SYNTHETICELSEWHERE']);
        $this->assertSame(1, Artisan::call('membership-billing:sweep-hints', ['--after' => $cursor]));
        $this->assertStringContainsString('Refused (cursor).', Artisan::output());
    }

    public function test_the_policy_is_checked_before_the_cursor_or_any_ledger_read(): void
    {
        $this->coveredHintsThenOneUncovered(4);
        Artisan::call('membership-billing:sweep-hints', ['--limit' => 3]);
        $cursor = $this->cursor(Artisan::output());
        config(['production-membership-billing.enabled' => false]);
        Queue::fake();
        foreach ([['--after' => $cursor], ['--after' => 'garbage', '--all' => true, '--dispatch' => true]] as $options) {
            $this->assertSame(1, Artisan::call('membership-billing:sweep-hints', $options));
            $output = Artisan::output();
            $this->assertStringContainsString('Refused (disabled).', $output);
            $this->assertStringNotContainsString('uncovered event=', $output);
        }
        Queue::assertNothingPushed();
    }

    public function test_a_forged_hint_beyond_the_first_page_still_fails_the_sweep_closed(): void
    {
        $this->coveredHintsThenOneUncovered(4);
        // A structurally valid hint row (the guards admit it) whose seal is not its canonical row hash, newest of all.
        $position = DB::table('production_membership_billing_positions')->insertGetId(['kind' => 'hint', 'created_at' => '2026-10-07 00:00:00']);
        $row = (array) DB::table('production_membership_billing_events')->orderByDesc('received_at')->orderByDesc('id')->first();
        DB::table('production_membership_billing_events')->insert([...$row, 'id' => BillingValues::id(), 'hint_position' => $position,
            'provider_event_ref_hash' => hash('sha256', 'forged synthetic event'), 'received_at' => BillingValues::utc(self::T0 + 25),
            'created_at' => BillingValues::utc(self::T0 + 25), 'seal' => hash('sha256', 'forged')]);
        Queue::fake();

        $this->assertSame(1, Artisan::call('membership-billing:sweep-hints', ['--limit' => 2, '--all' => true, '--dispatch' => true]));
        $output = Artisan::output();
        $this->assertStringContainsString('Refused (tampered_ledger).', $output);
        $this->assertStringNotContainsString('Dispatched', $output);
        Queue::assertNothingPushed();
    }

    /**
     * Independent review CP-4: the keyset's tie-break `(received_at = ? AND id > ?)` is what continues a page that ended inside one
     * intake second. Five uncovered hints share one `received_at`; pages of two must examine each exactly once and miss none, and a
     * page of one per hint must reach and dispatch all five. Without the tie-break the second page finds nothing after the first.
     */
    public function test_hints_sharing_one_received_at_second_are_each_examined_exactly_once_across_page_boundaries(): void
    {
        F::binding();
        $this->at(5);
        $events = array_map(fn (int $n): string => 'evt_SYNTHETICSAMESECOND'.$n, range(1, 5));
        foreach ($events as $n => $event) {
            $this->lose($event, 'in_SYNTHETICSAMESECOND'.$n);
        }
        $this->assertSame(1, DB::table('production_membership_billing_events')->distinct()->count('received_at'), 'All five share one intake second.');
        $this->at(30);
        Queue::fake();

        $seen = [];
        $cursor = null;
        $pages = 0;
        do {
            $this->assertSame(0, Artisan::call('membership-billing:sweep-hints', ['--limit' => 2] + ($cursor === null ? [] : ['--after' => $cursor])));
            $output = Artisan::output();
            $pages++;
            preg_match_all('/^uncovered (event=[0-9a-f]{12}) /m', $output, $matches);
            array_push($seen, ...$matches[1]);
            $cursor = preg_match('/^Next cursor: (\S+)$/m', $output, $next) === 1 ? $next[1] : null;
        } while ($cursor !== null && $pages < 10);

        $expected = array_map(fn (string $event): string => $this->prefix($event), $events);
        sort($expected);
        sort($seen);
        $this->assertSame($expected, $seen, 'Every hint of the shared second is examined exactly once.');
        $this->assertSame(3, $pages);
        Queue::assertNothingPushed();

        $this->assertSame(0, Artisan::call('membership-billing:sweep-hints', ['--limit' => 1, '--all' => true, '--dispatch' => true]));
        $this->assertStringContainsString('Scan complete: 5 hint(s) examined in 5 page(s).', Artisan::output());
        Queue::assertPushed(RetrieveMembershipInvoice::class, 5);
    }

    /** `$covered` hints of F::INVOICE that one retrieval covers, then one newer uncovered hint of another invoice. */
    private function coveredHintsThenOneUncovered(int $covered): array
    {
        $binding = F::binding();
        foreach (range(1, $covered) as $offset) {
            $this->at($offset);
            $this->lose('evt_SYNTHETICCOVERED'.$offset, F::INVOICE);
        }
        $this->at(10);
        (new RetrieveMembershipInvoice($binding['id'], F::INVOICE))->handle(new BillingReconciliation(new RehearsalBillingGateway(F::graph())));
        $this->at(20);
        $this->lose('evt_SYNTHETICLATEST', self::OTHER);
        $this->at(30);

        return $binding;
    }

    private function cursor(string $output): string
    {
        $this->assertSame(1, preg_match('/^Next cursor: (\S+)$/m', $output, $match), $output);

        return $match[1];
    }

    private function prefix(string $eventId): string
    {
        return 'event='.substr(BillingValues::hash('event', F::ACCOUNT, 'test', $eventId), 0, 12);
    }

    private function assertNoProviderReference(string $output): void
    {
        foreach (['in_SYNTHETIC', 'evt_SYNTHETIC', 'sub_SYNTHETIC', F::ACCOUNT] as $reference) {
            $this->assertStringNotContainsString($reference, $output, 'Provider references are never printed.');
        }
    }

    /** Commits an event hint whose dispatch fails afterwards (this lane binds no gateway), as a queue outage would. */
    private function lose(string $eventId, string $invoiceRef, string $type = 'invoice.paid'): void
    {
        try {
            $this->receive($this->event($eventId, $invoiceRef, $type));
        } catch (BindingResolutionException) {
            // Expected.
        }
    }

    private function receive(string $payload): array
    {
        return (new BillingWebhookIntake)->receive($payload, WebhookSignature::generateSignatureHeader($payload, self::SECRET));
    }

    private function event(string $id, string $invoiceRef, string $type): string
    {
        $invoice = [...F::graph()['invoice'], 'id' => $invoiceRef,
            'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => F::SUBSCRIPTION]]];
        $values = ['id' => $id, 'object' => 'event', 'api_version' => BillingProviderPin::API_VERSION, 'created' => F::PERIOD_START,
            'livemode' => false, 'pending_webhooks' => 1, 'request' => ['id' => null, 'idempotency_key' => null], 'type' => $type, 'data' => ['object' => $invoice]];

        return json_encode(F::sdk(Event::class, $values), JSON_THROW_ON_ERROR);
    }

    private function at(int $offset): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC(self::T0 + $offset));
    }
}
