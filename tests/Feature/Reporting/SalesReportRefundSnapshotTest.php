<?php

namespace Tests\Feature\Reporting;

use App\Domain\Commerce\RefundDispute\Models\RefundDisputeObject;
use App\Domain\Commerce\RefundDispute\Models\RefundLineAllocation;
use App\Domain\Commerce\RefundDispute\RefundDisputeActions;
use App\Domain\Delivery\PrepareTestDeliveryStream;
use App\Domain\Reporting\SalesReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PDO;
use Tests\Support\DeliveryFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\RefundDisputeFixtures as R;
use Tests\TestCase;

/**
 * Tier refunds are netted per order inside one snapshot transaction, and the order's succeeded total is taken from that same
 * snapshot. The period aggregate is an earlier read: a refund recorded between the two must not leave a negative unattributed
 * amount, and an inconsistent case is reported as unreadable, never as a negative figure (Codex P2 r4418610921, review R-2).
 */
class SalesReportRefundSnapshotTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private object $financial;

    private User $admin;

    private array $order;

    /** @var list<int> */
    private array $totals;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        $this->travelTo(CarbonImmutable::parse('2026-09-30 12:00:00', 'UTC'));
        R::configure();
        Queue::fake();
        [$payments, $this->financial] = R::bind($this->app);
        $this->app->instance(PrepareTestDeliveryStream::class, DeliveryFixtures::observingStreams());
        $this->admin = LicenseFixtures::admin();
        $this->admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->actingAs($this->admin);
        $this->order = DeliveryFixtures::ready($payments, true);
        $this->totals = array_map(fn (array $line): int => $line['pricing']['total_minor'], $this->order['original']['lines']);
        $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'));
    }

    private function test(): array
    {
        return app(SalesReport::class)->build($this->admin, '2026-09-30', '2026-10-06', 'month')['test'];
    }

    /**
     * Records the provider's refunds, then allocates the newest refund to one line through the real A5 actions, unless A5 already
     * allocated it automatically (a refund that exactly covers the only line still unrefunded).
     */
    private function refund(array $refunds, int $amount, int $position): void
    {
        $public = $this->order['order']->public_id;
        R::provider($this->financial, $refunds);
        R::observe($public, $this->admin);
        $object = RefundDisputeObject::where('kind', 'refund')->orderByDesc('id')->firstOrFail();
        if (RefundLineAllocation::where('refund_dispute_object_id', $object->id)->exists()) {
            return;
        }
        app(RefundDisputeActions::class)->allocate($public, $this->admin, (string) Str::uuid(), R::sequence($public, $this->admin), $object->public_id, [$position => $amount]);
    }

    private function tierRefunds(array $report): int
    {
        return array_sum(array_column($report['tiers']['rows'], 'refunded_minor'));
    }

    /** Runs $write once, right after the tier chunk read the orders' succeeded totals and before any per-order snapshot. */
    private function betweenTheReads(callable $write): object
    {
        $state = (object) ['done' => false];
        DB::listen(function (QueryExecuted $query) use ($state, $write): void {
            if ($state->done || DB::transactionLevel() !== 0 || preg_match('/succeeded_refund_minor["`]? as ["`]?succeeded["`]?/i', $query->sql) !== 1) {
                return;
            }
            $state->done = true;
            $write();
        });

        return $state;
    }

    public function test_a_refund_recorded_between_the_period_aggregate_and_the_order_snapshot_never_reports_a_negative_unattributed_amount(): void
    {
        [$first, $second] = $this->totals;
        $this->refund([['re_SNAPFIRST', $first, 'succeeded']], $first, 0);
        $this->assertSame([], $this->test()['tiers']['unattributed_refund_minor']);

        // 1. A second refund is observed and allocated between the two reads. The snapshot sees both allocations, and its own
        // succeeded total covers both; the older aggregate total alone would leave -$second unattributed.
        $both = [['re_SNAPFIRST', $first, 'succeeded'], ['re_SNAPSECOND', $second, 'succeeded']];
        $injected = $this->betweenTheReads(fn () => $this->refund($both, $second, 1));
        $report = $this->test();
        $this->assertTrue($injected->done, 'The refund was recorded between the two reads.');
        // The period aggregate ran before the injection and still shows only the first refund: that read is not changed here.
        $this->assertSame($first, $report['currencies']['USD']['refunded_minor']);
        $this->assertSame([], $report['tiers']['unattributed_refund_minor'], 'No negative (or any) unattributed amount.');
        $this->assertSame($first + $second, $this->tierRefunds($report));
        $this->assertSame(0, $report['tiers']['unreadable_refund_orders']);
        $fresh = $this->test();
        $this->assertSame([$first + $second, $first + $second, []], [$fresh['currencies']['USD']['refunded_minor'], $this->tierRefunds($fresh), $fresh['tiers']['unattributed_refund_minor']]);

        // 2. The opposite order: the second refund fails between the two reads. The snapshot no longer counts it, so nothing is
        // left unattributed; the older aggregate total would have shown $second as unattributed.
        $injected = $this->betweenTheReads(function () use ($first, $second): void {
            R::provider($this->financial, [['re_SNAPFIRST', $first, 'succeeded'], ['re_SNAPSECOND', $second, 'failed']]);
            R::observe($this->order['order']->public_id, $this->admin);
        });
        $report = $this->test();
        $this->assertTrue($injected->done, 'The reversal was recorded between the two reads.');
        $this->assertSame($first + $second, $report['currencies']['USD']['refunded_minor'], 'The aggregate was read before the reversal.');
        $this->assertSame([[], $first, 0], [$report['tiers']['unattributed_refund_minor'], $this->tierRefunds($report), $report['tiers']['unreadable_refund_orders']]);
    }

    public function test_a_case_that_attributes_more_than_its_succeeded_total_is_unreadable_not_a_negative_figure(): void
    {
        [$first] = $this->totals;
        $this->refund([['re_SNAPGUARD', $first, 'succeeded']], $first, 0);
        // Disposable fixture only: lower the accepted observation's succeeded total below its allocated refund. The A5 guard
        // forbids this update, so the test removes that trigger first; the application never writes such a row.
        DB::unprepared('DROP TRIGGER refund_dispute_observations_update');
        $latest = DB::table('refund_dispute_observations')->where('order_id', $this->order['order']->id)->where('outcome', 'observed')->max('sequence');
        DB::table('refund_dispute_observations')->where('order_id', $this->order['order']->id)->where('sequence', $latest)->update(['succeeded_refund_minor' => $first - 1]);

        $report = $this->test();
        $this->assertSame(1, $report['tiers']['unreadable_refund_orders']);
        $this->assertSame(0, $this->tierRefunds($report), 'An inconsistent case attributes nothing to tiers.');
        // The order is listed as unreadable and its succeeded refund is shown as unattributed, never below zero.
        $this->assertSame(['USD' => $first - 1], $report['tiers']['unattributed_refund_minor']);
    }

    public function test_native_the_order_snapshot_does_not_see_an_allocation_committed_while_it_reads(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('A second session committing between the reads of one REPEATABLE READ snapshot requires native MySQL.');
        }
        [$first] = $this->totals;
        $public = $this->order['order']->public_id;
        R::provider($this->financial, [['re_SNAPNATIVE', $first, 'succeeded']]);
        R::observe($public, $this->admin);
        $object = RefundDisputeObject::where('kind', 'refund')->sole();
        $line = DB::table('order_lines')->where('order_id', $this->order['order']->id)->where('position', 0)->value('id');
        $before = $this->test();
        $this->assertSame([['USD' => $first], 0], [$before['tiers']['unattributed_refund_minor'], $this->tierRefunds($before)]);

        // A second session commits the allocation the A5 writer would record, after the snapshot read the order's accepted
        // observation and before it reads the allocations. Inside one snapshot transaction the report keeps the earlier
        // consistent view (refund unattributed); separate autocommit reads would mix the two moments.
        $config = DB::connection()->getConfig();
        $other = new PDO('mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'], $config['username'], $config['password']);
        $other->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $committed = false;
        DB::listen(function (QueryExecuted $query) use (&$committed, $other, $object, $line, $first): void {
            if ($committed || DB::transactionLevel() === 0 || ! str_contains($query->sql, 'refund_dispute_observations') || ! str_contains($query->sql, 'outcome')) {
                return;
            }
            $committed = true;
            $insert = $other->prepare('INSERT INTO refund_line_allocations (public_id, order_id, refund_dispute_object_id, order_line_id, amount_minor, currency, source, actor_id, sequence, created_at)'
                ." VALUES (?, ?, ?, ?, ?, 'USD', 'staff', ?, ?, ?)");
            $insert->execute([(string) Str::uuid(), $this->order['order']->id, $object->id, $line, $first, $this->admin->id, 1, now()->utc()->format('Y-m-d H:i:s')]);
        });
        $during = $this->test();
        $this->assertTrue($committed, 'The allocation was committed while the snapshot read the case.');
        $this->assertSame([['USD' => $first], 0, 0], [$during['tiers']['unattributed_refund_minor'], $this->tierRefunds($during), $during['tiers']['unreadable_refund_orders']]);

        // The allocation really is committed: the next snapshot attributes it.
        $after = $this->test();
        $this->assertSame([[], $first], [$after['tiers']['unattributed_refund_minor'], $this->tierRefunds($after)]);
    }
}
