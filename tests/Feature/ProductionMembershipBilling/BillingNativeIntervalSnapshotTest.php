<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Memberships\Billing\BillingSchema;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use Tests\Support\BillingIntervalFixtures;
use Tests\Support\BillingStripeFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

class BillingNativeIntervalSnapshotTest extends TestCase
{
    use BillingIntervalFixtures;
    use FinalizationDatabaseMigrations;

    public function test_prior_snapshot_sees_a_later_committed_endpoint_claim_in_both_ledger_directions(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Current reads through an older REPEATABLE READ snapshot require native MySQL.');
        }
        F::configure();
        $binding = F::binding();
        $db = DB::connection()->getConfig();
        $address = empty($db['unix_socket']) ? 'host='.$db['host'].';port='.$db['port'] : 'unix_socket='.$db['unix_socket'];
        $older = new PDO('mysql:'.$address.';dbname='.$db['database'].';charset=utf8mb4', $db['username'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $older->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->assertNotSame(DB::connection()->getPdo()->query('SELECT CONNECTION_ID()')->fetchColumn(), $older->query('SELECT CONNECTION_ID()')->fetchColumn());
        $tables = [BillingSchema::TABLES[2], BillingSchema::TABLES[5]];
        foreach ($tables as $firstTable) {
            $otherTable = $firstTable === $tables[0] ? $tables[1] : $tables[0];
            foreach (['start_start', 'start_end', 'end_start', 'end_end'] as $pair) {
                [$a, $b, $c, $d, $e] = $this->positions(5);
                $first = $this->intervalRows($binding, $b, $d);
                [$start, $end] = match ($pair) {
                    'start_start' => [$b, $e], 'start_end' => [$a, $b], 'end_start' => [$d, $e], 'end_end' => [$a, $d],
                };
                $other = $this->intervalRows($binding, $start, $end);
                $older->beginTransaction();
                try {
                    $before = $older->query('SELECT COUNT(*) FROM '.$firstTable)->fetchColumn();
                    $older->query('SELECT COUNT(*) FROM '.$otherTable)->fetchColumn();
                    // The first connection commits before the stale reader attempts its insert.
                    $this->insertIntervalRow($firstTable, $first[$firstTable]);
                    $this->assertFalse(DB::connection()->getPdo()->inTransaction());
                    $this->assertSame($before, $older->query('SELECT COUNT(*) FROM '.$firstTable)->fetchColumn());
                    $row = $other[$otherTable];
                    try {
                        $older->prepare('INSERT INTO '.$otherTable.' ('.implode(', ', array_keys($row)).') VALUES ('.implode(', ', array_fill(0, count($row), '?')).')')->execute(array_values($row));
                        $this->fail('An older snapshot cannot hide a committed endpoint claim: '.$firstTable.' '.$pair);
                    } catch (PDOException $error) {
                        $this->assertSame('45000', $error->errorInfo[0]);
                        $this->assertStringContainsString('Retain billing retrieval interval', $error->getMessage());
                    }
                } finally {
                    if ($older->inTransaction()) {
                        $older->rollBack();
                    }
                }
                $this->assertSame(1, DB::table($firstTable)->where('id', $first[$firstTable]['id'])->count());
                $this->assertSame(0, DB::table($otherTable)->where('id', $other[$otherTable]['id'])->count());
            }
        }
        $this->assertSame(0, DB::table('production_membership_paid_periods')->count());
        $this->assertSame(0, DB::table('production_membership_credit_events')->count());
    }
}
