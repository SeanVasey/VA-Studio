<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Memberships\Billing\BillingAwardFence;
use App\Domain\Memberships\Billing\BillingException;
use App\Domain\Memberships\Billing\BillingSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use Tests\Support\BillingIntervalFixtures;
use Tests\Support\BillingStripeFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

/** Synthetic structural evidence only; no retained fixture is an approval or a paid-invoice proof. */
class BillingAwardFenceTest extends TestCase
{
    use BillingIntervalFixtures;
    use FinalizationDatabaseMigrations;

    public function test_exact_retained_six_table_upgrade_is_idempotent_and_keeps_every_row(): void
    {
        $this->retainRows();
        $before = $this->billingSnapshot();
        $this->removeFence();
        $this->refused(fn () => (new BillingSchema)->assertOwned(DB::connection()->getPdo()));
        // The prior forward migration remains a six-table migration even with the new application source.
        (require base_path('database/migrations/2026_10_13_261000_membership_billing_interval_ownership.php'))->up();
        $migration = require base_path('database/migrations/2026_10_13_262000_membership_billing_award_fence.php');
        $migration->up();
        $migration->up();
        (new BillingSchema)->assertOwned(DB::connection()->getPdo());
        $this->assertSame($before, $this->billingSnapshot());
        $this->assertSame([['id' => 1]], DB::connection()->getPdo()->query('SELECT id FROM '.(new BillingAwardFence)->table())->fetchAll(PDO::FETCH_ASSOC));
        try {
            $migration->down();
            $this->fail('Retained ordering cannot be rolled back destructively.');
        } catch (\LogicException) {
            $this->assertSame($before, $this->billingSnapshot());
        }
    }

    public function test_each_missing_guard_and_empty_owned_seed_recover_without_changing_evidence(): void
    {
        $this->retainRows();
        $before = $this->billingSnapshot();
        $pdo = DB::connection()->getPdo();
        foreach ($this->guardNames() as $guard) {
            $pdo->exec('DROP TRIGGER '.$guard);
            $this->refused(fn () => (new BillingSchema)->assertOwned($pdo));
            (new BillingAwardFence)->upgrade();
            $this->assertSame($before, $this->billingSnapshot());
        }
        $pdo->exec('DROP TRIGGER '.BillingAwardFence::TABLE.'_delete');
        $pdo->exec('DELETE FROM '.(new BillingAwardFence)->table());
        $this->refused(fn () => (new BillingAwardFence)->assertOwned($pdo));
        (new BillingAwardFence)->upgrade();
        (new BillingAwardFence)->assertOwned($pdo);
        $this->assertSame($before, $this->billingSnapshot());
        // Native interruption after CREATE TABLE and before seed/guards is also explicitly supported.
        foreach ($this->guardNames() as $guard) {
            $pdo->exec('DROP TRIGGER '.$guard);
        }
        $pdo->exec('DELETE FROM '.(new BillingAwardFence)->table());
        (new BillingAwardFence)->upgrade();
        (new BillingAwardFence)->assertOwned($pdo);
        $this->assertSame($before, $this->billingSnapshot());
    }

    public function test_foreign_successor_shape_refuses_before_creating_any_guard_or_rewriting_rows(): void
    {
        $this->retainRows();
        $before = $this->billingSnapshot();
        $this->removeFence();
        $pdo = DB::connection()->getPdo();
        $pdo->exec('CREATE TABLE '.BillingAwardFence::TABLE.' (id INTEGER PRIMARY KEY, foreign_value INTEGER)');
        $pdo->exec('INSERT INTO '.BillingAwardFence::TABLE.' (id, foreign_value) VALUES (1, 9)');
        $this->refused(fn () => (new BillingAwardFence)->upgrade());
        $this->assertSame($before, $this->billingSnapshot());
        $this->assertSame(9, (int) $pdo->query('SELECT foreign_value FROM '.BillingAwardFence::TABLE)->fetchColumn());
        $this->assertSame(0, $this->guardCount());
    }

