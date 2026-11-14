<?php

namespace Tests\Feature\ProductionIdentity;

use App\Domain\Customers\Preferences\ConsentMigrationAdmission;
use App\Domain\Customers\ProductionIdentity\AccountAdmissionGuards;
use App\Domain\Customers\ProductionIdentity\AccountAdmissionUpgrade;
use App\Domain\Customers\ProductionIdentity\CurrentCatalog;
use App\Domain\Customers\ProductionIdentity\IdentityMigrationOwnership;
use App\Domain\Customers\ProductionIdentity\IdentitySchema;
use App\Domain\Customers\ProductionIdentity\SchemaProofFrame;
use App\Support\MigrationDefinitions;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;
use ReflectionMethod;
use Tests\Support\ProductionIdentityFixture;
use Tests\TestCase;

final class AccountAdmissionGuardUpgradeTest extends TestCase
{
    use ProductionIdentityFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->identitySetup();
    }

    public function test_fresh_ordering_and_populated_up_down_preserve_old_definitions_and_every_retained_row(): void
    {
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $driver = $connection->getDriverName();
        $upgrade = new AccountAdmissionUpgrade;
        $upgrade->down($connection);
        $this->assertStable(AccountAdmissionGuards::V1);
        $historical = (new ReflectionMethod(MigrationDefinitions::load('2026_10_06_000040_customer_accounts.php'), 'guards'))->invoke(MigrationDefinitions::load('2026_10_06_000040_customer_accounts.php'));
        foreach ($historical as $name => $guard) {
            $actual = AccountAdmissionGuards::definitions($driver, AccountAdmissionGuards::V1)[$name];
            $this->assertSame($guard['body'], $actual['body']);
            $this->assertSame($guard['statement'], $actual['sql']);
        }
        // Exercise old migrations with the dynamically loaded compatible inspectors before the late upgrade.
        (require database_path('migrations/2026_10_07_247000_production_customer_identity.php'))->up();
        $this->completeIdentity($this->requestIdentity('enroll', 'account-upgrade@example.test'));
        $before = $this->retainedRows($pdo);
        $upgrade->up($connection);
        $this->assertStable($driver === 'mysql' ? AccountAdmissionGuards::V2 : AccountAdmissionGuards::V1);
        $upgrade->up($connection); // crash after DROP, before ledger insert: verify/no-op.
        $this->assertSame($before, $this->retainedRows($pdo));
        $upgrade->down($connection);
        $upgrade->down($connection); // crash after restoring V1, before ledger delete: verify/no-op.
        $this->assertStable(AccountAdmissionGuards::V1);
        $this->assertSame($before, $this->retainedRows($pdo));
    }

    public function test_exact_interrupted_overlap_is_migration_only_and_resumes_in_both_directions(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Only MySQL uses overlapping trigger DDL.');
        }
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $upgrade = new AccountAdmissionUpgrade;
        $upgrade->down($connection);
        $new = AccountAdmissionGuards::definitions('mysql', AccountAdmissionGuards::V2)[AccountAdmissionGuards::NEW_INSERT];
        $pdo->exec('CREATE TRIGGER customer_accounts_insert_v2 BEFORE INSERT ON customer_accounts FOR EACH ROW FOLLOWS customer_accounts_insert '.$new['body']);
        $this->assertSame(AccountAdmissionGuards::OVERLAP, AccountAdmissionGuards::migrationState($pdo, 'mysql')['version']);
        $this->assertRuntimeRefused();
        $upgrade->up($connection); // crash after CREATE, before ledger insert.
        $this->assertStable(AccountAdmissionGuards::V2);
        $old = AccountAdmissionGuards::definitions('mysql', AccountAdmissionGuards::V1)[AccountAdmissionGuards::OLD_INSERT];
        $pdo->exec('CREATE TRIGGER customer_accounts_insert BEFORE INSERT ON customer_accounts FOR EACH ROW PRECEDES customer_accounts_insert_v2 '.$old['body']);
        $this->assertRuntimeRefused();
        $upgrade->down($connection);
        $this->assertStable(AccountAdmissionGuards::V1);
    }

    public function test_existing_transaction_is_refused_without_trigger_changes(): void
    {
        $connection = DB::connection();
        $before = AccountAdmissionGuards::stable($connection->getPdo(), $connection->getDriverName())['fingerprint'];
        $connection->beginTransaction();
        try {
            (new AccountAdmissionUpgrade)->up($connection);
            $this->fail('An active transaction was admitted for implicit-commit DDL.');
        } catch (LogicException) {
            $this->assertTrue($connection->getPdo()->inTransaction());
        } finally {
            $connection->rollBack();
        }
        $this->assertSame($before, AccountAdmissionGuards::stable($connection->getPdo(), $connection->getDriverName())['fingerprint']);
    }

    public function test_sqlite_down_noop_still_refuses_a_missing_guard(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite-specific verified no-op.');
        }
        DB::unprepared('DROP TRIGGER customer_accounts_insert');
        $this->expectException(LogicException::class);
        (new AccountAdmissionUpgrade)->down(DB::connection());
    }

    public function test_migration_only_dependency_floor_refuses_foreign_view_and_incoming_key_before_ddl(): void
    {
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $driver = $connection->getDriverName();
        $before = AccountAdmissionGuards::stable($pdo, $driver)['fingerprint'];
        $pdo->exec('CREATE VIEW account_upgrade_foreign_view AS SELECT id FROM customer_accounts');
        try {
            try {
                (new AccountAdmissionUpgrade)->up($connection);
                $this->fail('An unowned view dependency was admitted.');
            } catch (LogicException) {
                $this->assertSame($before, AccountAdmissionGuards::stable($pdo, $driver)['fingerprint']);
            }
        } finally {
            $pdo->exec('DROP VIEW account_upgrade_foreign_view');
        }
        $type = $driver === 'mysql' ? 'bigint unsigned' : 'integer';
        $pdo->exec('CREATE TABLE account_upgrade_foreign_key (account_id '.$type.' NOT NULL, CONSTRAINT account_upgrade_foreign_fk FOREIGN KEY (account_id) REFERENCES customer_accounts(id) ON DELETE RESTRICT)');
        try {
            try {
                (new AccountAdmissionUpgrade)->up($connection);
                $this->fail('An unowned incoming account key was admitted.');
            } catch (LogicException) {
                $this->assertSame($before, AccountAdmissionGuards::stable($pdo, $driver)['fingerprint']);
            }
        } finally {
            $pdo->exec('DROP TABLE account_upgrade_foreign_key');
        }
    }

    public function test_migration_only_scanner_accepts_legitimate_triggers_and_refuses_foreign_or_altered_dependency(): void
    {
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $driver = $connection->getDriverName();
        $inspect = fn () => (new IdentityMigrationOwnership)->assertAccountUpgradeDependents($pdo, $driver);
        $inspect(); // A fresh composed install includes real legacy, identity, consent, feature and membership consumers.
        $this->assertTrue(true);
        $pdo->exec('CREATE TABLE account_upgrade_trigger_target (id integer NOT NULL)');
        $sql = $driver === 'mysql'
            ? 'CREATE TRIGGER account_upgrade_foreign_trigger BEFORE INSERT ON account_upgrade_trigger_target FOR EACH ROW SET @account_upgrade_test=(SELECT COUNT(*) FROM customer_accounts)'
            : 'CREATE TRIGGER account_upgrade_foreign_trigger BEFORE INSERT ON account_upgrade_trigger_target BEGIN SELECT id FROM customer_accounts; END';
        $pdo->exec($sql);
        try {
            $this->assertDependencyRefused($inspect);
        } finally {
            $pdo->exec('DROP TRIGGER account_upgrade_foreign_trigger');
            $pdo->exec('DROP TABLE account_upgrade_trigger_target');
        }
        $name = 'customer_consent_events_retain_insert';
        $source = MigrationDefinitions::load('2026_10_07_250000_customer_consent.php');
        $definition = (new ReflectionMethod($source, 'triggers'))->invoke($source, $driver)[$name];
        $pdo->exec('DROP TRIGGER '.$name);
        $pdo->exec('CREATE TRIGGER '.$name.' BEFORE INSERT ON customer_consent_events '.($driver === 'mysql'
            ? 'FOR EACH ROW SET @account_upgrade_test=1' : 'BEGIN SELECT 1; END'));
        try {
            // Removing the account predicate altogether must not evade the account-reaching source allowlist.
            $this->assertDependencyRefused($inspect);
        } finally {
            $pdo->exec('DROP TRIGGER '.$name);
            $pdo->exec($definition['sql']);
        }
        $inspect();
        $this->assertTrue(true);
    }

    public function test_native_account_frame_captures_once_reuses_cheaply_and_proves_terminal_in_full(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Physical account MDL and native query counters.');
        }
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $calls = 0;
        $connection->beginTransaction();
        $pdo->exec('SAVEPOINT account_upgrade_frame');
        $frame = SchemaProofFrame::open($connection, $pdo, static function () use ($pdo): void {
            $pdo->exec('RELEASE SAVEPOINT account_upgrade_frame');
            $pdo->exec('SAVEPOINT account_upgrade_frame');
        });
        $prove = function () use ($pdo, &$calls): void {
            $calls++;
            AccountAdmissionGuards::stable($pdo, 'mysql');
        };
        $read = static fn (): int => (int) $pdo->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_NUM)[1] + CurrentCatalog::statementCount();
        try {
            $start = $read();
            SchemaProofFrame::prove($pdo, 'account-test', ['customer_accounts'], $prove);
            $first = $read() - $start - 1;
            $start = $read();
            SchemaProofFrame::prove($pdo, 'account-test', ['customer_accounts'], $prove);
            $hit = $read() - $start - 1;
            $this->assertSame(1, $calls);
            $this->assertLessThan(intdiv($first, 2), $hit);
            $this->assertLessThanOrEqual(15, $hit, 'Total primary plus companion SQL must retain the memo budget.');
            $pins = (new \ReflectionProperty($frame, 'accountProofs'))->getValue($frame);
            $this->assertSame(AccountAdmissionGuards::V2, $pins['account-test']['version']);
            $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $pins['account-test']['fingerprint']);
            $frame->terminal();
            SchemaProofFrame::prove($pdo, 'account-test', ['customer_accounts'], $prove);
            $this->assertSame(2, $calls);
            fwrite(STDERR, "account frame: first={$first}, hit={$hit}, full callbacks={$calls}\n");
        } finally {
            $frame->close();
            $connection->rollBack();
        }
    }

    public function test_native_memo_rechecks_reserved_names_created_without_account_metadata_lock(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Independent native namespace changes outside account MDL.');
        }
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $other = $this->independentMysql();
        $other->exec('CREATE TABLE account_upgrade_namespace_target (id integer PRIMARY KEY)');
        $cases = [
            ['CREATE TABLE customer_accounts_insert (id integer)', 'DROP TABLE customer_accounts_insert'],
            ['CREATE PROCEDURE customer_accounts_insert() SELECT 1', 'DROP PROCEDURE customer_accounts_insert'],
            ['CREATE EVENT customer_accounts_insert ON SCHEDULE AT CURRENT_TIMESTAMP + INTERVAL 1 DAY DISABLE DO SET @account_upgrade_test=1', 'DROP EVENT customer_accounts_insert'],
            ['CREATE TRIGGER customer_accounts_insert AFTER INSERT ON account_upgrade_namespace_target FOR EACH ROW SET @account_upgrade_test=1', 'DROP TRIGGER customer_accounts_insert'],
            ['ALTER TABLE account_upgrade_namespace_target ADD UNIQUE KEY customer_accounts_insert(id)', 'ALTER TABLE account_upgrade_namespace_target DROP INDEX customer_accounts_insert'],
        ];
        try {
            foreach ($cases as [$create, $drop]) {
                // As in existing native frame-dependent tests: current dictionary visibility is a separate test input.
                $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
                $connection->beginTransaction();
                $pdo->exec('SAVEPOINT account_upgrade_namespace');
                $frame = SchemaProofFrame::open($connection, $pdo, static function () use ($pdo): void {
                    $pdo->exec('RELEASE SAVEPOINT account_upgrade_namespace');
                    $pdo->exec('SAVEPOINT account_upgrade_namespace');
                });
                $created = false;
                try {
                    $prove = fn () => AccountAdmissionGuards::stable($pdo, 'mysql');
                    SchemaProofFrame::prove($pdo, 'account-namespace', ['customer_accounts'], $prove);
                    SchemaProofFrame::prove($pdo, 'account-namespace', ['customer_accounts'], $prove);
                    $other->exec($create);
                    $created = true;
                    $this->assertDependencyRefused(fn () => SchemaProofFrame::prove($pdo, 'account-namespace', ['customer_accounts'], $prove));
                } finally {
                    $frame->close();
                    $connection->rollBack();
                    if ($created) {
                        $other->exec($drop);
                    }
                }
            }
        } finally {
            $other->exec('DROP TABLE account_upgrade_namespace_target');
        }
    }

    public function test_native_trigger_dependency_qualification_refuses_target_and_allows_similar_named_peer(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Native cross-schema stored trigger resolution.');
        }
        $pdo = DB::connection()->getPdo();
        $database = DB::connection()->getDatabaseName();
        $peer = $database.'-account-guard-peer';
        $quote = static fn (string $name): string => '`'.str_replace('`', '``', $name).'`';
        $statement = $pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');
        $statement->execute([$peer]);
        $this->assertSame([], $statement->fetchAll());
        $pdo->exec('CREATE DATABASE '.$quote($peer));
        try {
            $pdo->exec('CREATE TABLE '.$quote($peer).'.customer_accounts (id integer PRIMARY KEY)');
            $pdo->exec('CREATE TABLE '.$quote($peer).'.trigger_target (id integer PRIMARY KEY)');
            foreach (['customer_accounts', $quote($peer).'.customer_accounts', $quote($database).'.customer_accounts'] as $index => $reference) {
                $pdo->exec('CREATE TRIGGER '.$quote($peer).'.account_upgrade_peer BEFORE INSERT ON '.$quote($peer).'.trigger_target FOR EACH ROW SET @account_upgrade_test=(SELECT COUNT(*) FROM '.$reference.')');
                try {
                    $inspect = fn () => (new IdentityMigrationOwnership)->assertAccountUpgradeDependents($pdo, 'mysql');
                    if ($index === 2) {
                        $this->assertDependencyRefused($inspect);
                    } else {
                        $inspect();
                        $this->assertTrue(true);
                    }
                } finally {
                    $pdo->exec('DROP TRIGGER '.$quote($peer).'.account_upgrade_peer');
                }
            }
        } finally {
            $pdo->exec('DROP DATABASE '.$quote($peer));
        }
    }

    public function test_native_early_repeatable_read_snapshot_refuses_new_reserved_namespace_on_memo_hit(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Native early REPEATABLE READ snapshot and independent namespace visibility.');
        }
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $other = $this->independentMysql();
        $this->assertSame('REPEATABLE-READ', $pdo->query('SELECT @@SESSION.transaction_isolation')->fetchColumn());
        $connection->beginTransaction();
        // Establish an actual early consistent data snapshot BEFORE the first full ownership proof.
        $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $pdo->exec('SAVEPOINT account_upgrade_early_rr');
        $frame = SchemaProofFrame::open($connection, $pdo, static function () use ($pdo): void {
            $pdo->exec('RELEASE SAVEPOINT account_upgrade_early_rr');
            $pdo->exec('SAVEPOINT account_upgrade_early_rr');
        });
        $created = false;
        $calls = 0;
        $prove = function () use ($pdo, &$calls): void {
            $calls++;
            AccountAdmissionGuards::stable($pdo, 'mysql');
        };
        try {
            SchemaProofFrame::prove($pdo, 'account-early-rr', ['customer_accounts'], $prove);
            SchemaProofFrame::prove($pdo, 'account-early-rr', ['customer_accounts'], $prove);
            $this->assertSame(1, $calls);
            $other->exec('CREATE TABLE customer_accounts_insert (id integer PRIMARY KEY)');
            $created = true;
            $this->assertDependencyRefused(fn () => SchemaProofFrame::prove($pdo, 'account-early-rr', ['customer_accounts'], $prove));
        } finally {
            $frame->close();
            $connection->rollBack();
            if ($created) {
                $other->exec('DROP TABLE customer_accounts_insert');
            }
        }
    }

    public function test_native_same_connection_implicit_ddl_invalidates_witness_before_memo_reuse(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Native implicit DDL commit and physical transaction witness.');
        }
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $connection->beginTransaction();
        $pdo->exec('SAVEPOINT account_upgrade_implicit');
        $frame = SchemaProofFrame::open($connection, $pdo, static function () use ($pdo): void {
            $pdo->exec('RELEASE SAVEPOINT account_upgrade_implicit');
            $pdo->exec('SAVEPOINT account_upgrade_implicit');
        });
        $calls = 0;
        $prove = function () use ($pdo, &$calls): void {
            $calls++;
            AccountAdmissionGuards::stable($pdo, 'mysql');
        };
        $created = false;
        try {
            SchemaProofFrame::prove($pdo, 'account-implicit', ['customer_accounts'], $prove);
            SchemaProofFrame::prove($pdo, 'account-implicit', ['customer_accounts'], $prove);
            $this->assertSame(1, $calls);
            $pdo->exec('CREATE TABLE account_upgrade_implicit_commit (id integer PRIMARY KEY)');
            $created = true;
            $this->assertFalse($pdo->inTransaction());
            SchemaProofFrame::prove($pdo, 'account-implicit', ['customer_accounts'], $prove);
            $this->assertSame(2, $calls, 'Lost physical witness must execute a complete callback instead of reusing its proof.');
            $this->assertTrue((new \ReflectionProperty($frame, 'closed'))->getValue($frame));
        } finally {
            $frame->close();
            $connection->rollBack();
            if ($created) {
                $pdo->exec('DROP TABLE account_upgrade_implicit_commit');
            }
        }
    }

    private function independentMysql(): PDO
    {
        $config = DB::connection()->getConfig();
        $address = empty($config['unix_socket']) ? 'host='.$config['host'].';port='.$config['port'] : 'unix_socket='.$config['unix_socket'];
        $pdo = new PDO('mysql:'.$address.';dbname='.$config['database'].';charset=utf8mb4', $config['username'], $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_STRINGIFY_FETCHES => false]);
        $pdo->exec('SET SESSION lock_wait_timeout=3, innodb_lock_wait_timeout=3');

        return $pdo;
    }

    private function assertDependencyRefused(callable $inspect): void
    {
        try {
            $inspect();
            $this->fail('Foreign or altered account-reaching trigger was admitted.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }
    }

    private function assertStable(string $version): void
    {
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $driver = $connection->getDriverName();
        $this->assertSame($version, AccountAdmissionGuards::stable($pdo, $driver)['version']);
        [$present] = (new IdentityMigrationOwnership)->inspect($pdo, $driver);
        $this->assertNotContains(false, $present);
        (new ConsentMigrationAdmission($pdo, $driver, $connection->getDatabaseName()))->dependencies();
    }

    private function assertRuntimeRefused(): void
    {
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        foreach ([fn () => AccountAdmissionGuards::stable($pdo, 'mysql'),
            fn () => (new IdentityMigrationOwnership)->inspect($pdo, 'mysql'),
            fn () => (new ConsentMigrationAdmission($pdo, 'mysql', $connection->getDatabaseName()))->dependencies()] as $inspect) {
            try {
                $inspect();
                $this->fail('Ordinary runtime admitted transitional overlap.');
            } catch (LogicException) {
                $this->assertTrue(true);
            }
        }
    }

    private function retainedRows(PDO $pdo): array
    {
        $rows = [];
        foreach (['users', 'customer_accounts', 'quote_owners', 'audit_events', 'customer_consent_events', 'customer_consent_states', ...array_keys(IdentitySchema::definitions())] as $table) {
            $rows[$table] = $pdo->query('SELECT * FROM `'.$table.'` ORDER BY '.($table === 'quote_owners' ? 'owner_key' : 'id'))->fetchAll(PDO::FETCH_ASSOC);
        }

        return $rows;
    }
}
