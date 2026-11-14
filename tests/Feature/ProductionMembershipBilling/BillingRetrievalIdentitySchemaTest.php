<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Memberships\Billing\BillingRetrievalSchema;
use App\Domain\Memberships\Billing\BillingSchema;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use Tests\Support\BillingIntervalFixtures;
use Tests\Support\BillingStripeFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

/** Raw structural fixtures exercise guards, never cryptographic invoice authority. */
class BillingRetrievalIdentitySchemaTest extends TestCase
{
    use BillingIntervalFixtures;
    use FinalizationDatabaseMigrations;

    public function test_native_positions_require_database_time_and_unique_allocation_and_read_phase(): void
    {
        $token = bin2hex(random_bytes(32));
        $first = $this->allocate($token, 'start');
        $last = $this->allocate($token, 'end');
        $hint = $this->allocate(null, 'hint');
        $this->assertNotSame($first['allocation_token'], $last['allocation_token']);
        $this->assertSame($first['retrieval_token'], $last['retrieval_token']);
        $this->assertNull($hint['retrieval_token']);
        $this->assertSame(substr($first['database_at'], 0, 19), $first['created_at']);
        $this->assertSame(26, strlen($first['database_at']));
        foreach ([['kind' => 'retrieval', 'created_at' => '2099-01-01 00:00:00'],
            [...$first, 'id' => 900000], [...$first, 'id' => 0, 'database_at' => '2099-01-01 00:00:00.000000']] as $row) {
            $this->sqlRefused(fn () => $this->insertIntervalRow(BillingSchema::TABLES[4], $row));
        }
        $this->sqlRefused(fn () => $this->allocate($token, 'start'));
        $this->sqlRefused(fn () => $this->allocate(bin2hex(random_bytes(32)), 'start', $first['allocation_token']));
        $this->assertSame(3, DB::table(BillingSchema::TABLES[4])->count());
        (new BillingRetrievalSchema)->assertOwned(DB::connection()->getPdo());
    }

    public function test_exact_endpoint_identity_and_database_time_are_required_on_both_ledgers(): void
    {
        F::configure();
        $binding = F::binding();
        foreach ([BillingSchema::TABLES[2], BillingSchema::TABLES[5]] as $table) {
            [$start, $end] = $this->interval();
            $row = $this->newRows($binding, $start, $end)[$table];
            foreach (['retrieval_token', 'start_allocation_token', 'end_allocation_token'] as $field) {
                $this->sqlRefused(fn () => $this->insertIntervalRow($table, [...$row, $field => bin2hex(random_bytes(32))]), true);
                $this->sqlRefused(fn () => $this->insertIntervalRow($table, [...$row, $field => null]), true);
            }
            $this->sqlRefused(fn () => $this->insertIntervalRow($table, [...$row, 'created_at' => '2099-01-01 00:00:00']), true);
            if ($table === BillingSchema::TABLES[2]) {
                foreach (['retrieved_at' => '2099-01-01 00:00:00', 'retrieval_started_at' => '2099-01-01 00:00:00.000000', 'freshness_deadline' => '2099-01-01 00:10:00'] as $field => $wrong) {
                    $this->sqlRefused(fn () => $this->insertIntervalRow($table, [...$row, $field => $wrong]), true);
                }
            }
            $this->insertIntervalRow($table, $row);
            $this->assertSame(1, DB::table($table)->count());
        }
        $this->assertSame(0, DB::table('production_membership_paid_periods')->count());
    }

    public function test_endpoints_from_two_reads_cannot_be_spliced_even_with_both_actual_tokens(): void
    {
        F::configure();
        $binding = F::binding();
        [$start] = $this->interval();
        [, $end] = $this->interval();
        $rows = $this->newRows($binding, $start, $end);
        foreach ($rows as $table => $row) {
            foreach ([$start['retrieval_token'], $end['retrieval_token']] as $token) {
                $this->sqlRefused(fn () => $this->insertIntervalRow($table, [...$row, 'retrieval_token' => $token]), true);
            }
            $this->assertSame(0, DB::table($table)->count());
        }
    }

    public function test_same_integer_after_disposable_allocation_replacement_does_not_retain_identity(): void
    {
        F::configure();
        $binding = F::binding();
        foreach (['start', 'end'] as $endpoint) {
            [$start, $end] = $this->interval();
            $rows = $this->newRows($binding, $start, $end);
            $id = $endpoint === 'start' ? $start['id'] : $end['id'];
            $this->replaceAllocationForSimulation((int) $id);
            foreach ($rows as $table => $row) {
                $this->sqlRefused(fn () => $this->insertIntervalRow($table, $row), true);
                $this->assertSame(0, DB::table($table)->count());
            }
        }
        (new BillingRetrievalSchema)->assertOwned(DB::connection()->getPdo());
    }

