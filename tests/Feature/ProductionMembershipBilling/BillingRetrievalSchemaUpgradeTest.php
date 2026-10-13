<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Memberships\Billing\BillingException;
use App\Domain\Memberships\Billing\BillingRetrievalSchema;
use App\Domain\Memberships\Billing\BillingSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BillingIntervalFixtures;
use Tests\Support\BillingStripeFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

class BillingRetrievalSchemaUpgradeTest extends TestCase
{
    use BillingIntervalFixtures;
    use FinalizationDatabaseMigrations { migrateFreshUsing as private baselineMigrateFreshUsing; }

    protected function migrateFreshUsing(): array
    {
        $paths = array_values(array_filter(glob(database_path('migrations/*.php')), fn (string $path): bool => basename($path) !== '2026_10_13_263000_membership_billing_retrieval_identity.php'));

        return [...$this->baselineMigrateFreshUsing(), '--path' => $paths, '--realpath' => true];
    }

    public function test_populated_upgrade_preserves_old_columns_and_only_adds_null_evidence(): void
    {
        $before = $this->retained();
        $schema = new BillingRetrievalSchema;
        $this->refused(fn () => $schema->assertOwned(DB::connection()->getPdo()));
        $schema->upgrade();
        $schema->upgrade();
        (new BillingSchema)->upgradeIntervals();
        (new BillingSchema)->assertOwned(DB::connection()->getPdo());
        $schema->assertOwned(DB::connection()->getPdo());
        $this->assertRetained($before);
        $migration = require database_path('migrations/2026_10_13_263000_membership_billing_retrieval_identity.php');
        try {
            $migration->down();
            $this->fail('Rollback must preserve retained evidence.');
        } catch (\LogicException) {
            $this->assertRetained($before);
        }
    }

    #[DataProvider('prefixes')]
    public function test_each_exact_interrupted_prefix_resumes_without_rewriting_rows(int $stage): void
    {
        $before = $this->retained();
        $this->installPrefix($stage);
        $schema = new BillingRetrievalSchema;
        $this->refused(fn () => $schema->assertOwned(DB::connection()->getPdo()));
        $this->refused(fn () => (new BillingSchema)->assertOwned(DB::connection()->getPdo()));
        $schema->upgrade();
        $schema->assertOwned(DB::connection()->getPdo());
        $this->assertRetained($before);
    }

    public static function prefixes(): array
    {
        return array_combine(array_map(fn (int $stage): string => 'prefix_'.$stage, range(1, BillingRetrievalSchema::COMPLETE - 1)), array_map(fn (int $stage): array => [$stage], range(1, BillingRetrievalSchema::COMPLETE - 1)));
    }

    public function test_late_table_drift_is_refused_before_any_extension_ddl(): void
    {
        $before = $this->retained();
        DB::connection()->getPdo()->exec('ALTER TABLE '.(new BillingSchema)->table(BillingSchema::TABLES[5]).' ADD COLUMN unexpected_extension INTEGER NULL');
        $this->refused(fn () => (new BillingRetrievalSchema)->upgrade());
        $positions = DB::table(BillingSchema::TABLES[4])->first();
        $this->assertFalse(property_exists($positions, 'allocation_token'));
        $this->assertSame($before[4], $this->billingSnapshot()[4]);
    }

    public function test_foreign_extension_prefix_and_outer_transaction_are_refused(): void
    {
        $this->retained();
        DB::connection()->beginTransaction();
        try {
            $this->refused(fn () => (new BillingRetrievalSchema)->upgrade(), 'migration_transaction');
        } finally {
            DB::connection()->rollBack();
        }
        DB::connection()->getPdo()->exec('ALTER TABLE '.(new BillingSchema)->table(BillingSchema::TABLES[4]).' ADD COLUMN retrieval_token VARCHAR(64) NULL');
        $this->refused(fn () => (new BillingRetrievalSchema)->upgrade(), 'retrieval_schema_prefix');
        $this->assertFalse(property_exists(DB::table(BillingSchema::TABLES[4])->first(), 'allocation_token'));
    }

    public function test_old_interval_migration_cannot_recreate_a_missing_new_guard(): void
    {
        $this->retained();
        (new BillingRetrievalSchema)->upgrade();
        DB::connection()->getPdo()->exec('DROP TRIGGER '.BillingSchema::TABLES[2].'_interval');
        $this->refused(fn () => (new BillingSchema)->upgradeIntervals());
        // Removing a guard from the completed graph is not an authored upgrade prefix.
        $this->refused(fn () => (new BillingRetrievalSchema)->upgrade(), 'retrieval_schema_prefix');
    }

