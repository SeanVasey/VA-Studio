<?php

namespace App\Domain\Customers\ProductionIdentity;

use App\Domain\Catalog\Discovery\DiscoveryEpoch;
use App\Domain\Customers\Preferences\Suppression\SuppressionSchema;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureSchema;
use App\Domain\Grants\Member\MemberGrantSchema;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantSchema;
use App\Domain\Memberships\Billing\BillingSchema;
use App\Domain\Memberships\Production\MembershipSchema;
use App\Support\MigrationDefinitions;
use LogicException;
use PDO;
use PDOException;
use ReflectionMethod;

/** Exact empty DDL-prefix recovery; ownership admission performs no application/query callbacks. */
final class IdentityMigrationOwnership
{
    private ?string $database = null;

    /** @var array<string, array<string, list<array<string, mixed>>>> per-inspection batched per-name results */
    private array $batches = [];

    private array $groups = [];

    private string $accountVersion = AccountAdmissionGuards::V2;

    public function inspect(PDO $pdo, string $driver): array
    {
        return $this->inspectState($pdo, $driver, false);
    }

    /** Only the additive account migration may inspect the exact ordered overlap. */
    public function inspectForAccountUpgrade(PDO $pdo, string $driver): array
    {
        return $this->inspectState($pdo, $driver, true);
    }

    private function inspectState(PDO $pdo, string $driver, bool $accountUpgrade): array
    {
        $lease = CatalogLease::open($pdo);
        try {
            return $this->inspectCurrent($pdo, $driver, $accountUpgrade);
        } finally {
            $lease->close();
        }
    }

