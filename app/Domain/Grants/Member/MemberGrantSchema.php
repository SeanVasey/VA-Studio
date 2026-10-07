<?php

namespace App\Domain\Grants\Member;

use App\Domain\Memberships\Production\MembershipSchema;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;

/** Atomic table DDL and strict prefix recovery. No adoption or repair of retained unguarded rows. */
final class MemberGrantSchema
{
    public const TABLES = ['production_member_profiles', 'production_member_definitions', 'production_member_origins', 'production_member_artifacts', 'production_member_activations'];

    public function table(string $logical): string
    {
        MemberGrantException::require(in_array($logical, self::TABLES, true), 'schema');
        $name = DB::connection()->getTablePrefix().$logical;
        MemberGrantException::require(strlen($name) <= 52 && preg_match('/\A[a-zA-Z0-9_]+\z/D', $name) === 1, 'schema');

        return DB::getDriverName() === 'sqlite' ? 'main."'.$name.'"' : '`'.$name.'`';
    }

    public function assertOwned(PDO $pdo): void
    {
        MemberGrantException::require(DB::connection()->getPdo() === $pdo, 'changed_connection');
        $this->up(false);
    }

    public function up(bool $ddl = true): void
    {
        $pdo = DB::connection()->getPdo();
        $driver = DB::getDriverName();
        MemberGrantException::require(in_array($driver, ['sqlite', 'mysql'], true), 'schema');
        (new MembershipSchema)->assertOwned($pdo);
        $this->assertNamespace($pdo, $driver);
        $plans = [];
        $absent = false;
        $prefixEnded = false;
        foreach (self::TABLES as $logical) {
            $table = $this->table($logical);
            $name = DB::connection()->getTablePrefix().$logical;
            $definition = $this->definition($logical, $driver);
            $create = 'CREATE TABLE '.$table.' ('.$definition['sql'].')'.($driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin' : '');
            $guards = $this->guards($logical, $driver, $name, $table);
            if ($driver === 'sqlite') {
                $statement = $pdo->prepare('SELECT name, tbl_name FROM sqlite_temp_master');
                $statement->execute();
                foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $object) {
                    MemberGrantException::require(! in_array(strtolower($object['name']), array_map(strtolower(...), [$name, ...array_keys($guards)]), true) && strtolower($object['tbl_name']) !== strtolower($name), 'shadowed_schema');
                }
                $statement = $pdo->prepare('SELECT name, type, sql FROM main.sqlite_master WHERE lower(name) = lower(?)');
                $statement->execute([$name]);
                $objects = $statement->fetchAll(PDO::FETCH_ASSOC);
                MemberGrantException::require(count($objects) <= 1 && ($objects === [] || $objects[0]['name'] === $name), 'schema_namespace');
                $actual = $objects[0] ?? false;
                if ($actual !== false) {
                    MemberGrantException::require($actual['type'] === 'table' && $this->sql($actual['sql']) === $this->sql(str_replace('main.', '', $create)), 'schema');
                    $indexes = $pdo->query('PRAGMA main.index_list("'.$name.'")')->fetchAll(PDO::FETCH_ASSOC);
                    MemberGrantException::require(count($indexes) === 1 + count($definition['unique']), 'schema');
                }
                $statement = $pdo->prepare("SELECT name FROM main.sqlite_master WHERE type = 'trigger' AND tbl_name = ?");
                $statement->execute([$name]);
                MemberGrantException::require(array_diff($statement->fetchAll(PDO::FETCH_COLUMN), array_keys($guards)) === [], 'schema');
            } else {
                $statement = $pdo->prepare('SELECT TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE BINARY TABLE_SCHEMA = BINARY DATABASE() AND lower(TABLE_NAME) = lower(?)');
                $statement->execute([$name]);
                $objects = $statement->fetchAll(PDO::FETCH_ASSOC);
                MemberGrantException::require(count($objects) <= 1 && ($objects === [] || $objects[0]['TABLE_NAME'] === $name), 'schema_namespace');
                $actual = $objects[0] ?? false;
                if ($actual !== false) {
                    unset($actual['TABLE_NAME']);
                }
                // SHOW also resolves a local temporary table when no durable table exists.
                try {
                    $shown = $pdo->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM);
                    MemberGrantException::require(! str_contains(strtoupper($shown[1]), 'TEMPORARY'), 'shadowed_schema');
                } catch (\PDOException $error) {
                    if ($actual !== false || $error->getCode() !== '42S02') {
                        throw $error;
                    }
                }
                if ($actual !== false) {
                    MemberGrantException::require($actual === ['TABLE_TYPE' => 'BASE TABLE', 'ENGINE' => 'InnoDB', 'TABLE_COLLATION' => 'utf8mb4_bin'], 'schema');
                    $this->mysqlDefinition($pdo, $name, $definition);
                }
                $statement = $pdo->prepare('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE BINARY TRIGGER_SCHEMA = BINARY DATABASE() AND EVENT_OBJECT_TABLE = ?');
                $statement->execute([$name]);
                MemberGrantException::require(array_diff($statement->fetchAll(PDO::FETCH_COLUMN), array_keys($guards)) === [], 'schema');
            }
            MemberGrantException::require(! (($absent || $prefixEnded) && $actual !== false), 'schema_prefix');
            $absent = $absent || $actual === false;
            $prefixEnded = $prefixEnded || $actual === false;
            $missing = [];
            foreach ($guards as $guard => $expected) {
                if ($driver === 'sqlite') {
                    $statement = $pdo->prepare('SELECT name, type, tbl_name, sql FROM main.sqlite_master WHERE lower(name) = lower(?)');
                    $statement->execute([$guard]);
                    $objects = $statement->fetchAll(PDO::FETCH_ASSOC);
                    MemberGrantException::require(count($objects) <= 1 && ($objects === [] || $objects[0]['name'] === $guard), 'schema_namespace');
                    $row = $objects[0] ?? false;
                    if ($row !== false) {
                        MemberGrantException::require($row['type'] === 'trigger' && $row['tbl_name'] === $name && $this->sql($row['sql']) === $this->sql($expected['sql']), 'schema');
                    }
                } else {
                    $statement = $pdo->prepare('SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, EVENT_MANIPULATION, ACTION_TIMING, ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE BINARY TRIGGER_SCHEMA = BINARY DATABASE() AND lower(TRIGGER_NAME) = lower(?)');
                    $statement->execute([$guard]);
                    $objects = $statement->fetchAll(PDO::FETCH_ASSOC);
                    MemberGrantException::require(count($objects) <= 1 && ($objects === [] || $objects[0]['TRIGGER_NAME'] === $guard), 'schema_namespace');
                    $row = $objects[0] ?? false;
                    if ($row !== false) {
                        MemberGrantException::require($row['EVENT_OBJECT_TABLE'] === $name && $row['EVENT_MANIPULATION'] === $expected['event'] && $row['ACTION_TIMING'] === 'BEFORE' && $this->sql($row['ACTION_STATEMENT']) === $this->sql($expected['body']), 'schema');
                    }
                }
                MemberGrantException::require(! ($prefixEnded && $row !== false), 'schema_prefix');
                $prefixEnded = $prefixEnded || $row === false;
                if ($row === false) {
                    $missing[] = $expected['sql'];
                }
            }
            if ($actual !== false && $missing !== []) {
                MemberGrantException::require($pdo->query('SELECT 1 FROM '.$table.' LIMIT 1')->fetchColumn() === false, 'retained_unguarded_schema');
            }
            MemberGrantException::require($ddl || ($actual !== false && $missing === []), 'schema');
            MemberGrantException::require($actual !== false || count($missing) === count($guards), 'schema');
            $plans[] = ['create' => $actual === false ? $create : null, 'guards' => $missing];
        }
        // Inspect the entire existing graph before the first write, including later drift/foreign names.
        if ($ddl) {
            foreach ($plans as $plan) {
                if ($plan['create'] !== null) {
                    $pdo->exec($plan['create']);
                }
                foreach ($plan['guards'] as $sql) {
                    $pdo->exec($sql);
                }
            }
            $this->up(false);

        }
    }

    public function down(): never
    {
        throw new LogicException('Production member original evidence requires an explicit retention migration.');
    }

    private function physical(string $logical): string
    {
        if (in_array($logical, [...self::TABLES, 'production_membership_redemptions'], true)) {
            return DB::connection()->getTablePrefix().$logical;
        }
        MemberGrantException::require(in_array($logical, ['users', 'customer_accounts', 'production_membership_redemptions'], true), 'schema');

        return $logical;
    }

    private function definition(string $logical, string $driver): array
    {
        $name = $this->physical($logical);
        $ascii = $driver === 'mysql' ? ' CHARACTER SET ascii COLLATE ascii_bin' : ' COLLATE BINARY';
        $text = $driver === 'mysql' ? 'LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin' : 'TEXT';
        $integer = $driver === 'mysql' ? 'BIGINT UNSIGNED' : 'INTEGER';
        $id = 'VARCHAR(36)'.$ascii.' NOT NULL';
        $hash = 'VARCHAR(64)'.$ascii.' NOT NULL';
        $utc = 'VARCHAR(19)'.$ascii.' NOT NULL';
        $columns = ['id' => $id];
        $primary = ['id'];
        $unique = [];
        $foreign = [];
        $check = 'length(id) = 36 AND length(seal) = 64';
        if ($logical === self::TABLES[0]) {
            $columns += ['profile_hash' => $hash, 'original_terms_hash' => $hash, 'implementation_hash' => $hash,
                'font_manifest_hash' => $hash, 'provenance' => 'VARCHAR(32)'.$ascii.' NOT NULL'];
            $unique = [['profile_hash']];
            $check .= " AND length(profile_hash) = 64 AND length(original_terms_hash) = 64 AND length(implementation_hash) = 64 AND length(font_manifest_hash) = 64 AND provenance IN ('synthetic_rehearsal','verified_production')";
        } elseif ($logical === self::TABLES[1]) {
            $columns += ['profile_id' => $id, 'definition_hash' => $hash, 'original_terms_hash' => $hash,
                'policy_hash' => $hash, 'license_manifest_hash' => $hash, 'asset_manifest_hash' => $hash,
                'retention_policy_hash' => $hash, 'family' => 'VARCHAR(64)'.$ascii.' NOT NULL',
                'purpose' => 'VARCHAR(64)'.$ascii.' NOT NULL', 'version' => 'INTEGER NOT NULL',
                'provenance' => 'VARCHAR(32)'.$ascii.' NOT NULL'];
            $unique = [['definition_hash']];
            $foreign = ['profile_id' => self::TABLES[0]];
            $check .= " AND length(definition_hash) = 64 AND length(original_terms_hash) = 64 AND length(policy_hash) = 64 AND length(license_manifest_hash) = 64 AND length(asset_manifest_hash) = 64 AND length(retention_policy_hash) = 64 AND family = 'production-member-origin-v1' AND purpose = 'production-member-license-grant-v1' AND version = 1 AND provenance IN ('synthetic_rehearsal','verified_production')";
        } elseif ($logical === self::TABLES[2]) {
            $columns += ['redemption_id' => $id, 'definition_id' => $id, 'profile_id' => $id,
                'account_id' => $integer.' NOT NULL', 'user_id' => $integer.' NOT NULL', 'identity_origin_id' => $integer.' NOT NULL',
                'invoice_identity_hash' => $hash, 'owner_binding_hash' => $hash, 'intent_hash' => $hash,
                'license_manifest_hash' => $hash, 'asset_manifest_hash' => $hash, 'original_terms_hash' => $hash,
                'artifact_count' => 'INTEGER NOT NULL', 'artifact_manifest_hash' => $hash,
                'honor_deadline' => $utc, 'provenance' => 'VARCHAR(32)'.$ascii.' NOT NULL'];
            $unique = [['redemption_id']];
            $foreign = ['redemption_id' => 'production_membership_redemptions', 'definition_id' => self::TABLES[1],
                'profile_id' => self::TABLES[0], 'account_id' => 'customer_accounts', 'user_id' => 'users'];
            $check .= " AND account_id > 0 AND user_id > 0 AND identity_origin_id > 0 AND length(invoice_identity_hash) = 64 AND length(owner_binding_hash) = 64 AND length(intent_hash) = 64 AND length(license_manifest_hash) = 64 AND length(asset_manifest_hash) = 64 AND length(original_terms_hash) = 64 AND artifact_count BETWEEN 1 AND 16 AND length(artifact_manifest_hash) = 64 AND created_at < honor_deadline AND provenance IN ('synthetic_rehearsal','verified_production')";
        } elseif ($logical === self::TABLES[3]) {
            $columns += ['origin_id' => $id, 'ordinal' => 'INTEGER NOT NULL', 'role' => 'VARCHAR(32)'.$ascii.' NOT NULL',
                'sha256' => $hash, 'bytes' => $integer.' NOT NULL', 'storage_policy_hash' => $hash];
            $unique = [['origin_id', 'ordinal'], ['origin_id', 'role']];
            $foreign = ['origin_id' => self::TABLES[2]];
            $check .= ' AND ordinal BETWEEN 0 AND 15 AND length(role) BETWEEN 1 AND 32 AND length(sha256) = 64 AND bytes BETWEEN 1 AND 536870912 AND length(storage_policy_hash) = 64';
        } else {
            $columns += ['origin_id' => $id, 'redemption_id' => $id, 'artifact_manifest_hash' => $hash,
                'readiness_receipt_hash' => $hash, 'reservation_event_hash' => $hash,
                'purpose' => 'VARCHAR(64)'.$ascii.' NOT NULL'];
            $unique = [['origin_id'], ['redemption_id']];
            $foreign = ['origin_id' => self::TABLES[2], 'redemption_id' => 'production_membership_redemptions'];
            $check .= " AND length(artifact_manifest_hash) = 64 AND length(readiness_receipt_hash) = 64 AND length(reservation_event_hash) = 64 AND purpose = 'production-member-license-grant-v1'";
        }
        $columns += ['payload_ciphertext' => $text.' NOT NULL', 'seal' => $hash, 'created_at' => $utc];
        $quote = $driver === 'mysql' ? '`' : '"';
        $sql = implode(', ', array_map(fn ($column, $type) => $quote.$column.$quote.' '.$type, array_keys($columns), $columns));
        $sql .= ', PRIMARY KEY ('.implode(', ', $primary).'), CONSTRAINT '.$quote.$name.'_bounds'.$quote.' CHECK ('.$check.')';
        foreach ($unique as $index => $parts) {
            $sql .= ', CONSTRAINT '.$quote.$name.'_u'.$index.$quote.' UNIQUE ('.implode(', ', $parts).')';
        }
        foreach (array_keys($foreign) as $index => $column) {
            $sql .= ', CONSTRAINT '.$quote.$name.'_f'.$index.$quote.' FOREIGN KEY ('.$column.') REFERENCES '.$quote.$this->physical($foreign[$column]).$quote.' (id) ON UPDATE RESTRICT ON DELETE RESTRICT';
        }

        return compact('sql', 'columns', 'primary', 'unique', 'check', 'foreign');
    }

    private function guards(string $logical, string $driver, string $name, string $table): array
    {
        $profiles = $this->table(self::TABLES[0]);
        $definitions = $this->table(self::TABLES[1]);
        $origins = $this->table(self::TABLES[2]);
        $artifacts = $this->table(self::TABLES[3]);
        $insert = 'NOT EXISTS (SELECT 1 FROM '.$table.' WHERE id = NEW.id)';
        if ($logical === self::TABLES[0]) {
            $insert .= ' AND NOT EXISTS (SELECT 1 FROM '.$table.' WHERE profile_hash = NEW.profile_hash)';
        } elseif ($logical === self::TABLES[1]) {
            $insert .= ' AND NOT EXISTS (SELECT 1 FROM '.$table.' WHERE definition_hash = NEW.definition_hash)'
                .' AND EXISTS (SELECT 1 FROM '.$profiles.' WHERE id = NEW.profile_id AND original_terms_hash = NEW.original_terms_hash AND provenance = NEW.provenance)';
        } elseif ($logical === self::TABLES[2]) {
            $insert .= ' AND NOT EXISTS (SELECT 1 FROM '.$table.' WHERE redemption_id = NEW.redemption_id)'
                .' AND EXISTS (SELECT 1 FROM '.$definitions.' WHERE id = NEW.definition_id AND profile_id = NEW.profile_id AND original_terms_hash = NEW.original_terms_hash AND license_manifest_hash = NEW.license_manifest_hash AND asset_manifest_hash = NEW.asset_manifest_hash AND provenance = NEW.provenance)'
                .' AND EXISTS (SELECT 1 FROM '.$this->physical('production_membership_redemptions').' WHERE id = NEW.redemption_id AND owner_binding_hash = NEW.owner_binding_hash AND intent_hash = NEW.intent_hash AND license_manifest_hash = NEW.license_manifest_hash AND asset_manifest_hash = NEW.asset_manifest_hash AND original_terms_hash = NEW.original_terms_hash AND honor_deadline = NEW.honor_deadline)'
                .' AND EXISTS (SELECT 1 FROM customer_accounts a JOIN users u ON u.id = a.user_id WHERE a.id = NEW.account_id AND u.id = NEW.user_id AND a.active = 1 AND u.is_admin = 0 AND u.email_verified_at IS NOT NULL)';
        } elseif ($logical === self::TABLES[3]) {
            $insert .= ' AND NOT EXISTS (SELECT 1 FROM '.$table.' WHERE origin_id = NEW.origin_id AND (ordinal = NEW.ordinal OR role = NEW.role))'
                .' AND NEW.ordinal = (SELECT COUNT(*) FROM '.$artifacts.' WHERE origin_id = NEW.origin_id)'
                .' AND EXISTS (SELECT 1 FROM '.$origins.' WHERE id = NEW.origin_id AND artifact_count > NEW.ordinal)';
        } else {
            $insert .= ' AND NOT EXISTS (SELECT 1 FROM '.$table.' WHERE origin_id = NEW.origin_id OR redemption_id = NEW.redemption_id)'
                .' AND EXISTS (SELECT 1 FROM '.$origins.' o WHERE o.id = NEW.origin_id AND o.redemption_id = NEW.redemption_id AND o.artifact_manifest_hash = NEW.artifact_manifest_hash AND NEW.created_at < o.honor_deadline AND o.artifact_count = (SELECT COUNT(*) FROM '.$artifacts.' WHERE origin_id = o.id))';
        }
        $result = [];
        foreach (['insert' => $insert, 'update' => '0 = 1', 'delete' => '0 = 1'] as $event => $condition) {
            $guard = $name.'_'.$event;
            $condition = $driver === 'sqlite' ? str_replace('main.', '', $condition) : $condition;
            $body = "BEGIN IF NOT COALESCE(({$condition}), 0) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Retain production member originals'; END IF; END";
            $sql = $driver === 'mysql' ? 'CREATE TRIGGER `'.$guard.'` BEFORE '.strtoupper($event).' ON '.$table.' FOR EACH ROW '.$body
                : 'CREATE TRIGGER "'.$guard.'" BEFORE '.strtoupper($event).' ON '.$table.' WHEN NOT COALESCE(('.$condition."),0) BEGIN SELECT RAISE(ABORT, 'Retain production member originals'); END";
            $result[$guard] = ['sql' => $sql, 'event' => strtoupper($event), 'body' => $body];
        }

        return $result;
    }

    private function assertNamespace(PDO $pdo, string $driver): void
    {
        $reserved = [];
        foreach (self::TABLES as $logical) {
            $name = DB::connection()->getTablePrefix().$logical;
            $this->table($logical);
            $reserved[strtolower($name)] = ['name' => $name, 'type' => 'table'];
            foreach (['insert', 'update', 'delete'] as $event) {
                $guard = $name.'_'.$event;
                $reserved[strtolower($guard)] = ['name' => $guard, 'type' => 'trigger'];
            }
        }
        if ($driver === 'sqlite') {
            // Inline non-integer primary keys create schema-global autoindexes. Their
            // identity belongs to the owned table, unlike SQLite's named CHECK/FK clauses.
            $indexes = [];
            foreach (self::TABLES as $logical) {
                $name = DB::connection()->getTablePrefix().$logical;
                for ($index = 1; $index <= 1 + count($this->definition($logical, $driver)['unique']); $index++) {
                    $symbol = 'sqlite_autoindex_'.$name.'_'.$index;
                    $indexes[strtolower($symbol)] = ['name' => $symbol, 'type' => 'index', 'tbl_name' => $name, 'sql' => null];
                }
            }
            $objects = $pdo->query('SELECT name, type, tbl_name, sql FROM main.sqlite_master')->fetchAll(PDO::FETCH_ASSOC);
            $rows = [];
            $seen = [];
            foreach ($objects as $object) {
                $key = strtolower($object['name']);
                if (isset($indexes[$key])) {
                    MemberGrantException::require(! isset($seen[$key]) && $indexes[$key] === $object, 'schema_namespace');
                    $seen[$key] = true;
                }
                $rows[] = ['name' => $object['name'], 'type' => $object['type']];
            }
        } else {
            // CHECK and FK symbols are schema-global in distinct native namespaces.
            // Query with the dictionary's name collation, then require exact bytes and
            // ownership. A foreign table-local index/unique name is allowed to coexist.
            $statement = $pdo->prepare('SELECT CONSTRAINT_NAME, TABLE_NAME, CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE BINARY CONSTRAINT_SCHEMA = BINARY DATABASE() AND CONSTRAINT_TYPE = ? AND CONSTRAINT_NAME = ?');
            foreach (self::TABLES as $logical) {
                $name = DB::connection()->getTablePrefix().$logical;
                $definition = $this->definition($logical, $driver);
                $constraints = [$name.'_bounds' => 'CHECK'];
                foreach (array_keys($definition['foreign']) as $index => $column) {
                    $constraints[$name.'_f'.$index] = 'FOREIGN KEY';
                }
                foreach ($constraints as $symbol => $type) {
                    $statement->execute([$type, $symbol]);
                    $objects = $statement->fetchAll(PDO::FETCH_ASSOC);
                    MemberGrantException::require($objects === [] || $objects === [['CONSTRAINT_NAME' => $symbol, 'TABLE_NAME' => $name, 'CONSTRAINT_TYPE' => $type]], 'schema_namespace');
                }
            }
            $rows = $pdo->query("SELECT TABLE_NAME AS name, 'table' AS type FROM information_schema.TABLES WHERE BINARY TABLE_SCHEMA = BINARY DATABASE() UNION ALL SELECT TRIGGER_NAME AS name, 'trigger' AS type FROM information_schema.TRIGGERS WHERE BINARY TRIGGER_SCHEMA = BINARY DATABASE()")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($reserved as $expected) {
                if ($expected['type'] === 'trigger') {
                    try {
                        $pdo->query('SHOW CREATE TABLE `'.$expected['name'].'`')->fetchAll();
                        throw new MemberGrantException('schema_namespace');
                    } catch (\PDOException $error) {
                        if ($error->getCode() !== '42S02') {
                            throw $error;
                        }
                    }
                }
            }
        }
        $seen = [];
        foreach ($rows as $row) {
            $key = strtolower($row['name']);
            if (isset($reserved[$key])) {
                MemberGrantException::require(! isset($seen[$key]) && $reserved[$key] === $row, 'schema_namespace');
                $seen[$key] = true;
            }
        }
    }

    private function mysqlDefinition(PDO $pdo, string $name, array $definition): void
    {
        $s = $pdo->prepare('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLLATION_NAME FROM information_schema.COLUMNS WHERE BINARY TABLE_SCHEMA = BINARY DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
        $s->execute([$name]);
        $columns = $s->fetchAll(PDO::FETCH_ASSOC);
        MemberGrantException::require(array_column($columns, 'COLUMN_NAME') === array_keys($definition['columns']), 'schema');
        foreach ($columns as $column) {
            $expected = $definition['columns'][$column['COLUMN_NAME']];
            MemberGrantException::require($column['COLUMN_TYPE'] === (str_starts_with($expected, 'INTEGER') ? 'int' : (str_starts_with($expected, 'BIGINT UNSIGNED') ? 'bigint unsigned' : strtolower(explode(' ', $expected)[0]))) && $column['COLUMN_DEFAULT'] === null && $column['EXTRA'] === ''
                && $column['IS_NULLABLE'] === (str_contains($expected, 'NOT NULL') ? 'NO' : 'YES')
                && $column['COLLATION_NAME'] === (str_contains($expected, 'ascii_bin') ? 'ascii_bin' : (str_contains($expected, 'utf8mb4_bin') ? 'utf8mb4_bin' : null)), 'schema');
        }
        $indexes = $pdo->query('SHOW INDEX FROM `'.$name.'`')->fetchAll(PDO::FETCH_ASSOC);
        $actual = [];
        foreach ($indexes as $index) {
            MemberGrantException::require($index['Index_type'] === 'BTREE' && $index['Sub_part'] === null && $index['Non_unique'] == ($index['Key_name'] === 'PRIMARY' || preg_match('/_u[0-9]+\z/D', $index['Key_name']) === 1 ? 0 : 1), 'schema');
            $actual[$index['Key_name']][] = $index['Column_name'];
        }
        $wanted = ['PRIMARY' => $definition['primary']];
        foreach ($definition['unique'] as $index => $parts) {
            $wanted[$name.'_u'.$index] = $parts;
        }
        foreach (array_keys($definition['foreign']) as $index => $column) {
            if (! array_any($wanted, fn ($parts): bool => $parts[0] === $column)) {
                $wanted[$name.'_f'.$index] = [$column];
            }
        }
        ksort($actual);
        ksort($wanted);
        MemberGrantException::require($actual === $wanted, 'schema');
        $s = $pdo->prepare('SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE BINARY TABLE_SCHEMA = BINARY DATABASE() AND TABLE_NAME = ?');
        $s->execute([$name]);
        $actual = array_column($s->fetchAll(PDO::FETCH_ASSOC), 'CONSTRAINT_TYPE', 'CONSTRAINT_NAME');
        $wanted = ['PRIMARY' => 'PRIMARY KEY', $name.'_bounds' => 'CHECK'];
        foreach ($definition['unique'] as $index => $parts) {
            $wanted[$name.'_u'.$index] = 'UNIQUE';
        }
        foreach (array_keys($definition['foreign']) as $index => $column) {
            $target = $definition['foreign'][$column];
            $wanted[$name.'_f'.$index] = 'FOREIGN KEY';
            $s = $pdo->prepare('SELECT COLUMN_NAME, REFERENCED_TABLE_SCHEMA, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME, ORDINAL_POSITION FROM information_schema.KEY_COLUMN_USAGE WHERE BINARY CONSTRAINT_SCHEMA = BINARY DATABASE() AND BINARY TABLE_NAME = BINARY ? AND CONSTRAINT_NAME = ?');
            $s->execute([$name, $name.'_f'.$index]);
            MemberGrantException::require($s->fetchAll(PDO::FETCH_ASSOC) === [['COLUMN_NAME' => $column, 'REFERENCED_TABLE_SCHEMA' => $pdo->query('SELECT DATABASE()')->fetchColumn(), 'REFERENCED_TABLE_NAME' => $this->physical($target), 'REFERENCED_COLUMN_NAME' => 'id', 'ORDINAL_POSITION' => 1]], 'schema');
            $s = $pdo->prepare('SELECT UNIQUE_CONSTRAINT_SCHEMA, UPDATE_RULE, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE BINARY CONSTRAINT_SCHEMA = BINARY DATABASE() AND BINARY TABLE_NAME = BINARY ? AND CONSTRAINT_NAME = ?');
            $s->execute([$name, $name.'_f'.$index]);
            MemberGrantException::require($s->fetchAll(PDO::FETCH_ASSOC) === [['UNIQUE_CONSTRAINT_SCHEMA' => $pdo->query('SELECT DATABASE()')->fetchColumn(), 'UPDATE_RULE' => 'RESTRICT', 'DELETE_RULE' => 'RESTRICT']], 'schema');
        }
        ksort($actual);
        ksort($wanted);
        MemberGrantException::require($actual === $wanted, 'schema');
        $s = $pdo->prepare("SELECT c.CHECK_CLAUSE, t.ENFORCED FROM information_schema.CHECK_CONSTRAINTS c JOIN information_schema.TABLE_CONSTRAINTS t ON BINARY t.CONSTRAINT_SCHEMA = BINARY c.CONSTRAINT_SCHEMA AND BINARY t.CONSTRAINT_NAME = BINARY c.CONSTRAINT_NAME WHERE BINARY t.TABLE_SCHEMA = BINARY DATABASE() AND BINARY t.TABLE_NAME = BINARY ? AND t.CONSTRAINT_TYPE = 'CHECK' AND t.CONSTRAINT_NAME = ?");
        $s->execute([$name, $name.'_bounds']);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        MemberGrantException::require(count($rows) === 1 && $rows[0]['ENFORCED'] === 'YES' && $this->check($rows[0]['CHECK_CLAUSE']) === $this->check($definition['check']), 'schema');
    }

    private function sql(string $sql): string
    {
        return trim(preg_replace('/\s+/', ' ', $sql), ' ;');
    }

    private function check(string $sql): string
    {
        // Native8.0 dictionaries introduce/escape these exact ASCII enum literals.
        // Admit only the owned literal spellings, never arbitrary charset or SQL rewriting.
        foreach (['synthetic_rehearsal', 'verified_production', 'production-member-origin-v1', 'production-member-license-grant-v1'] as $literal) {
            $sql = str_replace("_utf8mb4\\'".$literal."\\'", "'".$literal."'", $sql);
        }

        return strtolower(preg_replace('/[\s`()]+/', '', $sql));
    }
}
