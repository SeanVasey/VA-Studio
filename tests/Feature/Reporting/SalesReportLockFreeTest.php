<?php

namespace Tests\Feature\Reporting;

use App\Domain\Commerce\RefundDispute\Models\RefundDisputeObject;
use App\Domain\Commerce\RefundDispute\RefundDisputeActions;
use App\Domain\Delivery\PrepareTestDeliveryStream;
use App\Domain\Reporting\SalesReport;
use Carbon\CarbonImmutable;
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
 * The read-only sales report takes no row locks. Its tier netting once loaded the A5 case with FOR UPDATE, which on MySQL waits on
 * any A5 writer, locks the whole allocation table (no order index) and, on timeout, silently re-labelled the refund as unattributed.
 * On every engine no statement may lock; on native MySQL a concurrent writer's locks neither delay the report nor change its figures,
 * and the report session holds no InnoDB locks while it runs.
 */
class SalesReportLockFreeTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public function test_the_report_and_its_export_issue_no_locking_reads_and_never_wait_on_an_a5_writer(): void
    {
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        $this->travelTo(CarbonImmutable::parse('2026-09-30 12:00:00', 'UTC'));
        R::configure();
        Queue::fake();
        [$payments, $financial] = R::bind($this->app);
        $this->app->instance(PrepareTestDeliveryStream::class, DeliveryFixtures::observingStreams());
        $admin = LicenseFixtures::admin();
        $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->actingAs($admin);
        $a = DeliveryFixtures::ready($payments, true);
        $totals = array_map(fn (array $line): int => $line['pricing']['total_minor'], $a['original']['lines']);
        $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'));
        R::provider($financial, [['re_LOCKFREE', $totals[0], 'succeeded']]);
        R::observe($a['order']->public_id, $admin);
        app(RefundDisputeActions::class)->allocate($a['order']->public_id, $admin, (string) Str::uuid(), R::sequence($a['order']->public_id, $admin),
            RefundDisputeObject::where('kind', 'refund')->sole()->public_id, [0 => $totals[0]]);
        $build = fn (): array => app(SalesReport::class)->build($admin, '2026-09-30', '2026-10-06', 'month')['test'];
        $refunded = fn (array $report): int => array_sum(array_column($report['tiers']['rows'], 'refunded_minor'));

        // Every engine: no statement of a report or an export locks a row.
        $sql = [];
        DB::listen(function ($query) use (&$sql): void { $sql[] = $query->sql; });
        $baseline = $build();
        $csv = app(SalesReport::class)->export($admin, '2026-09-30', '2026-10-06', 'month');
        $this->assertNotEmpty(array_filter($sql, fn (string $statement): bool => str_contains($statement, 'refund_line_allocations')), 'The tier netting read the allocations.');
        $this->assertSame([], array_values(array_filter($sql, fn (string $statement): bool => preg_match('/\bfor\s+update\b|\block\s+in\s+share\s+mode\b|\bfor\s+share\b/i', $statement) === 1)));
        $this->assertSame($totals[0], $refunded($baseline));
        $this->assertSame([0, 0], [$baseline['tiers']['unreadable_refund_orders'], $baseline['tiers']['unverified_orders']]);
        // The CSV carries the same counts as the page, with no unattributed refund row when nothing is unattributed.
        $rows = array_map('str_getcsv', explode("\n", trim($csv['csv'])));
        $records = array_column($rows, 6, 1);
        $this->assertSame(['0', '0'], [$records['tier_unverified_orders'], $records['tier_unreadable_refund_orders']]);
        $this->assertNotContains('unattributed_refund', array_column($rows, 1));

        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        // Native MySQL: a second session holds FOR UPDATE on this order's A5 rows (the allocation scan locks the whole table).
        $config = DB::connection()->getConfig();
        $other = new PDO('mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'], $config['username'], $config['password']);
        $other->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $other->beginTransaction();
        $order = (int) $a['order']->id;
        foreach (['refund_line_allocations', 'refund_dispute_observations', 'refund_dispute_objects', 'refund_dispute_holds', 'refund_dispute_work'] as $table) {
            $other->query("SELECT id FROM {$table} WHERE order_id = {$order} FOR UPDATE")->fetchAll();
        }
        DB::statement('SET SESSION innodb_lock_wait_timeout = 2');
        $started = microtime(true);
        $blocked = $build();
        $elapsed = microtime(true) - $started;
        $this->assertLessThan(1.5, $elapsed, 'The report waited on a writer lock.');
        $this->assertSame($totals[0], $refunded($blocked));
        $this->assertSame([0, []], [$blocked['tiers']['unreadable_refund_orders'], $blocked['tiers']['unattributed_refund_minor']]);
        $other->rollBack();

        // The report's own session holds no InnoDB locks after it ran (checked inside one outer transaction), and the probe is
        // meaningful: the same session does show locks after an explicit locking read.
        DB::beginTransaction();
        $build();
        // The report's session, found by thread rather than by transaction id (a read-write transaction gets a new id on its first lock).
        $locks = fn (): int => (int) DB::selectOne('SELECT COUNT(*) AS locks FROM performance_schema.data_locks WHERE THREAD_ID = '
            .'(SELECT THREAD_ID FROM performance_schema.threads WHERE PROCESSLIST_ID = CONNECTION_ID())')->locks;
        $this->assertSame(0, $locks());
        DB::select('SELECT id FROM refund_line_allocations WHERE order_id = ? FOR UPDATE', [$order]);
        $this->assertGreaterThan(0, $locks());
        DB::rollBack();
    }
}