    public function test_drifted_event_guard_refuses_before_repairing_a_missing_owned_guard(): void
    {
        $this->retainRows();
        $before = $this->billingSnapshot();
        $pdo = DB::connection()->getPdo();
        $guard = (new BillingAwardFence)->eventGuardName();
        $pdo->exec('DROP TRIGGER '.$guard);
        $pdo->exec('DROP TRIGGER '.BillingAwardFence::TABLE.'_update');
        $pdo->exec(DB::getDriverName() === 'mysql'
            ? 'CREATE TRIGGER '.$guard.' BEFORE INSERT ON '.BillingSchema::TABLES[3].' FOR EACH ROW BEGIN SET @synthetic_fence_drift = 1; END'
            : 'CREATE TRIGGER '.$guard.' BEFORE INSERT ON '.BillingSchema::TABLES[3].' BEGIN SELECT 1; END');
        $this->refused(fn () => (new BillingAwardFence)->upgrade());
        $this->assertSame(3, $this->guardCount());
        $this->assertSame($before, $this->billingSnapshot());
    }

    public function test_singleton_is_immutable_and_missing_seed_prevents_raw_hint_insertion(): void
    {
        $pdo = DB::connection()->getPdo();
        foreach (['UPDATE '.BillingAwardFence::TABLE.' SET id = id', 'DELETE FROM '.BillingAwardFence::TABLE,
            'INSERT INTO '.BillingAwardFence::TABLE.' (id) VALUES (1)', 'INSERT INTO '.BillingAwardFence::TABLE.' (id) VALUES (2)'] as $sql) {
            $this->pdoRefuses(fn () => $pdo->exec($sql));
        }
        $pdo->exec('DROP TRIGGER '.BillingAwardFence::TABLE.'_delete');
        $pdo->exec('DELETE FROM '.BillingAwardFence::TABLE);
        $row = $this->hint();
        $this->pdoRefuses(fn () => $this->insertIntervalRow(BillingSchema::TABLES[3], $row));
        $this->assertSame(0, DB::table(BillingSchema::TABLES[3])->count());
        (new BillingAwardFence)->upgrade();
        $this->insertIntervalRow(BillingSchema::TABLES[3], $row);
        $this->assertSame(1, DB::table(BillingSchema::TABLES[3])->count());
    }

    public function test_lock_requires_owned_outer_transaction_and_upgrade_requires_no_transaction(): void
    {
        $pdo = DB::connection()->getPdo();
        $fence = new BillingAwardFence;
        $this->refused(fn () => $fence->lock($pdo), 'award_fence_transaction');
        DB::transaction(function () use ($pdo, $fence): void {
            $fence->lock($pdo);
            $this->refused(fn () => $fence->upgrade(), 'migration_transaction');
            $this->assertSame(1, DB::connection()->transactionLevel());
        });
    }