    private function inspectCurrent(PDO $pdo, string $driver, bool $accountUpgrade): array
    {
        $this->database = null;
        $this->batches = [];
        $this->groups = [];
        if (! in_array($driver, ['sqlite', 'mysql'], true) || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== $driver) {
            $this->reject();
        }
        $this->dependencies($pdo, $driver, $accountUpgrade);
        $definitions = IdentitySchema::definitions();
        $guards = IdentitySchema::guards($driver);
        $tables = array_keys($definitions);
        $parents = ['users', 'customer_accounts', 'quote_owners'];
        $reserved = [];
        foreach ($definitions as $definition) {
            $reserved = [...$reserved, ...array_keys($definition['unique']), ...array_keys($definition['foreign'])];
        }
        $present = [];
        if ($driver === 'sqlite') {
            $objects = $pdo->query('SELECT type,name,tbl_name,sql FROM sqlite_master')->fetchAll(PDO::FETCH_ASSOC);
            $temporary = $pdo->query('SELECT type,name,tbl_name,sql FROM sqlite_temp_master')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($temporary as $object) {
                if (in_array(strtolower($object['name']), [...$tables, ...array_keys($guards), ...$parents, ...$reserved], true)
                    || in_array(strtolower($object['tbl_name']), $tables, true)) {
                    $this->reject();
                }
            }
            foreach ($objects as $object) {
                if (in_array(strtolower($object['name']), $reserved, true)) {
                    $this->reject();
                }
            }
            foreach ($parents as $parent) {
                $matches = array_values(array_filter($objects, fn ($object) => strtolower($object['name']) === $parent));
                if (count($matches) !== 1 || $matches[0]['name'] !== $parent || $matches[0]['type'] !== 'table') {
                    $this->reject();
                }
            }
            foreach ($tables as $table) {
                $matches = array_values(array_filter($objects, fn ($object) => strtolower($object['name']) === $table));
                if (count($matches) > 1 || ($matches !== [] && ($matches[0]['name'] !== $table || $matches[0]['type'] !== 'table'
                    || $matches[0]['sql'] !== IdentitySchema::tableSql($table, $driver)))) {
                    $this->reject();
                }
                $present[$table] = $matches !== [];
                if ($present[$table]) {
                    $indexes = $pdo->query('PRAGMA main.index_list(`'.$table.'`)')->fetchAll(PDO::FETCH_ASSOC);
                    $actual = [];
                    foreach ($indexes as $index) {
                        if ((int) $index['unique'] !== 1 || $index['origin'] !== 'u' || (int) $index['partial'] !== 0) {
                            $this->reject();
                        }
                        $parts = $pdo->query('PRAGMA main.index_info(`'.$index['name'].'`)')->fetchAll(PDO::FETCH_ASSOC);
                        $actual[] = array_column($parts, 'name');
                    }
                    $expected = array_values($definitions[$table]['unique']);
                    sort($actual);
                    sort($expected);
                    if ($actual !== $expected) {
                        $this->reject();
                    }
                }
                foreach ($objects as $object) {
                    if (strtolower($object['tbl_name']) === $table && $object['name'] !== $table
                        && ! isset($guards[$object['name']]) && ! ($object['type'] === 'index' && $object['sql'] === null
                            && str_starts_with($object['name'], 'sqlite_autoindex_'.$table.'_'))) {
                        $this->reject();
                    }
                }
            }
            foreach ($guards as $name => $guard) {
                $matches = array_values(array_filter($objects, fn ($object) => strtolower($object['name']) === $name));
                if (count($matches) > 1 || ($matches !== [] && (! $present[$guard['table']] || $matches[0]['name'] !== $name
                    || $matches[0]['type'] !== 'trigger' || $matches[0]['tbl_name'] !== $guard['table'] || $matches[0]['sql'] !== $guard['sql']))) {
                    $this->reject();
                }
                $present[$name] = $matches !== [];
            }
            foreach (['main' => $objects, 'temp' => $temporary] as $schema => $catalog) {
                foreach ($catalog as $object) {
                    if ($schema === 'main' && (in_array($object['name'], $tables, true) || isset($guards[$object['name']]))) {
                        continue;
                    }
                    if ($object['type'] === 'table') {
                        $name = str_replace('"', '""', $object['name']);
                        foreach ($pdo->query('PRAGMA '.$schema.'.foreign_key_list("'.$name.'")')->fetchAll(PDO::FETCH_ASSOC) as $key) {
                            if (in_array(strtolower($key['table']), $tables, true)) {
                                $this->reject();
                            }
                        }
                    } elseif (in_array($object['type'], ['view', 'trigger'], true) && $this->references($object['sql'], $tables)) {
                        $this->reject();
                    }
                }
            }
        } else {
            $namedTables = $this->groupLookups($pdo, CatalogGroup::I0_IDENTITY_NAMESPACE, CatalogRead::QEDBF704DA857,
                [...$parents, ...$tables, ...array_keys($guards), ...$reserved]);
            $namedTriggers = $this->groupLookups($pdo, CatalogGroup::I0_IDENTITY_NAMESPACE, CatalogRead::QDE6EB7150A9B,
                [...$reserved, ...array_keys($guards), ...$tables]);
            foreach ([...$parents, ...$tables] as $table) {
                $rows = $namedTables[$table];
                if (count($rows) > 1 || ($rows !== [] && ($rows[0]['TABLE_NAME'] !== $table || $rows[0]['TABLE_TYPE'] !== 'BASE TABLE'
                    || $rows[0]['ENGINE'] !== 'InnoDB' || $rows[0]['CREATE_OPTIONS'] !== '' || $rows[0]['TABLE_COMMENT'] !== ''))) {
                    $this->reject();
                }
                if (in_array($table, $parents, true) && $rows === []) {
                    $this->reject();
                }
                try {
                    $shown = $pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_ASSOC);
                    if ($rows === [] || str_starts_with($shown['Create Table'] ?? '', 'CREATE TEMPORARY TABLE ')) {
                        $this->reject();
                    }
                } catch (PDOException $exception) {
                    if ((int) ($exception->errorInfo[1] ?? 0) !== 1146 || $rows !== []) {
                        throw $exception;
                    }
                }
                if (in_array($table, $tables, true)) {
                    $present[$table] = $rows !== [];
                    if ($rows !== []) {
                        $this->mysqlTable($pdo, $table, $definitions[$table], $rows[0]);
                    }
                }
            }
            foreach ([...array_keys($guards), ...$reserved] as $reservedName) {
                if ($namedTables[$reservedName] !== []) {
                    $this->reject();
                }
                try {
                    $pdo->query('SHOW CREATE TABLE `'.$reservedName.'`');
                    $this->reject();
                } catch (PDOException $exception) {
                    if ((int) ($exception->errorInfo[1] ?? 0) !== 1146) {
                        throw $exception;
                    }
                }
                if (in_array($reservedName, $reserved, true) && $namedTriggers[$reservedName] !== []) {
                    $this->reject();
                }
            }
            foreach ($guards as $name => $guard) {
                $rows = $namedTriggers[$name];
                if (count($rows) > 1 || ($rows !== [] && (! $present[$guard['table']] || $rows[0]['TRIGGER_NAME'] !== $name
                    || $rows[0]['EVENT_OBJECT_TABLE'] !== $guard['table'] || $rows[0]['EVENT_MANIPULATION'] !== $guard['operation']
                    || $rows[0]['ACTION_TIMING'] !== 'BEFORE' || $rows[0]['ACTION_STATEMENT'] !== $guard['body']))) {
                    $this->reject();
                }
                if ($namedTables[$name] !== []) {
                    $this->reject();
                }
                $present[$name] = $rows !== [];
            }
            foreach ($tables as $table) {
                if ($namedTriggers[$table] !== []) {
                    $this->reject();
                }
            }
            $namedIndexes = $this->groupLookups($pdo, CatalogGroup::I2_RESERVED_NAMES, CatalogRead::QECFE2D2F4FCD, $reserved);
            $namedConstraints = $this->groupLookups($pdo, CatalogGroup::I2_RESERVED_NAMES, CatalogRead::Q6546FD85F02A, $reserved);
            foreach ($definitions as $table => $definition) {
                foreach ([...array_keys($definition['unique']), ...array_keys($definition['foreign'])] as $name) {
                    foreach ($namedIndexes[$name] as $index) {
                        if ($index['TABLE_NAME'] !== $table || $index['INDEX_NAME'] !== $name || ! $present[$table]) {
                            $this->reject();
                        }
                    }
                    foreach ($namedConstraints[$name] as $constraint) {
                        if ($constraint['TABLE_NAME'] !== $table || $constraint['CONSTRAINT_NAME'] !== $name || ! $present[$table]) {
                            $this->reject();
                        }
                    }
                }
            }
            $this->dependents($pdo, $tables, $guards, true);
        }
        $missing = false;
        foreach ($present as $installed) {
            if ($installed && $missing) {
                $this->reject();
            }
            $missing = $missing || ! $installed;
        }
        $populated = false;
        foreach ($tables as $table) {
            if ($present[$table] && $pdo->query('SELECT id FROM `'.$table.'` LIMIT 1')->fetchColumn() !== false) {
                $populated = true;
            }
        }
        if ($missing && $populated) {
            $this->reject();
        }