    public function test_database_timed_observation_can_follow_a_retained_future_worker_timestamp(): void
    {
        F::configure();
        $binding = F::binding();
        [$a, $b] = $this->positions(2);
        $old = $this->intervalRows($binding, $a, $b)[BillingSchema::TABLES[2]];
        $old['created_at'] = '2099-01-01 00:00:00';
        $this->insertIntervalRow(BillingSchema::TABLES[2], $old);
        (new BillingRetrievalSchema)->upgrade();
        $pdo = DB::connection()->getPdo();
        $table = (new BillingSchema)->table(BillingSchema::TABLES[4]);
        $clock = DB::getDriverName() === 'mysql' ? 'UTC_TIMESTAMP(6)' : "strftime('%Y-%m-%d %H:%M:%f','now') || '000'";
        $token = bin2hex(random_bytes(32));
        $endpoints = [];
        foreach (['start', 'end'] as $phase) {
            $pdo->prepare('INSERT INTO '.$table.' (kind,created_at,allocation_token,retrieval_token,phase,database_at) VALUES (?,substr(('.$clock.'),1,19),?,?,?,('.$clock.'))')
                ->execute(['retrieval', bin2hex(random_bytes(32)), $token, $phase]);
            $endpoints[] = $pdo->query('SELECT * FROM '.$table.' WHERE id = '.(int) $pdo->lastInsertId())->fetch(\PDO::FETCH_ASSOC);
        }
        [$start, $end] = $endpoints;
        $next = [...$old, 'id' => (string) Str::uuid(), 'sequence' => 2, 'prior_seal' => $old['seal'],
            'seal' => hash('sha256', 'synthetic database-timed observation'), 'created_at' => $end['created_at'],
            'retrieved_at' => $end['created_at'], 'retrieval_started_at' => $start['database_at'],
            'retrieval_position' => (int) $start['id'], 'retrieval_end_position' => (int) $end['id'],
            'retrieval_token' => $token, 'start_allocation_token' => $start['allocation_token'], 'end_allocation_token' => $end['allocation_token'],
            'freshness_deadline' => (new \DateTimeImmutable($end['created_at'], new \DateTimeZone('UTC')))->modify('+600 seconds')->format('Y-m-d H:i:s')];
        $this->insertIntervalRow(BillingSchema::TABLES[2], $next);
        $this->assertSame(2, DB::table(BillingSchema::TABLES[2])->count());
        $actual = (array) DB::table(BillingSchema::TABLES[2])->where('id', $old['id'])->first();
        foreach (array_keys(BillingRetrievalSchema::COLUMNS[BillingSchema::TABLES[2]]) as $column) {
            $this->assertNull($actual[$column]);
            unset($actual[$column]);
        }
        $this->assertEquals($old, $actual);
        $this->assertLessThan($old['created_at'], $next['created_at']);
        (new BillingRetrievalSchema)->upgrade();
    }

    private function retained(): array
    {
        F::configure();
        $binding = F::binding();
        [$a, $b, $c, $d] = $this->positions(4);
        $one = $this->intervalRows($binding, $a, $b);
        $two = $this->intervalRows($binding, $c, $d);
        $this->insertIntervalRow(BillingSchema::TABLES[2], $one[BillingSchema::TABLES[2]]);
        $this->insertIntervalRow(BillingSchema::TABLES[5], $two[BillingSchema::TABLES[5]]);

        return $this->billingSnapshot();
    }

    private function assertRetained(array $before): void
    {
        $after = $this->billingSnapshot();
        foreach (BillingSchema::TABLES as $index => $table) {
            foreach ($after[$index] as $offset => $row) {
                foreach (array_keys(BillingRetrievalSchema::COLUMNS[$table] ?? []) as $column) {
                    $this->assertNull($row[$column]);
                    unset($row[$column]);
                }
                $this->assertSame($before[$index][$offset], $row);
            }
        }
    }

    private function installPrefix(int $stage): void
    {
        $schema = new BillingRetrievalSchema;
        $billing = new BillingSchema;
        $driver = DB::getDriverName();
        $pdo = DB::connection()->getPdo();
        foreach (array_slice($schema->steps(), 0, $stage) as $offset => $step) {
            $table = $billing->table($step['table']);
            $name = DB::connection()->getTablePrefix().$step['table'];
            if ($step['kind'] === 'column') {
                $quote = $driver === 'mysql' ? '`' : '"';
                $pdo->exec('ALTER TABLE '.$table.' ADD COLUMN '.$quote.$step['name'].$quote.' '.$schema->columnType($step['length'], $driver));
            } elseif ($step['kind'] === 'index') {
                $pdo->exec($schema->indexSql($step['name'], $step['columns'], $driver));
            } elseif ($step['kind'] === 'drop') {
                $pdo->exec('DROP TRIGGER '.$name.'_'.$step['name']);
            } else {
                $guards = (new \ReflectionMethod(BillingSchema::class, 'guards'))->invoke($billing, $step['table'], $driver, $name, $table, $offset + 1);
                $pdo->exec($guards[$name.'_'.$step['name']]['sql']);
            }
        }
    }

    private function refused(callable $operation, ?string $reason = null): void
    {
        try {
            $operation();
            $this->fail('Incomplete or drifted retrieval evidence must be refused.');
        } catch (BillingException $error) {
            $reason === null ? $this->assertNotSame('', $error->reason) : $this->assertSame($reason, $error->reason);
        }
    }
}
