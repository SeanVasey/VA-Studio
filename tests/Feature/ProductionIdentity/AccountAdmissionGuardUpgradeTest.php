<?php

namespace Tests\Feature\ProductionIdentity;

use App\Domain\Customers\Preferences\ConsentMigrationAdmission;
use App\Domain\Customers\ProductionIdentity\AccountAdmissionGuards;
use App\Domain\Customers\ProductionIdentity\AccountAdmissionUpgrade;
use App\Domain\Customers\ProductionIdentity\IdentityMigrationOwnership;
use App\Domain\Customers\ProductionIdentity\IdentitySchema;
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