        return [$present, $populated];
    }

    /**
     * The MySQL cross-schema dependents scan of inspect(), alone. A trigger, view or routine that reaches these
     * tables can be created without a metadata lock on them, so SchemaProofFrame repeats this on every reuse.
     */
    public function assertNoDependents(PDO $pdo): void
    {
        $lease = CatalogLease::open($pdo);
        try {
            $this->assertNoDependentsCurrent($pdo);
        } finally {
            $lease->close();
        }
    }

    private function assertNoDependentsCurrent(PDO $pdo): void
    {
        $this->database = null;
        $this->batches = [];
        $this->groups = [];
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            $this->reject();
        }
        $this->dependents($pdo, array_keys(IdentitySchema::definitions()), IdentitySchema::guards('mysql'));
    }

    /**
     * Migration-only additional parent floor. Known child FK tuples are retained, never adopted/repaired;
     * this does not expand ordinary runtime identity-table dependent admission.
     */
    public function assertAccountUpgradeDependents(PDO $pdo, string $driver): void
    {
        $lease = CatalogLease::open($pdo);
        try {
            $this->assertAccountUpgradeDependentsCurrent($pdo, $driver);
        } finally {
            $lease->close();
        }
    }

    private function assertAccountUpgradeDependentsCurrent(PDO $pdo, string $driver): void
    {
        $allowed = $this->accountChildKeys();
        $canonical = $this->accountDependentTriggers($pdo, $driver);
        if ($driver === 'sqlite') {
            foreach (['main' => 'sqlite_master', 'temp' => 'sqlite_temp_master'] as $schema => $catalog) {
                foreach ($pdo->query('SELECT type,name,tbl_name,sql FROM '.$catalog)->fetchAll(PDO::FETCH_ASSOC) as $object) {
                    if ($object['type'] === 'trigger' && ($this->references($object['sql'], ['customer_accounts']) || ($schema === 'main' && isset($canonical[$object['name']])))) {
                        $expected = $canonical[$object['name']] ?? null;
                        if ($schema !== 'main' || $expected === null || $object['tbl_name'] !== $expected['table'] || $object['sql'] !== $expected['sql']) {
                            $this->reject();
                        }
                    }
                    if ($object['type'] === 'view' && $this->references($object['sql'], ['customer_accounts'])) {
                        $this->reject();
                    }
                    if ($object['type'] !== 'table') {
                        continue;
                    }
                    foreach ($pdo->query('PRAGMA '.$schema.'.foreign_key_list("'.str_replace('"', '""', $object['name']).'")')->fetchAll(PDO::FETCH_ASSOC) as $key) {
                        if (strtolower($key['table']) === 'customer_accounts') {
                            $expected = $allowed[$object['name']][$key['from']] ?? null;
                            if ($schema !== 'main' || $expected === null || $key['table'] !== 'customer_accounts' || $key['to'] !== 'id' || (int) $key['seq'] !== 0
                                || $key['on_update'] !== $expected[1] || $key['on_delete'] !== 'RESTRICT') {
                                $this->reject();
                            }
                        }
                    }
                }
            }

            return;
        }
        if ($driver !== 'mysql' || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== $driver) {
            $this->reject();
        }
        $this->database = null;
        foreach (['VIEWS' => ['TABLE_SCHEMA', 'VIEW_DEFINITION'], 'ROUTINES' => ['ROUTINE_SCHEMA', 'ROUTINE_DEFINITION']] as $catalog => [$schema, $column]) {
            foreach (CatalogLease::read($pdo, CatalogRead::dependencies($catalog)) as $object) {
                if ($this->dependsOn($pdo, $object[$schema], $object[$column], ['customer_accounts'])) {
                    $this->reject();
                }
            }
        }
        foreach (CatalogLease::read($pdo, CatalogRead::QB6B2A59338B7) as $trigger) {
            $reaches = $this->dependsOn($pdo, $trigger['TRIGGER_SCHEMA'], $trigger['ACTION_STATEMENT'], ['customer_accounts']);
            if (! $reaches && ! ($trigger['TRIGGER_SCHEMA'] === $this->database($pdo) && isset($canonical[$trigger['TRIGGER_NAME']]))) {
                continue;
            }
            $expected = $canonical[$trigger['TRIGGER_NAME']] ?? null;
            if ($trigger['TRIGGER_SCHEMA'] !== $this->database($pdo) || $expected === null || $trigger['EVENT_OBJECT_TABLE'] !== $expected['table']
                || $trigger['EVENT_MANIPULATION'] !== $expected['event'] || $trigger['ACTION_TIMING'] !== 'BEFORE'
                || $trigger['ACTION_STATEMENT'] !== $expected['body'] || (int) $trigger['ACTION_ORDER'] !== $expected['order']) {
                $this->reject();
            }
        }
        $sql = CatalogRead::Q4E4F09288618;
        foreach (CatalogLease::read($pdo, $sql) as $key) {
            $expected = $allowed[$key['TABLE_NAME']][$key['COLUMN_NAME']] ?? null;
            if ($key['TABLE_SCHEMA'] !== $this->database($pdo) || $expected === null || $key['CONSTRAINT_NAME'] !== $expected[0]
                || $key['REFERENCED_TABLE_NAME'] !== 'customer_accounts' || $key['REFERENCED_COLUMN_NAME'] !== 'id' || (int) $key['ORDINAL_POSITION'] !== 1
                || $key['UPDATE_RULE'] !== $expected[1] || $key['DELETE_RULE'] !== 'RESTRICT') {
                $this->reject();
            }
        }
    }

    /** Source-backed definitions only: never learn a legitimate dependency from installed names/bodies. */
    private function accountDependentTriggers(PDO $pdo, string $driver): array
    {
        $definitions = [];
        foreach (['2026_10_06_000045_customer_identity_challenges.php' => 'guards',
            '2026_10_06_000048_customer_purchase_claims.php' => 'guards',
            '2026_10_06_231000_test_transactional_notifications.php' => 'guards',
            '2026_10_07_250000_customer_consent.php' => 'triggers'] as $file => $method) {
            $migration = MigrationDefinitions::load($file);
            $definitions[] = (new ReflectionMethod($migration, $method))->invoke($migration, ...($method === 'triggers' ? [$driver] : []));
        }
        foreach ([SuppressionSchema::class,
            ProductionFeatureSchema::class] as $class) {
            $builder = new $class;
            $definitions[] = (new ReflectionMethod($builder, 'triggers'))->invoke($builder, $driver);
        }
        foreach ([MembershipSchema::class,
            BillingSchema::class,
            MemberGrantSchema::class,
            ProductionFreeGrantSchema::class] as $class) {
            $builder = new $class;
            foreach ($class::TABLES as $table) {
                $definitions[] = (new ReflectionMethod($builder, 'guards'))->invoke($builder, $table, $driver, $table, $builder->table($table));
            }
        }
        $credit = MigrationDefinitions::load('2026_10_06_230000_membership_credit_foundation.php');
        $creditGuards = [];
        foreach ((new ReflectionMethod($credit, 'conditions'))->invoke($credit) as $table => $when) {
            $name = $table.'_insert';
            $when = "COALESCE(($when),1)";
            // Exact emission of that historical migration's guard(), without invoking its DDL writer.
            $creditGuards[$name] = $driver === 'sqlite'
                ? "CREATE TRIGGER $name BEFORE INSERT ON $table WHEN $when BEGIN SELECT RAISE(ABORT, 'Retain membership credit evidence'); END"
                : "CREATE TRIGGER $name BEFORE INSERT ON $table FOR EACH ROW BEGIN IF $when THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Retain membership credit evidence'; END IF; END";
        }
        $account = AccountAdmissionGuards::migrationState($pdo, $driver);
        $definitions = [...$definitions, $creditGuards, IdentitySchema::guards($driver), $account['guards']];
        $result = [];
        foreach ($definitions as $guards) {
            foreach ($guards as $name => $guard) {
                $sql = is_array($guard) ? $guard['sql'] : $guard;
                if (! $this->references($sql, ['customer_accounts'])) {
                    continue;
                }
                if (preg_match('/\ACREATE TRIGGER [`"]?([a-z0-9_]+)[`"]? BEFORE (INSERT|UPDATE|DELETE) ON (?:main\.)?[`"]?([a-z0-9_]+)[`"]?/i', $sql, $header) !== 1
                    || $header[1] !== $name) {
                    $this->reject();
                }
                $body = $driver === 'mysql' ? explode(' FOR EACH ROW ', $sql, 2)[1] : '';
                $expected = ['table' => $header[3], 'event' => strtoupper($header[2]), 'sql' => $sql, 'body' => $body,
                    'order' => $account['version'] === AccountAdmissionGuards::OVERLAP && $name === AccountAdmissionGuards::NEW_INSERT ? 2 : 1];
                if (isset($result[$name]) && $result[$name] !== $expected) {
                    $this->reject();
                }
                $result[$name] = $expected;
            }
        }

        return $result;
    }

    /** Exact account-facing FK tuples from unchanged historical schema definitions. Absence grants no creation authority. */
    private function accountChildKeys(): array
    {
        $keys = [];
        foreach (['customer_identity_challenges' => ['account_id', 'result_account_id'], 'customer_purchase_claims' => ['account_id'],
            'membership_credit_buckets' => ['customer_account_id'], 'transactional_notices' => ['account_id'],
            'service_projects' => ['customer_account_id'], 'customer_data_requests' => ['customer_account_id']] as $table => $columns) {
            foreach ($columns as $column) {
                $keys[$table][$column] = [$table.'_'.$column.'_foreign', 'NO ACTION'];
            }
        }
        foreach (['free_origins', 'free_authorizations', 'paid_order_origins', 'paid_authorizations', 'paid_tax_order_origins', 'paid_tax_authorizations'] as $table) {
            $keys[$table]['account_id'] = [$table.'_account', 'NO ACTION'];
        }
        foreach (['customer_consent_events', 'customer_consent_states', 'customer_suppression_targets'] as $table) {
            $keys[$table]['customer_account_id'] = [$table.'_customer_account_id_fk', 'RESTRICT'];
        }
        $keys['customer_saved_tracks']['customer_account_id'] = ['customer_saved_tracks_account_fk', 'RESTRICT'];
        $keys['production_identity_origins']['account_id'] = ['pio_account_fk', 'NO ACTION'];
        $keys['production_identity_verifications']['account_id'] = ['piv_account_fk', 'NO ACTION'];
        $keys['production_account_feature_bindings']['account_id'] = ['production_account_feature_bindings_account_id_fk', 'RESTRICT'];
        foreach (['production_membership_paid_periods' => 0, 'production_membership_billing_subscriptions' => 0,
            'production_member_origins' => 3, 'production_free_origins' => 2, 'production_free_authorizations' => 1,
            'production_free_redemptions' => 2] as $table => $index) {
            $keys[$table]['account_id'] = [$table.'_f'.$index, 'RESTRICT'];
        }

        return $keys;
    }

    /** No foreign trigger, key, view or routine depends on the identity tables (MySQL). */
    private function dependents(PDO $pdo, array $tables, array $guards, bool $full = false): void
    {
        $group = $full ? CatalogLease::group($pdo, CatalogGroup::D0_FULL_DEPENDENTS, $this->accountVersion) : null;
        foreach (($group === null ? CatalogLease::read($pdo, CatalogRead::Q7BC108FBFFA5) : $group[CatalogRead::Q7BC108FBFFA5->value]['']) as $object) {
            if (isset($guards[$object['TRIGGER_NAME']]) && $object['TRIGGER_SCHEMA'] === $this->database($pdo)) {
                continue;
            }
            if (($object['TRIGGER_SCHEMA'] === $this->database($pdo) && in_array($object['EVENT_OBJECT_TABLE'], $tables, true))
                || $this->dependsOn($pdo, $object['TRIGGER_SCHEMA'], $object['ACTION_STATEMENT'], $tables)) {
                $this->reject();
            }
        }
        foreach (($group === null ? CatalogLease::read($pdo, CatalogRead::Q037BE6D6C820) : $group[CatalogRead::Q037BE6D6C820->value]['']) as $key) {
            if (in_array(strtolower((string) $key['REFERENCED_TABLE_NAME']), $tables, true)
                && ($key['TABLE_SCHEMA'] !== $this->database($pdo) || ! in_array($key['TABLE_NAME'], $tables, true))) {
                $this->reject();
            }
        }
        foreach (['VIEWS' => ['TABLE_SCHEMA', 'VIEW_DEFINITION'], 'ROUTINES' => ['ROUTINE_SCHEMA', 'ROUTINE_DEFINITION']] as $catalog => [$schema, $column]) {
            foreach (($group === null ? CatalogLease::read($pdo, CatalogRead::dependencies($catalog)) : $group[CatalogRead::dependencies($catalog)->value]['']) as $object) {
                if ($this->dependsOn($pdo, $object[$schema], $object[$column], $tables)) {
                    $this->reject();
                }
            }
        }
    }

    /** Retained foundation metadata is a dependency, never something this migration repairs or adopts. */
    private function dependencies(PDO $pdo, string $driver, bool $accountUpgrade): void
    {
        if ((int) $pdo->query($driver === 'sqlite' ? 'PRAGMA foreign_keys' : 'SELECT @@SESSION.foreign_key_checks')->fetchColumn() !== 1) {
            $this->reject();
        }
        $guards = $this->parentGuards($pdo, $driver, $accountUpgrade);
        $namespace = ['users' => ['table', 'users'], 'customer_accounts' => ['table', 'customer_accounts'], 'quote_owners' => ['table', 'quote_owners']];
        foreach ($guards as $name => $guard) {
            $namespace[$name] = ['trigger', $guard['table']];
        }
        if ($driver === 'mysql') {
            $names = array_keys($namespace);
            $exact = ['tables' => $this->groupLookups($pdo, CatalogGroup::P0_PARENT_NAMESPACE, CatalogRead::QA8762690E619, $names),
                'triggers' => $this->groupLookups($pdo, CatalogGroup::P0_PARENT_NAMESPACE, CatalogRead::Q38A05D78DB1E, $names)];
            foreach ([['ROUTINES', 'ROUTINE_SCHEMA', 'ROUTINE_NAME'], ['EVENTS', 'EVENT_SCHEMA', 'EVENT_NAME'],
                ['STATISTICS', 'TABLE_SCHEMA', 'INDEX_NAME'], ['TABLE_CONSTRAINTS', 'CONSTRAINT_SCHEMA', 'CONSTRAINT_NAME']] as [$dictionary, $schema, $column]) {
                $exact[$dictionary] = $this->groupLookups($pdo, CatalogGroup::P0_PARENT_NAMESPACE, CatalogRead::namespace($dictionary), $names);
            }
        }
        foreach ($namespace as $name => [$kind, $owner]) {
            if ($driver === 'sqlite') {
                if ($this->query($pdo, 'SELECT name FROM sqlite_temp_master WHERE name COLLATE NOCASE=? OR tbl_name COLLATE NOCASE=?', [$name, $name]) !== []
                    || $this->query($pdo, 'SELECT name,type,tbl_name FROM main.sqlite_master WHERE name COLLATE NOCASE=?', [$name]) !== [['name' => $name, 'type' => $kind, 'tbl_name' => $owner]]) {
                    $this->reject();
                }
            } else {
                $objects = [...$exact['tables'][$name], ...$exact['triggers'][$name]];
                if ($objects !== [['name' => $name, 'type' => $kind, 'tbl_name' => $owner]]) {
                    $this->reject();
                }
                foreach (['ROUTINES', 'EVENTS', 'STATISTICS', 'TABLE_CONSTRAINTS'] as $dictionary) {
                    if ($exact[$dictionary][$name] !== []) {
                        $this->reject();
                    }
                }
                try {
                    $shown = $pdo->query('SHOW CREATE TABLE `'.$name.'`')->fetch(PDO::FETCH_ASSOC);
                    if ($kind !== 'table' || str_contains(strtoupper((string) ($shown['Create Table'] ?? '')), 'CREATE TEMPORARY TABLE')) {
                        $this->reject();
                    }
                } catch (PDOException $error) {
                    if (($error->errorInfo[1] ?? null) !== 1146 || $kind === 'table') {
                        throw $error;
                    }
                }
            }
        }
        if ($driver === 'sqlite') {
            $sql = [
                'users' => 'CREATE TABLE "users" ("id" integer primary key autoincrement not null, "name" varchar not null, "email" varchar not null, "email_verified_at" datetime, "password" varchar not null, "remember_token" varchar, "created_at" datetime, "updated_at" datetime, "is_admin" tinyint(1) not null default \'0\', "app_authentication_secret" text, "app_authentication_recovery_codes" text)',
                'customer_accounts' => 'CREATE TABLE "customer_accounts" ("id" integer primary key autoincrement not null, "public_id" varchar not null, "user_id" integer not null, "owner_key" varchar not null, "active" tinyint(1) not null, "access_version" integer not null, "created_at" datetime, "updated_at" datetime, foreign key("user_id") references "users"("id") on delete restrict, foreign key("owner_key") references "quote_owners"("owner_key") on delete restrict)',
                'quote_owners' => 'CREATE TABLE "quote_owners" ("owner_key" varchar not null, primary key ("owner_key"))',
            ];
            foreach ($sql as $table => $definition) {
                if ($this->query($pdo, "SELECT sql FROM main.sqlite_master WHERE type='table' AND name=?", [$table]) !== [['sql' => $definition]]) {
                    $this->reject();
                }
                $expected = $this->parentIndexes($table);
                unset($expected['PRIMARY']);
                if ($table === 'quote_owners') {
                    $expected = ['sqlite_autoindex_quote_owners_1' => ['owner_key']];
                }
                $actual = [];
                foreach ($pdo->query('PRAGMA main.index_list("'.$table.'")')->fetchAll(PDO::FETCH_ASSOC) as $index) {
                    if (! isset($expected[$index['name']]) || (int) $index['unique'] !== 1 || (int) $index['partial'] !== 0) {
                        $this->reject();
                    }
                    $parts = $pdo->query('PRAGMA main.index_xinfo("'.$index['name'].'")')->fetchAll(PDO::FETCH_ASSOC);
                    $parts = array_values(array_filter($parts, fn ($part) => (int) $part['key'] === 1));
                    foreach ($parts as $part) {
                        if ((int) $part['desc'] !== 0 || $part['coll'] !== 'BINARY' || (int) $part['cid'] < 0) {
                            $this->reject();
                        }
                    }
                    $actual[$index['name']] = array_column($parts, 'name');
                }
                ksort($actual);
                ksort($expected);
                if ($actual !== $expected) {
                    $this->reject();
                }
            }
        } else {
            $parents = array_keys($this->parentColumns());
            foreach ($this->parentColumns() as $table => $columns) {
                if ($this->named($pdo, CatalogRead::Q9D29C0ADA7EE, $table, $parents)
                    !== [['ENGINE' => 'InnoDB', 'TABLE_TYPE' => 'BASE TABLE', 'TABLE_COMMENT' => '', 'TABLE_COLLATION' => 'utf8mb4_unicode_ci', 'CREATE_OPTIONS' => '']]) {
                    $this->reject();
                }
                $expected = [];
                foreach ($columns as $name => [$type, $nullable, $default, $extra]) {
                    $text = preg_match('/\A(?:varchar|char|text)/', $type) === 1;
                    $expected[] = ['COLUMN_NAME' => $name, 'COLUMN_TYPE' => $type, 'IS_NULLABLE' => $nullable ? 'YES' : 'NO', 'COLUMN_DEFAULT' => $default,
                        'EXTRA' => $extra, 'CHARACTER_SET_NAME' => $text ? 'utf8mb4' : null, 'COLLATION_NAME' => $text ? 'utf8mb4_unicode_ci' : null,
                        'COLUMN_COMMENT' => '', 'GENERATION_EXPRESSION' => ''];
                }
                if ($this->named($pdo, CatalogRead::QD9E74E9B8F9D, $table, $parents) !== $expected) {
                    $this->reject();
                }
                $expected = [];
                $indexes = $this->parentIndexes($table);
                ksort($indexes);
                foreach ($indexes as $name => $columns) {
                    foreach ($columns as $offset => $column) {
                        $expected[] = ['INDEX_NAME' => $name, 'COLUMN_NAME' => $column, 'NON_UNIQUE' => 0, 'SEQ_IN_INDEX' => $offset + 1,
                            'INDEX_TYPE' => 'BTREE', 'SUB_PART' => null, 'COLLATION' => 'A', 'EXPRESSION' => null, 'IS_VISIBLE' => 'YES', 'INDEX_COMMENT' => ''];
                    }
                }
                $actual = $this->named($pdo, CatalogRead::Q54EFEC87F80D, $table, $parents);
                usort($actual, fn ($left, $right) => strcmp($left['INDEX_NAME'], $right['INDEX_NAME']) ?: $left['SEQ_IN_INDEX'] <=> $right['SEQ_IN_INDEX']);
                if ($actual !== $expected) {
                    $this->reject();
                }
                $foreign = [];
                if ($table === 'customer_accounts') {
                    foreach (['owner_key' => ['quote_owners', 'owner_key'], 'user_id' => ['users', 'id']] as $column => [$target, $key]) {
                        $foreign[] = ['CONSTRAINT_NAME' => $table.'_'.$column.'_foreign', 'COLUMN_NAME' => $column, 'REFERENCED_TABLE_SCHEMA' => $this->database($pdo),
                            'REFERENCED_TABLE_NAME' => $target, 'REFERENCED_COLUMN_NAME' => $key, 'UPDATE_RULE' => 'NO ACTION', 'DELETE_RULE' => 'RESTRICT'];
                    }
                }
                if ($this->named($pdo, CatalogRead::QD29EA5375536, $table, $parents) !== $foreign
                    || count($this->named($pdo, CatalogRead::Q357FD211BAA0, $table, $parents)) !== count($indexes) + count($foreign)) {
                    $this->reject();
                }
            }
        }
        $bodies = $driver === 'sqlite' ? [] : $this->groupLookups($pdo, CatalogGroup::P2_PARENT_GUARDS, CatalogRead::Q95556A0350AB, array_keys($guards));
        foreach ($guards as $name => $guard) {
            $expected = $driver === 'sqlite' ? [['sql' => $guard['sql']]] : [['EVENT_OBJECT_TABLE' => $guard['table'], 'EVENT_MANIPULATION' => $guard['event'], 'ACTION_TIMING' => $guard['timing'] ?? 'BEFORE', 'ACTION_STATEMENT' => $guard['body']]];
            $actual = $driver === 'sqlite' ? $this->query($pdo, "SELECT sql FROM main.sqlite_master WHERE type='trigger' AND name=?", [$name])
                : $bodies[$name];
            if ($actual !== $expected) {
                $this->reject();
            }
        }
        foreach (['users', 'customer_accounts', 'quote_owners'] as $table) {
            $actual = $driver === 'sqlite' ? $this->query($pdo, "SELECT name FROM main.sqlite_master WHERE type='trigger' AND tbl_name=? ORDER BY name", [$table])
                : $this->named($pdo, CatalogRead::QB2684ECFE856, $table, ['users', 'customer_accounts', 'quote_owners']);
            $names = array_keys(array_filter($guards, fn ($guard) => $guard['table'] === $table));
            sort($names);
            if ($actual !== array_map(fn ($name) => ['name' => $name], $names)) {
                $this->reject();
            }
        }
    }

    private function parentColumns(): array
    {
        $id = ['bigint unsigned', false, null, 'auto_increment'];
        $timestamp = ['timestamp', true, null, ''];
        $text = ['text', true, null, ''];

        return ['users' => ['id' => $id, 'name' => ['varchar(255)', false, null, ''], 'email' => ['varchar(255)', false, null, ''],
            'email_verified_at' => $timestamp, 'password' => ['varchar(255)', false, null, ''], 'remember_token' => ['varchar(100)', true, null, ''],
            'created_at' => $timestamp, 'updated_at' => $timestamp, 'is_admin' => ['tinyint(1)', false, '0', ''],
            'app_authentication_secret' => $text, 'app_authentication_recovery_codes' => $text],
            'customer_accounts' => ['id' => $id, 'public_id' => ['char(36)', false, null, ''], 'user_id' => ['bigint unsigned', false, null, ''],
                'owner_key' => ['char(64)', false, null, ''], 'active' => ['tinyint(1)', false, null, ''], 'access_version' => ['int unsigned', false, null, ''],
                'created_at' => $timestamp, 'updated_at' => $timestamp], 'quote_owners' => ['owner_key' => ['char(64)', false, null, '']]];
    }

    private function parentIndexes(string $table): array
    {
        return match ($table) {
            'users' => ['PRIMARY' => ['id'], 'users_email_unique' => ['email']],
            'customer_accounts' => ['PRIMARY' => ['id'], 'customer_accounts_public_id_unique' => ['public_id'],
                'customer_accounts_user_id_unique' => ['user_id'], 'customer_accounts_owner_key_unique' => ['owner_key']],
            'quote_owners' => ['PRIMARY' => ['owner_key']],
        };
    }

    private function parentGuards(PDO $pdo, string $driver, bool $accountUpgrade): array
    {
        $state = $accountUpgrade ? AccountAdmissionGuards::identityParentMigrationState($pdo, $driver) : AccountAdmissionGuards::identityParentStable($pdo, $driver);
        $this->accountVersion = $state['version'];
        $guards = $state['guards'];
        foreach (['update', 'delete'] as $operation) {
            $name = 'quote_owners_immutable_'.$operation;
            $guards[$name] = ['table' => 'quote_owners', 'event' => strtoupper($operation),
                'body' => "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Quote evidence is immutable'",
                'sql' => "CREATE TRIGGER $name BEFORE $operation ON quote_owners BEGIN SELECT RAISE(ABORT, 'Quote evidence is immutable'); END"];
        }
        // The author branch predates PR25. Its reviewed users epoch guards are required in a composed install.
        if (class_exists(DiscoveryEpoch::class)) {
            foreach (DiscoveryEpoch::guards($driver) as $name => $guard) {
                if ($guard['table'] === 'users') {
                    $guards[$name] = $guard;
                }
            }
        }

        return $guards;
    }

    private function mysqlTable(PDO $pdo, string $table, array $definition, array $tableRow): void
    {
        if ($tableRow['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci') {
            $this->reject();
        }
        $expected = ['id' => 'bigint'] + $definition['columns'];
        $tables = array_keys(IdentitySchema::definitions());
        $columns = $this->named($pdo, CatalogRead::Q88ED11D61B97, $table, $tables);
        if (array_column($columns, 'COLUMN_NAME') !== array_keys($expected)) {
            $this->reject();
        }
        foreach ($columns as $row) {
            $name = $row['COLUMN_NAME'];
            $type = preg_replace('/ CHARACTER SET.*$/', '', IdentitySchema::columnSql($expected[$name], 'mysql'));
            $ascii = in_array($expected[$name], ['uuid', 'hash', 'version', 'scope', 'state', 'purpose'], true);
            $charset = $ascii ? 'ascii' : ($expected[$name] === 'text' ? 'utf8mb4' : null);
            $collation = $ascii ? 'ascii_bin' : ($expected[$name] === 'text' ? 'utf8mb4_unicode_ci' : null);
            if ($row['COLUMN_TYPE'] !== $type || $row['IS_NULLABLE'] !== 'NO' || $row['COLUMN_DEFAULT'] !== null
                || $row['COLUMN_COMMENT'] !== '' || $row['GENERATION_EXPRESSION'] !== ''
                || $row['EXTRA'] !== ($name === 'id' ? 'auto_increment' : '')
                || $row['CHARACTER_SET_NAME'] !== $charset || $row['COLLATION_NAME'] !== $collation) {
                $this->reject();
            }
        }
        $indexes = ['PRIMARY' => [['id'], 0]];
        foreach ($definition['unique'] as $name => $fields) {
            $indexes[$name] = [$fields, 0];
        }
        foreach ($definition['foreign'] as $name => [$column]) {
            if (! in_array([$column], $definition['unique'], true)) {
                $indexes[$name] = [[$column], 1];
            }
        }
        $parts = $this->named($pdo, CatalogRead::Q603824C0D074, $table, $tables);
        $actual = [];
        foreach ($parts as $part) {
            if (! isset($indexes[$part['INDEX_NAME']]) || $part['SUB_PART'] !== null || $part['EXPRESSION'] !== null
                || $part['INDEX_TYPE'] !== 'BTREE' || $part['COLLATION'] !== 'A' || $part['IS_VISIBLE'] !== 'YES' || $part['INDEX_COMMENT'] !== '') {
                $this->reject();
            }
            $actual[$part['INDEX_NAME']][0][] = $part['COLUMN_NAME'];
            $actual[$part['INDEX_NAME']][1] = (int) $part['NON_UNIQUE'];
        }
        ksort($indexes);
        ksort($actual);
        if ($actual !== $indexes) {
            $this->reject();
        }
        $keys = $this->named($pdo, CatalogRead::Q26706FA791A1, $table, $tables);
        if (count($keys) !== count($definition['foreign'])) {
            $this->reject();
        }
        foreach ($keys as $key) {
            $expectedKey = $definition['foreign'][$key['CONSTRAINT_NAME']] ?? null;
            if ($expectedKey !== [$key['COLUMN_NAME'], $key['REFERENCED_TABLE_NAME']] || $key['REFERENCED_COLUMN_NAME'] !== 'id'
                || $key['REFERENCED_TABLE_SCHEMA'] !== $this->database($pdo) || (int) $key['ORDINAL_POSITION'] !== 1
                || $key['DELETE_RULE'] !== 'RESTRICT' || $key['UPDATE_RULE'] !== 'NO ACTION') {
                $this->reject();
            }
        }
        if ($this->named($pdo, CatalogRead::QD3A32C45A22D, $table, $tables) !== []) {
            $this->reject();
        }
    }

    /**
     * Unqualified names in a stored trigger, routine or view resolve to that object's
     * own schema, so another schema reaches these tables only by naming this database
     * or through dynamic SQL. Same-named objects in a parallel schema are not dependents.
     */
    private function dependsOn(PDO $pdo, mixed $schema, mixed $sql, array $tables): bool
    {
        $database = $this->database($pdo);

        return $this->references($sql, $tables) && (strtolower((string) $schema) === strtolower($database)
            || $this->qualifies($sql, $database) || $this->references($sql, ['prepare']));
    }

    /**
     * Whether the definition names the selected database as a complete qualifier: the whole
     * identifier (bare, backtick or ANSI-quoted) followed, past optional whitespace and
     * comments, by the dot. A peer schema that merely extends the selected name, such as
     * `<db>-2`, `<db>$x` or `<db>é`, is another schema; MySQL stores its objects qualified
     * (`` `<db>-2`.`t` ``), so a plain word-boundary search would find `<db>` inside that name
     * and refuse the peer's own objects. The name is compared case-insensitively because a
     * `lower_case_table_names` 1 or 2 server resolves `` `DB`.`t` `` to the same schema.
     * A database name containing a backtick or a double quote is refused outright: MySQL stores
     * doubled delimiters inside routine and trigger bodies mangled (`` `a``b` `` is kept as
     * `` `aa`bb` ``), so neither the raw nor the SQL-escaped spelling is a reliable match and
     * a dependency on such a database could be admitted. A regex failure refuses. The same expression is in `CapabilityMigrationOwnership::qualifies()` and migration 243 `qualifiesDatabase()`.
     */
    private function qualifies(string $sql, string $database): bool
    {
        if (str_contains($database, '`') || str_contains($database, '"')) {
            $this->reject();
        }
        $name = preg_quote($database, '/');
        $match = preg_match('/(?:`'.$name.'`|"'.$name.'"|(?<![A-Za-z0-9_$\x{80}-\x{10FFFF}])'.$name.')(?:\s|\/\*.*?\*\/|(?:--\s|#)[^\n]*)*\./isu', $sql);
        if ($match === false) {
            $this->reject();
        }

        return $match === 1;
    }

    private function references(mixed $sql, array $tables): bool
    {
        if (! is_string($sql)) {
            $this->reject();
        }
        foreach ($tables as $table) {
            if (preg_match('/(?<![a-z0-9_])'.preg_quote($table, '/').'(?![a-z0-9_])/i', $sql)) {
                return true;
            }
        }

        return false;
    }

    private function query(PDO $pdo, CatalogRead|string $sql, array $bindings): array
    {
        if ($sql instanceof CatalogRead) {
            return CatalogLease::read($pdo, $sql, $bindings);
        }
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            $this->reject();
        }
        $statement = $pdo->prepare($sql);
        $statement->execute($bindings);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** One name's rows from a per-inspection batch of the same single-name statement over all $names. */
    private function named(PDO $pdo, CatalogRead $sql, string $name, array $names): array
    {
        $group = match ($sql) {
            CatalogRead::Q9D29C0ADA7EE,CatalogRead::QD9E74E9B8F9D,CatalogRead::Q54EFEC87F80D,CatalogRead::QD29EA5375536,CatalogRead::Q357FD211BAA0 => CatalogGroup::P1_PARENT_SHAPE,
            CatalogRead::QB2684ECFE856 => CatalogGroup::P2_PARENT_GUARDS,
            CatalogRead::Q88ED11D61B97,CatalogRead::Q603824C0D074,CatalogRead::Q26706FA791A1,CatalogRead::QD3A32C45A22D => CatalogGroup::I1_IDENTITY_SHAPE,
            default => null,
        };
        $this->batches[$sql->value] ??= $group === null ? $this->lookups($pdo, $sql, $names) : $this->groupLookups($pdo, $group, $sql, $names);

        return $this->batches[$sql->value][$name] ?? throw new LogicException('Unbatched identity dictionary name.');
    }

    /**
     * One statement for many per-name dictionary lookups. The schema's catalog rows are
     * materialized once as a CTE (NO_MERGE, otherwise every branch rescans the dictionary)
     * with only the selected, lookup and ordering columns and only rows matching one of the
     * names; each UNION ALL branch then applies the unchanged single-name predicate, parameter
     * and column collation to those rows.
     *
     * @return array<string, list<array<string, mixed>>> rows per requested name, in the statement's order
     */
    private function lookups(PDO $pdo, CatalogRead $sql, array $names): array
    {
        return CatalogLease::identity($pdo, $sql, $names);
    }

    private function groupLookups(PDO $pdo, CatalogGroup $group, CatalogRead $query, array $names): array
    {
        $this->groups[$group->value] ??= CatalogLease::group($pdo, $group, $this->accountVersion);
        $rows = $this->groups[$group->value][$query->value] ?? throw new LogicException('Closed group family required.');
        if (array_keys($rows) !== array_values(array_unique($names))) {
            throw new LogicException('Closed group name recipe required.');
        }

        return $rows;
    }

    /** Read once per inspection; the per-row comparisons below previously re-queried it. */
    private function database(PDO $pdo): string
    {
        return $this->database ??= $pdo->query('SELECT DATABASE()')->fetchColumn();
    }

    private function reject(): never
    {
        throw new LogicException('Unexpected production identity schema or retained evidence; refused before DDL.');
    }
}
