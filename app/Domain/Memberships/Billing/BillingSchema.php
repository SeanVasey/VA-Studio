<?php

namespace App\Domain\Memberships\Billing;

use App\Domain\Customers\ProductionIdentity\IdentityMigrationOwnership;
use App\Domain\Memberships\Production\MembershipSchema;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;

/**
 * Billing259 evidence tables: immutable approved subscription bindings, one row per provider invoice
 * identity, append-only hash-linked retrieval observations and deduplicated webhook hints. Same atomic
 * DDL, owned-prefix recovery and schema-global namespace algorithm as MembershipSchema. No foreign key
 * or trigger reaches into any production_identity_* child; buyer values are bound by runtime proof.
 * No row here awards, reserves or consumes a credit.
 */
final class BillingSchema
{
    public const TABLES = ['production_membership_billing_subscriptions', 'production_membership_billing_invoices', 'production_membership_billing_observations', 'production_membership_billing_events'];

    public const OUTCOMES = ['settled', 'not_settled', 'unknown', 'refused', 'reversed'];

    public const DISPOSITIONS = ['retrieval_hint', 'no_invoice_hint', 'ignored_type'];

    public function table(string $logical): string
    {
        BillingException::require(in_array($logical, self::TABLES, true), 'schema');
        $name = DB::connection()->getTablePrefix().$logical;
        BillingException::require(strlen($name) <= 52 && preg_match('/\A[a-zA-Z0-9_]+\z/D', $name) === 1, 'schema');

        return DB::getDriverName() === 'sqlite' ? 'main."'.$name.'"' : '`'.$name.'`';
    }

    public function assertOwned(PDO $pdo): void
    {
        BillingException::require(DB::connection()->getPdo() === $pdo, 'changed_connection');
        $this->up(false);
    }

