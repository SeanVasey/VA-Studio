<?php

use App\Domain\Catalog\Discovery\DiscoveryEpoch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $pdo = DB::connection()->getPdo();
        $driver = DB::getDriverName();
        if (! in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new LogicException('Unsupported discovery database.');
        }
        $guards = DiscoveryEpoch::guards($driver);
        [$exists, $installed] = $driver === 'sqlite'
            ? $this->sqlitePreflight($pdo, $guards) : $this->mysqlPreflight($pdo, $guards);
        // MySQL commits every DDL separately. Only an exact installation prefix may resume.
        // Deployment must keep application writers and competing migrators stopped.
        $missingSeen = false;
        foreach ($installed as $present) {
            if ($present && $missingSeen) {
                throw new LogicException('Discovery guards are not an installation prefix.');
            }
            $missingSeen = $missingSeen || ! $present;
        }
        $rows = $exists ? $pdo->query('SELECT id, epoch, schema_version FROM '.DiscoveryEpoch::TABLE)->fetchAll(PDO::FETCH_ASSOC) : [];
        if ($rows === []) {
            if (in_array(true, $installed, true)) {
                throw new LogicException('Discovery seed is missing from protected installation.');
            }
        } elseif (count($rows) !== 1 || (int) $rows[0]['id'] !== 1 || (int) $rows[0]['schema_version'] !== 1
            || ! ctype_digit((string) $rows[0]['epoch']) || (int) $rows[0]['epoch'] > DiscoveryEpoch::MAX
            || ($missingSeen && (int) $rows[0]['epoch'] !== 0)) {
            throw new LogicException('Discovery recovery seed changed; partial history cannot be adopted.');
        }
        if (! $exists) {
            $pdo->exec(DiscoveryEpoch::tableSql($driver));
        }
        if ($rows === []) {
            $pdo->exec('INSERT INTO '.DiscoveryEpoch::TABLE.' (id, epoch, schema_version) VALUES (1, 0, 1)');
        }
        foreach ($guards as $name => $guard) {
            if (! $installed[$name]) {
                $pdo->exec($guard['sql']);
            }
        }
        (new DiscoveryEpoch)->assertInstalled($pdo, $driver);
    }

    private function sqlitePreflight(PDO $pdo, array $guards): array
    {
        $names = [DiscoveryEpoch::TABLE, ...array_keys($guards)];
        foreach ($pdo->query('SELECT name, tbl_name FROM sqlite_temp_master')->fetchAll(PDO::FETCH_ASSOC) as $object) {
            if (in_array(strtolower($object['name']), [...$names, ...DiscoveryEpoch::DEPENDENCIES], true)
                || strtolower($object['tbl_name']) === DiscoveryEpoch::TABLE) {
                throw new LogicException('Discovery schema is shadowed; recovery refused before DDL.');
            }
        }
        $objects = $pdo->query('SELECT type, name, tbl_name, sql FROM sqlite_master')->fetchAll(PDO::FETCH_ASSOC);
        $byName = array_column($objects, null, 'name');
        foreach ($objects as $object) {
            if (in_array(strtolower($object['name']), $names, true) && ! in_array($object['name'], $names, true)) {
                throw new LogicException('Discovery identity collision; recovery refused before DDL.');
            }
            if (strtolower($object['tbl_name']) === DiscoveryEpoch::TABLE && $object['name'] !== DiscoveryEpoch::TABLE && ! isset($guards[$object['name']])) {
                throw new LogicException('Unexpected discovery object; recovery refused before DDL.');
            }
        }
        foreach (DiscoveryEpoch::DEPENDENCIES as $table) {
            if (($byName[$table]['type'] ?? null) !== 'table') {
                throw new LogicException('Discovery dependency unavailable.');
            }
        }
        $exists = isset($byName[DiscoveryEpoch::TABLE]);
        if ($exists && (($byName[DiscoveryEpoch::TABLE]['type'] ?? null) !== 'table'
            || $byName[DiscoveryEpoch::TABLE]['sql'] !== DiscoveryEpoch::tableSql('sqlite'))) {
            throw new LogicException('Discovery table ownership changed.');
        }
        $installed = [];
        foreach ($guards as $name => $guard) {
            $row = $byName[$name] ?? null;
            if ($row && (! $exists || $row['type'] !== 'trigger' || $row['tbl_name'] !== $guard['table'] || $row['sql'] !== $guard['sql'])) {
                throw new LogicException('Discovery guard ownership changed.');
            }
            $installed[$name] = $row !== null;
        }

        return [$exists, $installed];
    }

    private function mysqlPreflight(PDO $pdo, array $guards): array
    {
        $this->refuseMysqlShadow($pdo, DiscoveryEpoch::TABLE);
        $tables = $pdo->query('SELECT TABLE_NAME, TABLE_TYPE, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_ASSOC);
        $exists = false;
        foreach ($tables as $table) {
            if (strtolower($table['TABLE_NAME']) === DiscoveryEpoch::TABLE) {
                if ($exists || $table['TABLE_NAME'] !== DiscoveryEpoch::TABLE || $table['TABLE_TYPE'] !== 'BASE TABLE' || $table['ENGINE'] !== 'InnoDB') {
                    throw new LogicException('Discovery table identity changed.');
                }
                $exists = true;
            } elseif (in_array(strtolower($table['TABLE_NAME']), array_keys($guards), true)) {
                throw new LogicException('Discovery identity collision; recovery refused before DDL.');
            }
        }
        foreach (DiscoveryEpoch::DEPENDENCIES as $dependency) {
            $matches = array_values(array_filter($tables, fn (array $table): bool => strtolower($table['TABLE_NAME']) === $dependency));
            if (count($matches) !== 1 || $matches[0]['TABLE_NAME'] !== $dependency || $matches[0]['TABLE_TYPE'] !== 'BASE TABLE' || $matches[0]['ENGINE'] !== 'InnoDB') {
                throw new LogicException('Discovery requires exact transactional dependency tables.');
            }
            $this->refuseMysqlShadow($pdo, $dependency);
        }
        $checks = $pdo->query("SELECT CONSTRAINT_NAME, TABLE_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'CHECK'")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($checks as $check) {
            if (in_array(strtolower($check['CONSTRAINT_NAME']), ['cde_id_check', 'cde_epoch_check', 'cde_schema_check'], true)
                && ($check['TABLE_NAME'] !== DiscoveryEpoch::TABLE || ! in_array($check['CONSTRAINT_NAME'], ['cde_id_check', 'cde_epoch_check', 'cde_schema_check'], true))) {
                throw new LogicException('Discovery constraint identity collision.');
            }
        }
        if ($exists) {
            $this->mysqlTablePreflight($pdo);
        }
        $rows = $pdo->query('SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, EVENT_MANIPULATION, ACTION_TIMING, ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_ASSOC);
        $installed = array_fill_keys(array_keys($guards), false);
        foreach ($rows as $row) {
            $name = $row['TRIGGER_NAME'];
            if (strtolower($name) === DiscoveryEpoch::TABLE || (strtolower($row['EVENT_OBJECT_TABLE']) === DiscoveryEpoch::TABLE && ! isset($guards[$name]))) {
                throw new LogicException('Unexpected discovery guard.');
            }
            if (! array_key_exists(strtolower($name), $guards)) {
                continue;
            }
            $guard = $guards[$name] ?? null;
            if (! $exists || ! $guard || $installed[$name] || $row['EVENT_OBJECT_TABLE'] !== $guard['table'] || $row['EVENT_MANIPULATION'] !== $guard['event']
                || $row['ACTION_TIMING'] !== $guard['timing'] || $row['ACTION_STATEMENT'] !== $guard['body']) {
                throw new LogicException('Discovery guard ownership changed.');
            }
            $installed[$name] = true;
        }

        return [$exists, $installed];
    }

    private function refuseMysqlShadow(PDO $pdo, string $table): void
    {
        try {
            $shown = $pdo->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $error) {
            if (($error->errorInfo[0] ?? null) === '42S02' && ($error->errorInfo[1] ?? null) === 1146) {
                return;
            }
            throw $error;
        }
        if (str_starts_with($shown['Create Table'] ?? '', 'CREATE TEMPORARY TABLE')) {
            throw new LogicException('Discovery schema is shadowed; recovery refused before DDL.');
        }
    }

    private function mysqlTablePreflight(PDO $pdo): void
    {
        $table = DiscoveryEpoch::TABLE;
        $storage = $pdo->query("SELECT TABLE_COLLATION, CREATE_OPTIONS, TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}'")->fetch(PDO::FETCH_ASSOC);
        $collation = $pdo->query('SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE()')->fetchColumn();
        if ($storage !== ['TABLE_COLLATION' => $collation, 'CREATE_OPTIONS' => '', 'TABLE_COMMENT' => '']) {
            throw new LogicException('Discovery table storage changed.');
        }
        $columns = $pdo->query("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLLATION_NAME, COLUMN_COMMENT, GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_ASSOC);
        $wanted = [];
        foreach (['id' => 'int', 'epoch' => 'bigint', 'schema_version' => 'int'] as $name => $type) {
            $wanted[] = ['COLUMN_NAME' => $name, 'COLUMN_TYPE' => $type, 'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => null, 'EXTRA' => '', 'COLLATION_NAME' => null, 'COLUMN_COMMENT' => '', 'GENERATION_EXPRESSION' => ''];
        }
        if ($columns !== $wanted) {
            throw new LogicException('Discovery table columns changed.');
        }
        $indexes = $pdo->query("SELECT INDEX_NAME, NON_UNIQUE, COLUMN_NAME, SEQ_IN_INDEX, INDEX_TYPE, COLLATION, SUB_PART, EXPRESSION, IS_VISIBLE, INDEX_COMMENT FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}'")->fetchAll(PDO::FETCH_ASSOC);
        if ($indexes !== [['INDEX_NAME' => 'PRIMARY', 'NON_UNIQUE' => 0, 'COLUMN_NAME' => 'id', 'SEQ_IN_INDEX' => 1, 'INDEX_TYPE' => 'BTREE', 'COLLATION' => 'A', 'SUB_PART' => null, 'EXPRESSION' => null, 'IS_VISIBLE' => 'YES', 'INDEX_COMMENT' => '']]) {
            throw new LogicException('Discovery indexes changed.');
        }
        $constraints = $pdo->query("SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' ORDER BY CONSTRAINT_NAME")->fetchAll(PDO::FETCH_ASSOC);
        if ($constraints !== [['CONSTRAINT_NAME' => 'cde_epoch_check', 'CONSTRAINT_TYPE' => 'CHECK'], ['CONSTRAINT_NAME' => 'cde_id_check', 'CONSTRAINT_TYPE' => 'CHECK'],
            ['CONSTRAINT_NAME' => 'cde_schema_check', 'CONSTRAINT_TYPE' => 'CHECK'], ['CONSTRAINT_NAME' => 'PRIMARY', 'CONSTRAINT_TYPE' => 'PRIMARY KEY']]) {
            throw new LogicException('Discovery constraints changed.');
        }
        $checks = $pdo->query("SELECT c.CONSTRAINT_NAME, c.CHECK_CLAUSE, t.ENFORCED FROM information_schema.CHECK_CONSTRAINTS c JOIN information_schema.TABLE_CONSTRAINTS t ON t.CONSTRAINT_SCHEMA = c.CONSTRAINT_SCHEMA AND t.CONSTRAINT_NAME = c.CONSTRAINT_NAME WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME = '{$table}'")->fetchAll(PDO::FETCH_ASSOC);
        $wanted = ['cde_id_check' => 'id=1', 'cde_epoch_check' => 'epoch>=0andepoch<='.DiscoveryEpoch::MAX, 'cde_schema_check' => 'schema_version=1'];
        foreach ($checks as $check) {
            if ($check['ENFORCED'] !== 'YES' || ($wanted[$check['CONSTRAINT_NAME']] ?? null) !== preg_replace('/[`()\s]+/', '', strtolower($check['CHECK_CLAUSE']))) {
                throw new LogicException('Discovery checks changed.');
            }
            unset($wanted[$check['CONSTRAINT_NAME']]);
        }
        if ($wanted !== []) {
            throw new LogicException('Discovery checks changed.');
        }
    }

    public function down(): void
    {
        throw new LogicException('Retain discovery epoch, guards and migration bookkeeping; operational teardown is unsupported.');
    }
};
