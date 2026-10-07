<?php

namespace Tests\Feature\ProductionIdentity;

use App\Domain\Customers\ProductionIdentity\IdentitySchema;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ProductionIdentityFixture;
use Tests\TestCase;

final class ProductionIdentityDependencyAdmissionTest extends TestCase
{
    use ProductionIdentityFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->identitySetup();
    }

    public static function parentFloors(): array
    {
        return [
            'users credential column' => ['users', 'password'],
            'users credential nullability' => ['users', 'nullable_password'],
            'account access generation' => ['customer_accounts', 'access_version'],
            'owner identity type' => ['quote_owners', 'owner_key'],
        ];
    }

    #[DataProvider('parentFloors')]
    public function test_actual_parent_metadata_is_refused_before_any_owned_ddl(string $table, string $change): void
    {
        $migration = require database_path('migrations/2026_10_07_247000_production_customer_identity.php');
        $migration->down();
        $pdo = DB::connection()->getPdo();
        $driver = DB::getDriverName();
        $saved = $this->parentDdl($pdo, $driver, $table);
        try {
            $this->foreignKeys($pdo, $driver, false);
            $pdo->exec('DROP TABLE `'.$table.'`');
            $sql = $saved['table'];
            $sql = match ([$driver, $change]) {
                ['sqlite', 'password'] => str_replace('"password" varchar not null', '"password" integer not null', $sql),
                ['sqlite', 'nullable_password'] => str_replace('"password" varchar not null', '"password" varchar', $sql),
                ['sqlite', 'access_version'] => str_replace('"access_version" integer not null', '"access_version" text not null', $sql),
                ['sqlite', 'owner_key'] => str_replace('"owner_key" varchar not null', '"owner_key" integer not null', $sql),
                ['mysql', 'password'] => str_replace('`password` varchar(255)', '`password` varchar(64)', $sql),
                ['mysql', 'nullable_password'] => preg_replace('/(`password`[^,\n]*) NOT NULL/', '$1 DEFAULT NULL', $sql),
                ['mysql', 'access_version'] => str_replace('`access_version` int unsigned NOT NULL', '`access_version` bigint unsigned NOT NULL', $sql),
                ['mysql', 'owner_key'] => str_replace('`owner_key` char(64)', '`owner_key` varchar(64)', $sql),
            };
            $this->assertNotSame($saved['table'], $sql, 'The fixture must change actual parent metadata.');
            $pdo->exec($sql);
            foreach ($saved['remaining'] as $ddl) {
                $pdo->exec($ddl);
            }
            $this->foreignKeys($pdo, $driver, true);
            $this->assertRefusedWithoutDdl($migration, $pdo, $driver);
        } finally {
            $this->foreignKeys($pdo, $driver, false);
            $pdo->exec('DROP TABLE IF EXISTS `'.$table.'`');
            $pdo->exec($saved['table']);
            foreach ($saved['remaining'] as $ddl) {
                $pdo->exec($ddl);
            }
            $this->foreignKeys($pdo, $driver, true);
        }
        $migration->up();
        $this->assertSame(7, $this->ownedCount($pdo, $driver));
    }

    public static function parentGuards(): array
    {
        return ['account immutable owner' => ['customer_accounts_update'], 'retained owner' => ['quote_owners_immutable_delete']];
    }

    #[DataProvider('parentGuards')]
    public function test_changed_or_missing_actual_parent_guard_is_refused_before_owned_ddl(string $name): void
    {
        $migration = require database_path('migrations/2026_10_07_247000_production_customer_identity.php');
        $migration->down();
        $pdo = DB::connection()->getPdo();
        $driver = DB::getDriverName();
        $sql = $driver === 'sqlite'
            ? $pdo->query("SELECT sql FROM main.sqlite_master WHERE type='trigger' AND name='$name'")->fetchColumn()
            : $pdo->query('SHOW CREATE TRIGGER `'.$name.'`')->fetch(PDO::FETCH_ASSOC)['SQL Original Statement'];
        $pdo->exec('DROP TRIGGER `'.$name.'`');
        try {
            $this->assertRefusedWithoutDdl($migration, $pdo, $driver);
            $changed = str_replace("'Customer identity is retained'", "'Changed identity policy'", $sql);
            $changed = str_replace("'Quote evidence is immutable'", "'Changed identity policy'", $changed);
            $this->assertNotSame($sql, $changed);
            $pdo->exec($changed);
            $this->assertRefusedWithoutDdl($migration, $pdo, $driver);
        } finally {
            $pdo->exec('DROP TRIGGER IF EXISTS `'.$name.'`');
            $pdo->exec($sql);
        }
        $migration->up();
        $this->assertSame(7, $this->ownedCount($pdo, $driver));
    }

    public function test_required_unique_key_and_disabled_foreign_keys_are_refused_before_owned_ddl(): void
    {
        $migration = require database_path('migrations/2026_10_07_247000_production_customer_identity.php');
        $migration->down();
        $pdo = DB::connection()->getPdo();
        $driver = DB::getDriverName();
        $pdo->exec($driver === 'sqlite' ? 'DROP INDEX users_email_unique' : 'ALTER TABLE users DROP INDEX users_email_unique');
        try {
            $this->assertRefusedWithoutDdl($migration, $pdo, $driver);
        } finally {
            $pdo->exec('CREATE UNIQUE INDEX users_email_unique ON users(email)');
        }
        $this->foreignKeys($pdo, $driver, false);
        try {
            $this->assertRefusedWithoutDdl($migration, $pdo, $driver);
        } finally {
            $this->foreignKeys($pdo, $driver, true);
        }
        $migration->up();
        $this->assertSame(7, $this->ownedCount($pdo, $driver));
    }

    public function test_permanent_parent_temporary_shadow_is_refused_before_owned_ddl(): void
    {
        $migration = require database_path('migrations/2026_10_07_247000_production_customer_identity.php');
        $migration->down();
        $pdo = DB::connection()->getPdo();
        $driver = DB::getDriverName();
        $pdo->exec('CREATE TEMPORARY TABLE users (id integer)');
        try {
            $this->assertRefusedWithoutDdl($migration, $pdo, $driver);
        } finally {
            $pdo->exec($driver === 'sqlite' ? 'DROP TABLE temp.users' : 'DROP TEMPORARY TABLE users');
        }
        $migration->up();
        $this->assertSame(7, $this->ownedCount($pdo, $driver));
    }

    public function test_empty_owned_prefix_does_not_allow_a_withdrawn_dependency_guard_to_be_repaired(): void
    {
        $migration = require database_path('migrations/2026_10_07_247000_production_customer_identity.php');
        $migration->down();
        $pdo = DB::connection()->getPdo();
        $driver = DB::getDriverName();
        foreach (array_slice(array_keys(IdentitySchema::definitions()), 0, 3) as $table) {
            $pdo->exec(IdentitySchema::tableSql($table, $driver));
        }
        $name = 'customer_accounts_update';
        $sql = $driver === 'sqlite'
            ? $pdo->query("SELECT sql FROM main.sqlite_master WHERE type='trigger' AND name='$name'")->fetchColumn()
            : $pdo->query('SHOW CREATE TRIGGER `'.$name.'`')->fetch(PDO::FETCH_ASSOC)['SQL Original Statement'];
        $pdo->exec('DROP TRIGGER `'.$name.'`');
        $before = $this->catalog($pdo, $driver);
        try {
            try {
                $migration->up();
                $this->fail('Exact empty owned prefix does not authorize repairing a withdrawn dependency.');
            } catch (LogicException) {
            }
            $this->assertSame($before, $this->catalog($pdo, $driver));
            $this->assertSame(3, $this->ownedCount($pdo, $driver));
        } finally {
            $pdo->exec($sql);
        }
        $migration->up();
        $this->assertSame(7, $this->ownedCount($pdo, $driver));
    }

    private function parentDdl(PDO $pdo, string $driver, string $table): array
    {
        if ($driver === 'sqlite') {
            $statement = $pdo->prepare('SELECT sql FROM main.sqlite_master WHERE tbl_name=? AND sql IS NOT NULL ORDER BY CASE type WHEN \'table\' THEN 0 WHEN \'index\' THEN 1 ELSE 2 END,name');
            $statement->execute([$table]);
            $ddl = $statement->fetchAll(PDO::FETCH_COLUMN);

            return ['table' => array_shift($ddl), 'remaining' => $ddl];
        }
        $statement = $pdo->prepare('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE=? ORDER BY TRIGGER_NAME');
        $statement->execute([$table]);
        $remaining = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $name) {
            $remaining[] = $pdo->query('SHOW CREATE TRIGGER `'.$name.'`')->fetch(PDO::FETCH_ASSOC)['SQL Original Statement'];
        }

        return ['table' => $pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_ASSOC)['Create Table'], 'remaining' => $remaining];
    }

    private function assertRefusedWithoutDdl(object $migration, PDO $pdo, string $driver): void
    {
        $before = $this->catalog($pdo, $driver);
        try {
            $migration->up();
            $this->fail('Actual incompatible dependency must refuse before owned DDL.');
        } catch (LogicException) {
        }
        $this->assertSame($before, $this->catalog($pdo, $driver));
        $this->assertSame(0, $this->ownedCount($pdo, $driver));
    }

    private function catalog(PDO $pdo, string $driver): array
    {
        if ($driver === 'sqlite') {
            return $pdo->query('SELECT type,name,tbl_name,sql FROM main.sqlite_master UNION ALL SELECT type,name,tbl_name,sql FROM sqlite_temp_master ORDER BY type,name')->fetchAll(PDO::FETCH_ASSOC);
        }

        return [
            'tables' => $pdo->query('SELECT TABLE_NAME,TABLE_TYPE,ENGINE,TABLE_COLLATION,CREATE_OPTIONS FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_ASSOC),
            'columns' => $pdo->query('SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION')->fetchAll(PDO::FETCH_ASSOC),
            'keys' => $pdo->query('SELECT TABLE_NAME,INDEX_NAME,COLUMN_NAME,NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX')->fetchAll(PDO::FETCH_ASSOC),
            'guards' => $pdo->query('SELECT TRIGGER_NAME,EVENT_OBJECT_TABLE,EVENT_MANIPULATION,ACTION_TIMING,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME')->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    private function foreignKeys(PDO $pdo, string $driver, bool $enabled): void
    {
        $pdo->exec(($driver === 'sqlite' ? 'PRAGMA foreign_keys=' : 'SET FOREIGN_KEY_CHECKS=').($enabled ? '1' : '0'));
    }

    private function ownedCount(PDO $pdo, string $driver): int
    {
        $names = $driver === 'sqlite' ? $pdo->query("SELECT name FROM main.sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN)
            : $pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchAll(PDO::FETCH_COLUMN);

        return count(array_intersect($names, array_keys(IdentitySchema::definitions())));
    }
}