    public function test_both_cross_ledger_directions_refuse_reusing_an_exact_valid_interval(): void
    {
        F::configure();
        $binding = F::binding();
        $tables = [BillingSchema::TABLES[2], BillingSchema::TABLES[5]];
        foreach ($tables as $first) {
            [$start, $end] = $this->interval();
            $rows = $this->newRows($binding, $start, $end);
            $other = $first === $tables[0] ? $tables[1] : $tables[0];
            $this->insertIntervalRow($first, $rows[$first]);
            $this->sqlRefused(fn () => $this->insertIntervalRow($other, $rows[$other]), true);
        }
        $this->assertSame([1, 1], array_map(fn (string $table): int => DB::table($table)->count(), $tables));
    }

    public function test_allocated_identity_fields_remain_immutable(): void
    {
        [$start] = $this->interval();
        $table = (new BillingSchema)->table(BillingSchema::TABLES[4]);
        foreach (['allocation_token', 'retrieval_token', 'phase', 'database_at'] as $field) {
            $this->sqlRefused(fn () => DB::connection()->getPdo()->exec('UPDATE '.$table.' SET '.$field.' = NULL WHERE id = '.(int) $start['id']), true);
        }
        $this->sqlRefused(fn () => DB::connection()->getPdo()->exec('DELETE FROM '.$table.' WHERE id = '.(int) $start['id']), true);
        $this->assertSame($start, (array) DB::table(BillingSchema::TABLES[4])->where('id', $start['id'])->first());
    }