    public function test_sqlite_replay_lock_holds_writer_intent_even_without_a_period_insert(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite writer-intent behavior; native row-lock races run separately.');
        }
        $fence = new BillingAwardFence;
        $objects = DB::connection()->getPdo()->query("SELECT name, sql FROM main.sqlite_master WHERE name = '".BillingAwardFence::TABLE."' OR type = 'trigger' ORDER BY type DESC")->fetchAll(PDO::FETCH_ASSOC);
        $owned = array_flip([BillingAwardFence::TABLE, ...$this->guardNames()]);
        $sql = array_column(array_filter($objects, fn (array $row): bool => isset($owned[$row['name']])), 'sql', 'name');
        $path = tempnam(sys_get_temp_dir(), 'membership-fence-');
        $default = DB::getDefaultConnection();
        $peer = null;
        try {
            config(['database.connections.fence_fixture' => ['driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true]]);
            DB::setDefaultConnection('fence_fixture');
            $pdo = DB::connection()->getPdo();
            $pdo->exec('CREATE TABLE '.BillingSchema::TABLES[3].' (id INTEGER PRIMARY KEY)');
            $pdo->exec($sql[BillingAwardFence::TABLE]);
            $pdo->exec('INSERT INTO '.$fence->table().' (id) VALUES (1)');
            foreach ($this->guardNames() as $guard) {
                $pdo->exec($sql[$guard]);
            }
            $peer = new PDO('sqlite:'.$path);
            $peer->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $peer->exec('PRAGMA busy_timeout = 0');
            DB::beginTransaction();
            $fence->lock($pdo);
            try {
                $peer->exec('INSERT INTO '.BillingSchema::TABLES[3].' (id) VALUES (1)');
                $this->fail('A replay must hold the same writer fence as a first award.');
            } catch (PDOException $error) {
                $this->assertStringContainsString('database is locked', $error->getMessage());
            }
            DB::commit();
            $this->assertSame(1, $peer->exec('INSERT INTO '.BillingSchema::TABLES[3].' (id) VALUES (1)'));
        } finally {
            if (DB::connection()->transactionLevel() > 0) {
                DB::rollBack();
            }
            $peer = null;
            DB::purge('fence_fixture');
            DB::setDefaultConnection($default);
            @unlink($path);
        }
    }

    private function retainRows(): void
    {
        F::configure();
        $binding = F::binding();
        [$a, $b, $c, $d] = $this->positions(4);
        $one = $this->intervalRows($binding, $a, $b);
        $two = $this->intervalRows($binding, $c, $d);
        $this->insertIntervalRow(BillingSchema::TABLES[2], $one[BillingSchema::TABLES[2]]);
        $this->insertIntervalRow(BillingSchema::TABLES[5], $two[BillingSchema::TABLES[5]]);
        $this->insertIntervalRow(BillingSchema::TABLES[3], $this->hint());
    }

    private function hint(): array
    {
        $pdo = DB::connection()->getPdo();
        $pdo->exec("INSERT INTO production_membership_billing_positions (kind, created_at) VALUES ('hint', '2026-10-13 00:00:00')");

        return ['id' => (string) Str::uuid(), 'provider_event_ref_hash' => hash('sha256', (string) Str::uuid()), 'type' => 'invoice.paid',
            'mode' => 'test', 'provider_account_hash' => hash('sha256', 'synthetic account'), 'invoice_ref_hash' => hash('sha256', 'synthetic invoice'),
            'received_at' => '2026-10-13 00:00:00', 'hint_position' => (int) $pdo->lastInsertId(), 'payload_hash' => hash('sha256', 'synthetic body'),
            'disposition' => 'retrieval_hint', 'payload_ciphertext' => 'synthetic fixture', 'seal' => hash('sha256', 'synthetic seal'), 'created_at' => '2026-10-13 00:00:00'];
    }

    private function removeFence(): void
    {
        $pdo = DB::connection()->getPdo();
        foreach ($this->guardNames() as $guard) {
            $pdo->exec('DROP TRIGGER '.$guard);
        }
        $pdo->exec('DROP TABLE '.(new BillingAwardFence)->table());
    }

    private function guardNames(): array
    {
        return [BillingAwardFence::TABLE.'_insert', BillingAwardFence::TABLE.'_update', BillingAwardFence::TABLE.'_delete', (new BillingAwardFence)->eventGuardName()];
    }

    private function guardCount(): int
    {
        $pdo = DB::connection()->getPdo();
        $query = DB::getDriverName() === 'sqlite' ? "SELECT name FROM main.sqlite_master WHERE type = 'trigger'"
            : 'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE BINARY TRIGGER_SCHEMA = BINARY DATABASE()';

        return count(array_intersect($this->guardNames(), $pdo->query($query)->fetchAll(PDO::FETCH_COLUMN)));
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

    private function pdoRefuses(callable $work): void
    {
        try {
            $work();
            $this->fail('Expected a database guard refusal.');
        } catch (PDOException $error) {
            $this->assertNotEmpty($error->getCode());
        }
    }
}
