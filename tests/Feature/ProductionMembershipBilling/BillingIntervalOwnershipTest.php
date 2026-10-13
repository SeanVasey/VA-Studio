<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Memberships\Billing\BillingException;
use App\Domain\Memberships\Billing\BillingSchema;
use Illuminate\Support\Facades\DB;
use PDOException;
use Tests\Support\BillingIntervalFixtures;
use Tests\Support\BillingStripeFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

class BillingIntervalOwnershipTest extends TestCase
{
    use BillingIntervalFixtures;
    use FinalizationDatabaseMigrations;

    public function test_both_insert_directions_refuse_every_cross_ledger_endpoint_combination(): void
    {
        F::configure();
        $binding = F::binding();
        $tables = [BillingSchema::TABLES[2], BillingSchema::TABLES[5]];
        foreach ($tables as $firstTable) {
            $otherTable = $tables[0] === $firstTable ? $tables[1] : $tables[0];
            foreach (['start_start', 'start_end', 'end_start', 'end_end'] as $pair) {
                [$a, $b, $c, $d, $e] = $this->positions(5);
                $first = $this->intervalRows($binding, $b, $d);
                [$start, $end] = match ($pair) {
                    'start_start' => [$b, $e], 'start_end' => [$a, $b], 'end_start' => [$d, $e], 'end_end' => [$a, $d],
                };
                $other = $this->intervalRows($binding, $start, $end);
                $this->insertIntervalRow($firstTable, $first[$firstTable]);
                try {
                    $this->insertIntervalRow($otherTable, $other[$otherTable]);
                    $this->fail('One retrieval endpoint cannot belong to both ledgers: '.$firstTable.' '.$pair);
                } catch (PDOException $error) {
                    $this->assertStringContainsString('Retain billing retrieval interval', $error->getMessage());
                }
                $this->assertSame(1, DB::table($firstTable)->where('id', $first[$firstTable]['id'])->count());
                $this->assertSame(0, DB::table($otherTable)->where('id', $other[$otherTable]['id'])->count());
            }
        }
        // Independent intervals in both ledgers still succeed.
        [$a, $b, $c, $d] = $this->positions(4);
        $one = $this->intervalRows($binding, $a, $b);
        $two = $this->intervalRows($binding, $c, $d);
        $this->insertIntervalRow($tables[0], $one[$tables[0]]);
        $this->insertIntervalRow($tables[1], $two[$tables[1]]);
        $this->assertSame([5, 5], array_map(fn ($table) => DB::table($table)->count(), $tables));
        $this->assertSame(0, DB::table('production_membership_paid_periods')->count());
        $this->assertSame(0, DB::table('production_membership_credit_events')->count());
    }

    public function test_exact_legacy_upgrade_and_each_interrupted_guard_state_preserve_every_row(): void
    {
        F::configure();
        $binding = F::binding();
        [$a, $b, $c, $d] = $this->positions(4);
        $one = $this->intervalRows($binding, $a, $b);
        $two = $this->intervalRows($binding, $c, $d);
        $this->insertIntervalRow(BillingSchema::TABLES[2], $one[BillingSchema::TABLES[2]]);
        $this->insertIntervalRow(BillingSchema::TABLES[5], $two[BillingSchema::TABLES[5]]);
        $snapshot = $this->billingSnapshot();
        $this->dropIntervalGuards();
        $this->refused(fn () => (new BillingSchema)->assertOwned(DB::connection()->getPdo()));
        $migration = require base_path('database/migrations/2026_10_13_261000_membership_billing_interval_ownership.php');
        $migration->up();
        $migration->up();
        $this->assertSame($snapshot, $this->billingSnapshot());
        foreach ([BillingSchema::TABLES[2], BillingSchema::TABLES[5]] as $table) {
            DB::connection()->getPdo()->exec('DROP TRIGGER '.$table.'_interval');
            $migration->up();
            (new BillingSchema)->assertOwned(DB::connection()->getPdo());
            $this->assertSame($snapshot, $this->billingSnapshot());
        }
        try {
            $migration->down();
            $this->fail('Retained evidence cannot be rolled back destructively.');
        } catch (\LogicException) {
            $this->assertSame($snapshot, $this->billingSnapshot());
        }
    }

    public function test_legacy_cross_ledger_duplicates_are_refused_without_rewriting_or_resealing_them(): void
    {
        F::configure();
        $binding = F::binding();
        $this->dropIntervalGuards();
        [$start, $end] = $this->positions(2);
        $rows = $this->intervalRows($binding, $start, $end);
        foreach ($rows as $table => $row) {
            $this->insertIntervalRow($table, $row);
        }
        $snapshot = $this->billingSnapshot();
        $this->refused(fn () => (new BillingSchema)->upgradeIntervals(), 'retained_interval_conflict');
        $this->assertSame($snapshot, $this->billingSnapshot());
        $this->refused(fn () => (new BillingSchema)->assertOwned(DB::connection()->getPdo()));
    }

    public function test_upgrade_refuses_foreign_guard_drift_and_an_outer_transaction(): void
    {
        $pdo = DB::connection()->getPdo();
        $this->dropIntervalGuards();
        $table = BillingSchema::TABLES[2];
        $pdo->exec(DB::getDriverName() === 'mysql'
            ? 'CREATE TRIGGER '.$table.'_interval BEFORE INSERT ON '.$table.' FOR EACH ROW BEGIN SET @synthetic_interval_drift = 1; END'
            : 'CREATE TRIGGER '.$table.'_interval BEFORE INSERT ON '.$table.' BEGIN SELECT 1; END');
        $this->refused(fn () => (new BillingSchema)->upgradeIntervals());
        $this->assertSame(0, DB::table($table)->count());
        $pdo->exec('DROP TRIGGER '.$table.'_interval');
        (new BillingSchema)->upgradeIntervals();
        DB::transaction(fn () => $this->refused(fn () => (new BillingSchema)->upgradeIntervals(), 'migration_transaction'));
        (new BillingSchema)->assertOwned($pdo);
    }

    private function refused(callable $work, ?string $reason = null): void
    {
        try {
            $work();
            $this->fail('Expected a bounded billing refusal.');
        } catch (BillingException $error) {
            $reason === null ? $this->assertNotEmpty($error->reason) : $this->assertSame($reason, $error->reason);
        }
    }
}