    public function test_native_database_utc_is_independent_of_session_timezone(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL session timezone behavior requires the native database.');
        }
        $pdo = DB::connection()->getPdo();
        $original = $pdo->query('SELECT @@session.time_zone')->fetchColumn();
        try {
            $pdo->exec("SET SESSION time_zone = '+05:30'");
            $before = $pdo->query('SELECT UTC_TIMESTAMP(6)')->fetchColumn();
            [$start, $end] = $this->interval();
            $after = $pdo->query('SELECT UTC_TIMESTAMP(6)')->fetchColumn();
            $this->assertGreaterThanOrEqual($before, $start['database_at']);
            $this->assertLessThanOrEqual($after, $end['database_at']);
            $this->assertSame(substr($end['database_at'], 0, 19), $end['created_at']);
        } finally {
            $pdo->prepare('SET SESSION time_zone = ?')->execute([$original]);
        }
    }

    public function test_native_old_snapshot_cannot_hide_a_competing_committed_interval_claim(): void
    {
        $older = $this->olderConnection();
        F::configure();
        $binding = F::binding();
        $tables = [BillingSchema::TABLES[2], BillingSchema::TABLES[5]];
        foreach ($tables as $first) {
            [$start, $end] = $this->interval();
            $rows = $this->newRows($binding, $start, $end);
            $other = $first === $tables[0] ? $tables[1] : $tables[0];
            $older->beginTransaction();
            try {
                $before = $older->query('SELECT COUNT(*) FROM '.$first)->fetchColumn();
                $older->query('SELECT COUNT(*) FROM '.$other)->fetchColumn();
                $this->insertIntervalRow($first, $rows[$first]);
                $this->assertFalse(DB::connection()->getPdo()->inTransaction());
                $this->assertSame($before, $older->query('SELECT COUNT(*) FROM '.$first)->fetchColumn());
                $this->sqlRefused(fn () => $this->insertOn($older, $other, $rows[$other]), true);
            } finally {
                $older->rollBack();
            }
            $this->assertSame(0, DB::table($other)->where('id', $rows[$other]['id'])->count());
        }
    }

    public function test_native_endpoint_lookup_uses_current_allocation_after_an_older_snapshot(): void
    {
        $older = $this->olderConnection();
        F::configure();
        $binding = F::binding();
        foreach (['start', 'end'] as $endpoint) {
            foreach ([BillingSchema::TABLES[2], BillingSchema::TABLES[5]] as $table) {
                [$start, $end] = $this->interval();
                $rows = $this->newRows($binding, $start, $end);
                $original = $endpoint === 'start' ? $start : $end;
                $id = (int) $original['id'];
                $older->beginTransaction();
                try {
                    $query = 'SELECT allocation_token FROM '.BillingSchema::TABLES[4].' WHERE id = '.$id;
                    // Establish the old snapshot without an MDL pin on the allocation table.
                    $older->query('SELECT COUNT(*) FROM '.BillingSchema::TABLES[0])->fetchColumn();
                    $this->replaceAllocationForSimulation($id);
                    $this->assertSame($original['allocation_token'], $older->query($query)->fetchColumn());
                    $this->sqlRefused(fn () => $this->insertOn($older, $table, $rows[$table]), true);
                } finally {
                    $older->rollBack();
                }
                $this->assertSame(0, DB::table($table)->count());
            }
        }
    }

    private function olderConnection(): PDO
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Current reads through an older REPEATABLE READ snapshot require native MySQL.');
        }
        $db = DB::connection()->getConfig();
        $address = empty($db['unix_socket']) ? 'host='.$db['host'].';port='.$db['port'] : 'unix_socket='.$db['unix_socket'];
        $older = new PDO('mysql:'.$address.';dbname='.$db['database'].';charset=utf8mb4', $db['username'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $older->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->assertNotSame(DB::connection()->getPdo()->query('SELECT CONNECTION_ID()')->fetchColumn(), $older->query('SELECT CONNECTION_ID()')->fetchColumn());

        return $older;
    }

    private function insertOn(PDO $pdo, string $table, array $row): void
    {
        $pdo->prepare('INSERT INTO '.$table.' ('.implode(', ', array_keys($row)).') VALUES ('.implode(', ', array_fill(0, count($row), '?')).')')->execute(array_values($row));
    }

    private function allocate(?string $retrieval, string $phase, ?string $allocation = null): array
    {
        $pdo = DB::connection()->getPdo();
        $clock = DB::getDriverName() === 'mysql' ? 'UTC_TIMESTAMP(6)' : "strftime('%Y-%m-%d %H:%M:%f','now') || '000'";
        $table = (new BillingSchema)->table(BillingSchema::TABLES[4]);
        $pdo->prepare('INSERT INTO '.$table.' (kind, created_at, allocation_token, retrieval_token, phase, database_at) VALUES (?, substr(('.$clock.'),1,19), ?, ?, ?, ('.$clock.'))')
            ->execute([$phase === 'hint' ? 'hint' : 'retrieval', $allocation ?? bin2hex(random_bytes(32)), $retrieval, $phase]);
        $statement = $pdo->prepare('SELECT * FROM '.$table.' WHERE id = ?');
        $statement->execute([(int) $pdo->lastInsertId()]);

        return $statement->fetch(PDO::FETCH_ASSOC);
    }

    private function interval(): array
    {
        $token = bin2hex(random_bytes(32));

        return [$this->allocate($token, 'start'), $this->allocate($token, 'end')];
    }

    private function newRows(array $binding, array $start, array $end): array
    {
        $rows = $this->intervalRows($binding, (int) $start['id'], (int) $end['id']);
        foreach ($rows as $table => &$row) {
            $row['retrieval_token'] = $start['retrieval_token'];
            $row['start_allocation_token'] = $start['allocation_token'];
            $row['end_allocation_token'] = $end['allocation_token'];
            $row['created_at'] = $end['created_at'];
            if ($table === BillingSchema::TABLES[2]) {
                $row['retrieval_started_at'] = $start['database_at'];
                $row['retrieved_at'] = $end['created_at'];
                $row['freshness_deadline'] = (new \DateTimeImmutable($end['created_at'], new \DateTimeZone('UTC')))->modify('+600 seconds')->format('Y-m-d H:i:s');
            }
        }

        return $rows;
    }

    private function replaceAllocationForSimulation(int $id): void
    {
        $pdo = DB::connection()->getPdo();
        $driver = DB::getDriverName();
        $schema = new BillingSchema;
        $logical = BillingSchema::TABLES[4];
        $name = DB::connection()->getTablePrefix().$logical;
        $guards = (new \ReflectionMethod(BillingSchema::class, 'guards'))->invoke($schema, $logical, $driver, $name, $schema->table($logical), BillingRetrievalSchema::COMPLETE);
        $pdo->exec('DROP TRIGGER '.$name.'_update');
        try {
            $pdo->prepare('UPDATE '.$schema->table($logical).' SET allocation_token = ? WHERE id = ?')->execute([bin2hex(random_bytes(32)), $id]);
        } finally {
            $pdo->exec($guards[$name.'_update']['sql']);
        }
    }

    private function sqlRefused(callable $operation, bool $guard = false): void
    {
        try {
            $operation();
            $this->fail('Unowned raw retrieval evidence must be refused.');
        } catch (PDOException $error) {
            if (DB::getDriverName() === 'mysql' && $guard) {
                $this->assertSame('45000', $error->getCode());
            } else {
                $this->assertNotSame('', $error->getMessage());
            }
        }
    }
}