    public function up(bool $ddl = true): void
    {
        $pdo = DB::connection()->getPdo();
        $driver = DB::getDriverName();
        BillingException::require(in_array($driver, ['sqlite', 'mysql'], true), 'schema');
        [$parents] = (new IdentityMigrationOwnership)->inspect($pdo, $driver);
        BillingException::require(! in_array(false, $parents, true), 'parent_floor');
        // Plan versions are the 257 parent of every subscription binding.
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
                    BillingException::require(! in_array(strtolower($object['name']), array_map(strtolower(...), [$name, ...array_keys($guards)]), true) && strtolower($object['tbl_name']) !== strtolower($name), 'shadowed_schema');
                }
                $statement = $pdo->prepare('SELECT name, type, sql FROM main.sqlite_master WHERE lower(name) = lower(?)');
                $statement->execute([$name]);
                $objects = $statement->fetchAll(PDO::FETCH_ASSOC);
                BillingException::require(count($objects) <= 1 && ($objects === [] || $objects[0]['name'] === $name), 'schema_namespace');
                $actual = $objects[0] ?? false;
                if ($actual !== false) {
                    BillingException::require($actual['type'] === 'table' && $this->sql($actual['sql']) === $this->sql(str_replace('main.', '', $create)), 'schema');
                    $indexes = $pdo->query('PRAGMA main.index_list("'.$name.'")')->fetchAll(PDO::FETCH_ASSOC);
                    BillingException::require(count($indexes) === 1 + count($definition['unique']), 'schema');
                }
                $statement = $pdo->prepare("SELECT name FROM main.sqlite_master WHERE type = 'trigger' AND tbl_name = ?");
                $statement->execute([$name]);
                BillingException::require(array_diff($statement->fetchAll(PDO::FETCH_COLUMN), array_keys($guards)) === [], 'schema');
            } else {
                $statement = $pdo->prepare('SELECT TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE BINARY TABLE_SCHEMA = BINARY DATABASE() AND lower(TABLE_NAME) = lower(?)');
                $statement->execute([$name]);
                $objects = $statement->fetchAll(PDO::FETCH_ASSOC);
                BillingException::require(count($objects) <= 1 && ($objects === [] || $objects[0]['TABLE_NAME'] === $name), 'schema_namespace');
                $actual = $objects[0] ?? false;
                if ($actual !== false) {
                    unset($actual['TABLE_NAME']);
                }
                // SHOW also resolves a local temporary table when no durable table exists.
                try {
                    $shown = $pdo->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM);
                    BillingException::require(! str_contains(strtoupper($shown[1]), 'TEMPORARY'), 'shadowed_schema');
                } catch (\PDOException $error) {
                    if ($actual !== false || $error->getCode() !== '42S02') {
                        throw $error;
                    }
                }
                if ($actual !== false) {
                    BillingException::require($actual === ['TABLE_TYPE' => 'BASE TABLE', 'ENGINE' => 'InnoDB', 'TABLE_COLLATION' => 'utf8mb4_bin'], 'schema');
                    $this->mysqlDefinition($pdo, $name, $definition);
                }
                $statement = $pdo->prepare('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE BINARY TRIGGER_SCHEMA = BINARY DATABASE() AND EVENT_OBJECT_TABLE = ?');
                $statement->execute([$name]);
                BillingException::require(array_diff($statement->fetchAll(PDO::FETCH_COLUMN), array_keys($guards)) === [], 'schema');
            }
            BillingException::require(! (($absent || $prefixEnded) && $actual !== false), 'schema_prefix');
            $absent = $absent || $actual === false;
            $prefixEnded = $prefixEnded || $actual === false;
            $missing = [];
            foreach ($guards as $guard => $expected) {
                if ($driver === 'sqlite') {
                    $statement = $pdo->prepare('SELECT name, type, tbl_name, sql FROM main.sqlite_master WHERE lower(name) = lower(?)');
                    $statement->execute([$guard]);
                    $objects = $statement->fetchAll(PDO::FETCH_ASSOC);
                    BillingException::require(count($objects) <= 1 && ($objects === [] || $objects[0]['name'] === $guard), 'schema_namespace');
                    $row = $objects[0] ?? false;
                    if ($row !== false) {
                        BillingException::require($row['type'] === 'trigger' && $row['tbl_name'] === $name && $this->sql($row['sql']) === $this->sql($expected['sql']), 'schema');
                    }
                } else {
                    $statement = $pdo->prepare('SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, EVENT_MANIPULATION, ACTION_TIMING, ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE BINARY TRIGGER_SCHEMA = BINARY DATABASE() AND lower(TRIGGER_NAME) = lower(?)');
                    $statement->execute([$guard]);
                    $objects = $statement->fetchAll(PDO::FETCH_ASSOC);
                    BillingException::require(count($objects) <= 1 && ($objects === [] || $objects[0]['TRIGGER_NAME'] === $guard), 'schema_namespace');
                    $row = $objects[0] ?? false;
                    if ($row !== false) {
                        BillingException::require($row['EVENT_OBJECT_TABLE'] === $name && $row['EVENT_MANIPULATION'] === $expected['event'] && $row['ACTION_TIMING'] === 'BEFORE' && $this->sql($row['ACTION_STATEMENT']) === $this->sql($expected['body']), 'schema');
                    }
                }
                BillingException::require(! ($prefixEnded && $row !== false), 'schema_prefix');
                $prefixEnded = $prefixEnded || $row === false;
                if ($row === false) {
                    $missing[] = $expected['sql'];
                }
            }
            if ($actual !== false && $missing !== []) {
                BillingException::require($pdo->query('SELECT 1 FROM '.$table.' LIMIT 1')->fetchColumn() === false, 'retained_unguarded_schema');
            }
            BillingException::require($ddl || ($actual !== false && $missing === []), 'schema');
            BillingException::require($actual !== false || count($missing) === count($guards), 'schema');
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
        throw new LogicException('Production membership billing evidence requires an explicit retention migration.');
    }

    private function physical(string $logical): string
    {
        if (in_array($logical, [...self::TABLES, 'production_membership_plan_versions'], true)) {
            return DB::connection()->getTablePrefix().$logical;
        }
        BillingException::require(in_array($logical, ['users', 'customer_accounts'], true), 'schema');

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
        $mode = 'VARCHAR(8)'.$ascii.' NOT NULL';
        if ($logical === self::TABLES[0]) {
            $columns += ['account_id' => $integer.' NOT NULL', 'user_id' => $integer.' NOT NULL', 'identity_origin_id' => $integer.' NOT NULL',
                'provider_account_hash' => $hash, 'mode' => $mode, 'customer_ref_hash' => $hash, 'subscription_ref_hash' => $hash,
                'price_ref_hash' => $hash, 'plan_version_id' => $id, 'approval_binding_hash' => $hash, 'provenance' => 'VARCHAR(32)'.$ascii.' NOT NULL'];
            $unique = [['subscription_ref_hash']];
            $foreign = ['account_id' => 'customer_accounts', 'user_id' => 'users', 'plan_version_id' => 'production_membership_plan_versions'];
            $check .= " AND account_id > 0 AND user_id > 0 AND identity_origin_id > 0 AND length(provider_account_hash) = 64 AND length(customer_ref_hash) = 64 AND length(subscription_ref_hash) = 64 AND length(price_ref_hash) = 64 AND length(approval_binding_hash) = 64 AND ((mode = 'test' AND provenance = 'synthetic_rehearsal') OR (mode = 'live' AND provenance = 'verified_production'))";
        } elseif ($logical === self::TABLES[1]) {
            $columns += ['subscription_binding_id' => $id, 'invoice_ref_hash' => $hash, 'source_invoice_hash' => $hash,
                'provider_account_hash' => $hash, 'mode' => $mode];
            $unique = [['invoice_ref_hash'], ['source_invoice_hash']];
            $foreign = ['subscription_binding_id' => self::TABLES[0]];
            $check .= " AND length(invoice_ref_hash) = 64 AND length(source_invoice_hash) = 64 AND length(provider_account_hash) = 64 AND mode IN ('test','live')";
        } elseif ($logical === self::TABLES[2]) {
            $columns += ['invoice_id' => $id, 'sequence' => 'INTEGER NOT NULL', 'outcome' => 'VARCHAR(16)'.$ascii.' NOT NULL', 'facts_hash' => $hash,
                'line_period_start' => 'VARCHAR(19)'.$ascii.' NULL', 'line_period_end' => 'VARCHAR(19)'.$ascii.' NULL',
                'amount_minor' => 'INTEGER NULL', 'currency' => 'VARCHAR(3)'.$ascii.' NULL', 'retrieved_at' => $utc,
                'retrieval_started_at' => 'VARCHAR(26)'.$ascii.' NOT NULL', 'freshness_deadline' => $utc,
                'api_version' => 'VARCHAR(32)'.$ascii.' NOT NULL', 'sdk_reference' => 'VARCHAR(40)'.$ascii.' NOT NULL', 'prior_seal' => $hash];
            $unique = [['invoice_id', 'sequence']];
            $foreign = ['invoice_id' => self::TABLES[1]];
            $check .= " AND sequence BETWEEN 1 AND 10000 AND outcome IN ('settled','not_settled','unknown','refused','reversed') AND length(facts_hash) = 64 AND length(prior_seal) = 64 AND retrieved_at < freshness_deadline AND length(retrieval_started_at) = 26 AND length(api_version) BETWEEN 1 AND 32 AND length(sdk_reference) = 40 AND (outcome <> 'settled' OR (line_period_start IS NOT NULL AND line_period_end IS NOT NULL AND line_period_start < line_period_end AND amount_minor IS NOT NULL AND amount_minor > 0 AND currency IS NOT NULL AND length(currency) = 3))";
        } else {
            $columns += ['provider_event_ref_hash' => $hash, 'type' => 'VARCHAR(64)'.$ascii.' NOT NULL', 'mode' => $mode,
                'provider_account_hash' => $hash, 'invoice_ref_hash' => 'VARCHAR(64)'.$ascii.' NULL', 'received_at' => $utc,
                'payload_hash' => $hash, 'disposition' => 'VARCHAR(32)'.$ascii.' NOT NULL'];
            $unique = [['provider_event_ref_hash']];
            $check .= " AND length(provider_event_ref_hash) = 64 AND length(type) BETWEEN 1 AND 64 AND mode IN ('test','live') AND length(provider_account_hash) = 64 AND (invoice_ref_hash IS NULL OR length(invoice_ref_hash) = 64) AND length(payload_hash) = 64 AND disposition IN ('retrieval_hint','no_invoice_hint','ignored_type')";
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
        $subscriptions = $this->table(self::TABLES[0]);
        $invoices = $this->table(self::TABLES[1]);
        $observations = $this->table(self::TABLES[2]);
        $plans = $this->physical('production_membership_plan_versions');
        $insert = 'NOT EXISTS (SELECT 1 FROM '.$table.' WHERE id = NEW.id)';
        if ($logical === self::TABLES[0]) {
            $insert .= ' AND NOT EXISTS (SELECT 1 FROM '.$table.' WHERE subscription_ref_hash = NEW.subscription_ref_hash)'
                .' AND EXISTS (SELECT 1 FROM '.$plans.' WHERE id = NEW.plan_version_id AND provenance = NEW.provenance)'
                .' AND EXISTS (SELECT 1 FROM customer_accounts a JOIN users u ON u.id = a.user_id WHERE a.id = NEW.account_id AND u.id = NEW.user_id AND a.active = 1 AND u.is_admin = 0 AND u.email_verified_at IS NOT NULL)';
        } elseif ($logical === self::TABLES[1]) {
            $insert .= ' AND NOT EXISTS (SELECT 1 FROM '.$table.' WHERE invoice_ref_hash = NEW.invoice_ref_hash OR source_invoice_hash = NEW.source_invoice_hash)'
                .' AND EXISTS (SELECT 1 FROM '.$subscriptions.' WHERE id = NEW.subscription_binding_id AND provider_account_hash = NEW.provider_account_hash AND mode = NEW.mode)';
        } elseif ($logical === self::TABLES[2]) {
            $insert .= ' AND EXISTS (SELECT 1 FROM '.$invoices.' WHERE id = NEW.invoice_id)'
                .' AND NOT EXISTS (SELECT 1 FROM '.$observations.' WHERE invoice_id = NEW.invoice_id AND sequence = NEW.sequence)'
                .' AND NEW.sequence = COALESCE((SELECT MAX(sequence) FROM '.$observations.' WHERE invoice_id = NEW.invoice_id), 0) + 1'
                ." AND ((NEW.sequence = 1 AND NEW.prior_seal = '".str_repeat('0', 64)."')"
                .' OR EXISTS (SELECT 1 FROM '.$observations.' o WHERE o.invoice_id = NEW.invoice_id AND o.sequence = NEW.sequence - 1 AND o.seal = NEW.prior_seal AND o.created_at <= NEW.created_at'
                // A retrieval that began before the one that produced the tail is stale evidence and never becomes the tail (review R-6).
                .' AND o.retrieval_started_at <= NEW.retrieval_started_at))';
        } else {
            $insert .= ' AND NOT EXISTS (SELECT 1 FROM '.$table.' WHERE provider_event_ref_hash = NEW.provider_event_ref_hash)';
        }
        $result = [];
        foreach (['insert' => $insert, 'update' => '0 = 1', 'delete' => '0 = 1'] as $event => $condition) {
            $guard = $name.'_'.$event;
            $condition = $driver === 'sqlite' ? str_replace('main.', '', $condition) : $condition;
            $body = "BEGIN IF NOT COALESCE(({$condition}), 0) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Retain production membership billing evidence'; END IF; END";
            $sql = $driver === 'mysql' ? 'CREATE TRIGGER `'.$guard.'` BEFORE '.strtoupper($event).' ON '.$table.' FOR EACH ROW '.$body
                : 'CREATE TRIGGER "'.$guard.'" BEFORE '.strtoupper($event).' ON '.$table.' WHEN NOT COALESCE(('.$condition."),0) BEGIN SELECT RAISE(ABORT, 'Retain production membership billing evidence'); END";
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
                    BillingException::require(! isset($seen[$key]) && $indexes[$key] === $object, 'schema_namespace');
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
                    BillingException::require($objects === [] || $objects === [['CONSTRAINT_NAME' => $symbol, 'TABLE_NAME' => $name, 'CONSTRAINT_TYPE' => $type]], 'schema_namespace');
                }
            }
            $rows = $pdo->query("SELECT TABLE_NAME AS name, 'table' AS type FROM information_schema.TABLES WHERE BINARY TABLE_SCHEMA = BINARY DATABASE() UNION ALL SELECT TRIGGER_NAME AS name, 'trigger' AS type FROM information_schema.TRIGGERS WHERE BINARY TRIGGER_SCHEMA = BINARY DATABASE()")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($reserved as $expected) {
                if ($expected['type'] === 'trigger') {
                    try {
                        $pdo->query('SHOW CREATE TABLE `'.$expected['name'].'`')->fetchAll();
                        throw new BillingException('schema_namespace');
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
                BillingException::require(! isset($seen[$key]) && $reserved[$key] === $row, 'schema_namespace');
                $seen[$key] = true;
            }
        }
    }

    private function mysqlDefinition(PDO $pdo, string $name, array $definition): void
    {
        $s = $pdo->prepare('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLLATION_NAME FROM information_schema.COLUMNS WHERE BINARY TABLE_SCHEMA = BINARY DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
        $s->execute([$name]);
        $columns = $s->fetchAll(PDO::FETCH_ASSOC);
        BillingException::require(array_column($columns, 'COLUMN_NAME') === array_keys($definition['columns']), 'schema');
        foreach ($columns as $column) {
            $expected = $definition['columns'][$column['COLUMN_NAME']];
            BillingException::require($column['COLUMN_TYPE'] === (str_starts_with($expected, 'INTEGER') ? 'int' : (str_starts_with($expected, 'BIGINT UNSIGNED') ? 'bigint unsigned' : strtolower(explode(' ', $expected)[0]))) && $column['COLUMN_DEFAULT'] === null && $column['EXTRA'] === ''
                && $column['IS_NULLABLE'] === (str_contains($expected, 'NOT NULL') ? 'NO' : 'YES')
                && $column['COLLATION_NAME'] === (str_contains($expected, 'ascii_bin') ? 'ascii_bin' : (str_contains($expected, 'utf8mb4_bin') ? 'utf8mb4_bin' : null)), 'schema');
        }
        $indexes = $pdo->query('SHOW INDEX FROM `'.$name.'`')->fetchAll(PDO::FETCH_ASSOC);
        $actual = [];
        foreach ($indexes as $index) {
            BillingException::require($index['Index_type'] === 'BTREE' && $index['Sub_part'] === null && $index['Non_unique'] == ($index['Key_name'] === 'PRIMARY' || preg_match('/_u[0-9]+\z/D', $index['Key_name']) === 1 ? 0 : 1), 'schema');
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
        BillingException::require($actual === $wanted, 'schema');
        $s = $pdo->prepare('SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE BINARY TABLE_SCHEMA = BINARY DATABASE() AND TABLE_NAME = ?');
        $s->execute([$name]);
        $actual = array_column($s->fetchAll(PDO::FETCH_ASSOC), 'CONSTRAINT_TYPE', 'CONSTRAINT_NAME');
        $wanted = ['PRIMARY' => 'PRIMARY KEY', $name.'_bounds' => 'CHECK'];
        foreach ($definition['unique'] as $index => $parts) {
            $wanted[$name.'_u'.$index] = 'UNIQUE';
        }
        foreach (array_keys($definition['foreign']) as $index => $column) {
            $target = $definition['foreign'][$column];
            $schema = $pdo->query('SELECT DATABASE()')->fetchColumn();
            $wanted[$name.'_f'.$index] = 'FOREIGN KEY';
            $s = $pdo->prepare('SELECT COLUMN_NAME, REFERENCED_TABLE_SCHEMA, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME, ORDINAL_POSITION FROM information_schema.KEY_COLUMN_USAGE WHERE BINARY CONSTRAINT_SCHEMA = BINARY DATABASE() AND BINARY TABLE_NAME = BINARY ? AND CONSTRAINT_NAME = ?');
            $s->execute([$name, $name.'_f'.$index]);
            BillingException::require($s->fetchAll(PDO::FETCH_ASSOC) === [['COLUMN_NAME' => $column, 'REFERENCED_TABLE_SCHEMA' => $schema, 'REFERENCED_TABLE_NAME' => $this->physical($target), 'REFERENCED_COLUMN_NAME' => 'id', 'ORDINAL_POSITION' => 1]], 'schema');
            $s = $pdo->prepare('SELECT UNIQUE_CONSTRAINT_SCHEMA, UNIQUE_CONSTRAINT_NAME, REFERENCED_TABLE_NAME, MATCH_OPTION, UPDATE_RULE, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE BINARY CONSTRAINT_SCHEMA = BINARY DATABASE() AND BINARY TABLE_NAME = BINARY ? AND CONSTRAINT_NAME = ?');
            $s->execute([$name, $name.'_f'.$index]);
            BillingException::require($s->fetchAll(PDO::FETCH_ASSOC) === [['UNIQUE_CONSTRAINT_SCHEMA' => $schema, 'UNIQUE_CONSTRAINT_NAME' => 'PRIMARY', 'REFERENCED_TABLE_NAME' => $this->physical($target), 'MATCH_OPTION' => 'NONE', 'UPDATE_RULE' => 'RESTRICT', 'DELETE_RULE' => 'RESTRICT']], 'schema');
        }
        ksort($actual);
        ksort($wanted);
        BillingException::require($actual === $wanted, 'schema');
        $s = $pdo->prepare("SELECT c.CHECK_CLAUSE, t.ENFORCED FROM information_schema.CHECK_CONSTRAINTS c JOIN information_schema.TABLE_CONSTRAINTS t ON BINARY t.CONSTRAINT_SCHEMA = BINARY c.CONSTRAINT_SCHEMA AND BINARY t.CONSTRAINT_NAME = BINARY c.CONSTRAINT_NAME WHERE BINARY t.TABLE_SCHEMA = BINARY DATABASE() AND BINARY t.TABLE_NAME = BINARY ? AND t.CONSTRAINT_TYPE = 'CHECK' AND t.CONSTRAINT_NAME = ?");
        $s->execute([$name, $name.'_bounds']);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        BillingException::require(count($rows) === 1 && $rows[0]['ENFORCED'] === 'YES' && $this->check($rows[0]['CHECK_CLAUSE']) === $this->check($definition['check']), 'schema');
    }

    private function sql(string $sql): string
    {
        return trim(preg_replace('/\s+/', ' ', $sql), ' ;');
    }

    private function check(string $sql): string
    {
        // Native8.0 dictionaries introduce/escape these exact ASCII enum literals.
        // Admit only the owned literal spellings, never arbitrary charset or SQL rewriting.
        foreach (['synthetic_rehearsal', 'verified_production', 'test', 'live', 'settled', 'not_settled', 'unknown', 'refused', 'reversed',
            'retrieval_hint', 'no_invoice_hint', 'ignored_type'] as $literal) {
            $sql = str_replace("_utf8mb4\\'".$literal."\\'", "'".$literal."'", $sql);
        }

        return strtolower(preg_replace('/[\s`()]+/', '', $sql));
    }
}
