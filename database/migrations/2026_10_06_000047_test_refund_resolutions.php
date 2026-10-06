<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['test_refund_resolution_requests', 'test_refund_resolutions'];

    private function foreignKeys(string $table): array
    {
        return $table === self::TABLES[0]
            ? ['order_finalization_id' => 'order_finalizations', 'actor_id' => 'users']
            : ['request_record_id' => self::TABLES[0], 'order_id' => 'orders', 'order_finalization_id' => 'order_finalizations',
                'order_attempt_id' => 'order_attempts', 'observed_event_id' => 'test_payment_exception_events',
                'inventory_reservation_id' => 'inventory_reservations', 'promotion_use_id' => 'promotion_uses', 'actor_id' => 'users'];
    }

    private function uniqueColumns(string $table): array
    {
        return $table === self::TABLES[0] ? ['public_id']
            : ['public_id', 'request_record_id', 'order_id', 'order_finalization_id', 'order_attempt_id', 'observed_event_id', 'inventory_reservation_id', 'promotion_use_id'];
    }

    private function prefix(string $table): string
    {
        return $table === self::TABLES[0] ? 'refund_request' : 'refund_resolution';
    }

    private function tableStatements(string $name): array
    {
        $table = new Blueprint(DB::connection(), $name);
        $table->create();
        $table->id();
        $this->identity($table, 'public_id', 36);
        foreach ($this->foreignKeys($name) as $column => $parent) {
            $field = $table->foreignId($column);
            if ($column === 'promotion_use_id') {
                $field->nullable();
            }
            $field->constrained($parent, indexName: $this->prefix($name).'_'.$column.'_fk')->restrictOnDelete();
        }
        if ($name === self::TABLES[0]) {
            $this->identity($table, 'request_id', 36);
            $table->unsignedInteger('expected_sequence');
            $this->identity($table, 'policy_version', 64);
            $table->dateTime('created_at');
            $table->unique(['order_finalization_id', 'request_id'], 'refund_request_finalization_request_unique');
        } else {
            $table->longText('evidence_ciphertext');
            $this->identity($table, 'evidence_hash', 64);
            $this->identity($table, 'canonicalization_version', 32);
            $this->identity($table, 'policy_version', 64);
            $table->dateTime('released_at');
        }
        foreach ($this->uniqueColumns($name) as $column) {
            $table->unique($column, $this->prefix($name).'_'.$column.'_unique');
        }

        return $table->toSql();
    }

    private function identity(Blueprint $table, string $name, int $length): ColumnDefinition
    {
        $column = $table->string($name, $length);
        if (DB::getDriverName() === 'mysql') {
            $column->charset('ascii')->collation('ascii_bin');
        }

        return $column;
    }

    private function uuid(string $value): string
    {
        if (DB::getDriverName() === 'sqlite') {
            return "TYPEOF({$value}) = 'text' AND LENGTH({$value}) = 36 AND LENGTH(CAST({$value} AS BLOB)) = 36"
                ." AND SUBSTR({$value}, 9, 1) = '-' AND SUBSTR({$value}, 14, 1) = '-' AND SUBSTR({$value}, 19, 1) = '-' AND SUBSTR({$value}, 24, 1) = '-'"
                ." AND LENGTH(REPLACE({$value}, '-', '')) = 32 AND REPLACE({$value}, '-', '') NOT GLOB '*[^0-9a-f]*'";
        }

        return "OCTET_LENGTH({$value}) = 36 AND REGEXP_LIKE({$value}, '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$', 'c')";
    }

    private function integer(string $value, int $minimum = 1, int $maximum = PHP_INT_MAX): string
    {
        return (DB::getDriverName() === 'sqlite' ? "TYPEOF({$value}) = 'integer' AND " : '')."{$value} BETWEEN {$minimum} AND {$maximum}";
    }

    private function timestamp(string $value): string
    {
        return DB::getDriverName() === 'sqlite'
            ? "TYPEOF({$value}) = 'text' AND LENGTH({$value}) = 19 AND DATETIME({$value}, '+0 seconds') = {$value}"
            : "{$value} IS NOT NULL AND YEAR({$value}) BETWEEN 1000 AND 9999";
    }

    private function exact(string $value): string
    {
        return DB::getDriverName() === 'sqlite' ? $value : "CAST({$value} AS BINARY)";
    }

    private function guards(): array
    {
        $parent = 'EXISTS (SELECT 1 FROM order_finalizations f WHERE f.id = NEW.order_finalization_id AND '.$this->exact('f.mode')." = 'test'"
            .' AND '.$this->exact('f.outcome')." = 'paid_exception' AND ".$this->exact('f.policy_version')." = 'test-order-finalization-v1'"
            .' AND NOT EXISTS (SELECT 1 FROM test_unpaid_releases u WHERE u.order_id = f.order_id)'
            .' AND NOT EXISTS (SELECT 1 FROM license_grants g WHERE g.order_finalization_id = f.id))';
        $request = $this->uuid('NEW.public_id').' AND '.$this->uuid('NEW.request_id')
            .' AND '.$this->integer('NEW.order_finalization_id').' AND '.$this->integer('NEW.actor_id')
            .' AND '.$this->integer('NEW.expected_sequence', 0, 4294967293).' AND '.$this->timestamp('NEW.created_at')
            .' AND '.$this->exact('NEW.policy_version')." = 'test-refunded-exception-release-v1' AND {$parent}"
            .' AND NEW.expected_sequence = COALESCE((SELECT w.sequence FROM test_payment_exception_work w WHERE w.order_finalization_id = NEW.order_finalization_id), 0)'
            .' AND NOT EXISTS (SELECT 1 FROM test_refund_resolution_requests r WHERE r.id = NEW.id OR r.public_id = NEW.public_id'
            .' OR (r.order_finalization_id = NEW.order_finalization_id AND r.request_id = NEW.request_id))';
        $hash = DB::getDriverName() === 'sqlite'
            ? "TYPEOF(NEW.evidence_hash) = 'text' AND LENGTH(NEW.evidence_hash) = 64 AND LENGTH(CAST(NEW.evidence_hash AS BLOB)) = 64 AND NEW.evidence_hash NOT GLOB '*[^0-9a-f]*'"
            : "OCTET_LENGTH(NEW.evidence_hash) = 64 AND REGEXP_LIKE(NEW.evidence_hash, '^[0-9a-f]{64}$', 'c')";
        $proof = $this->uuid('NEW.public_id').' AND '.$this->timestamp('NEW.released_at')." AND {$parent} AND {$hash}"
            .(DB::getDriverName() === 'sqlite' ? " AND TYPEOF(NEW.evidence_ciphertext) = 'text'" : '')
            .' AND LENGTH(NEW.evidence_ciphertext) > 0 AND '.$this->exact('NEW.canonicalization_version')." = 'vasey-json-v1' AND ".$this->exact('NEW.policy_version')." = 'test-refunded-exception-release-v1'";
        foreach (array_keys($this->foreignKeys(self::TABLES[1])) as $column) {
            $condition = $this->integer('NEW.'.$column);
            $proof .= ' AND '.($column === 'promotion_use_id' ? "(NEW.promotion_use_id IS NULL OR ({$condition}))" : $condition);
        }
        $proof .= ' AND EXISTS (SELECT 1 FROM test_refund_resolution_requests q JOIN order_finalizations f ON f.id = q.order_finalization_id'
            .' JOIN order_attempts a ON a.id = f.order_attempt_id JOIN inventory_reservations v ON v.id = a.inventory_reservation_id'
            .' JOIN test_payment_exception_events e ON e.id = NEW.observed_event_id'
            .' JOIN test_payment_financial_observations o ON o.test_payment_exception_event_id = e.id'
            .' WHERE q.id = NEW.request_record_id AND q.order_finalization_id = NEW.order_finalization_id AND q.actor_id = NEW.actor_id'
            .' AND f.order_id = NEW.order_id AND f.order_attempt_id = NEW.order_attempt_id AND a.order_id = NEW.order_id'
            .' AND v.id = NEW.inventory_reservation_id AND v.attempt_id = a.public_id AND '.$this->exact('v.state')." = 'pending' AND v.consumed_at IS NULL"
            .' AND ((a.promotion_use_id IS NULL AND NEW.promotion_use_id IS NULL) OR (a.promotion_use_id = NEW.promotion_use_id'
            .' AND EXISTS (SELECT 1 FROM promotion_uses u WHERE u.id = a.promotion_use_id AND u.attempt_id = a.public_id AND '.$this->exact('u.state')." = 'pending' AND u.consumed_at IS NULL)))"
            .' AND e.order_finalization_id = f.id AND e.actor_id = NEW.actor_id AND e.request_id = q.request_id AND e.sequence = q.expected_sequence + 2'
            .' AND '.$this->exact('e.kind')." = 'reconciliation_observed' AND ".$this->exact('e.outcome')." = 'confirmed' AND ".$this->exact('o.state')." = 'observed'"
            .' AND e.observed_at = o.observed_at AND e.created_at >= e.observed_at AND e.observed_at >= q.created_at AND NEW.released_at >= e.created_at AND NEW.released_at >= f.finalized_at'
            .' AND EXISTS (SELECT 1 FROM test_payment_exception_events b WHERE b.order_finalization_id = f.id AND b.request_id = q.request_id'
            .' AND b.actor_id = NEW.actor_id AND b.sequence = q.expected_sequence + 1 AND '.$this->exact('b.kind')." = 'reconciliation_requested'"
            .' AND '.$this->exact('b.outcome')." = 'pending' AND b.observed_at IS NULL AND b.created_at >= q.created_at AND b.created_at <= e.observed_at))"
            .' AND NOT EXISTS (SELECT 1 FROM test_refund_resolutions r WHERE r.id = NEW.id'
            .implode('', array_map(fn ($column) => " OR r.{$column} = NEW.{$column}", $this->uniqueColumns(self::TABLES[1]))).')';
        $guards = [];
        foreach ([self::TABLES[0] => $request, self::TABLES[1] => $proof] as $table => $predicate) {
            foreach (['insert', 'update', 'delete'] as $operation) {
                $name = $this->prefix($table).'_'.$operation;
                $allowed = $operation === 'insert' ? $predicate : null;
                $signal = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid or immutable refund resolution evidence';";
                $body = 'BEGIN '.($allowed === null ? $signal : "IF NOT COALESCE(({$allowed}), 0) THEN {$signal} END IF;").' END';
                $sql = DB::getDriverName() === 'sqlite'
                    ? "CREATE TRIGGER {$name} BEFORE {$operation} ON {$table}".($allowed === null ? '' : " WHEN NOT COALESCE(({$allowed}), 0)")." BEGIN SELECT RAISE(ABORT, 'Invalid or immutable refund resolution evidence'); END"
                    : "CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW {$body}";
                $guards[$name] = compact('table', 'operation', 'body', 'sql');
            }
        }

        return $guards;
    }

    private function resourceDefinitions(bool $refund): array
    {
        $definitions = [];
        $release = true;
        $finalization = true;
        $emptyConsumption = ' AND NEW.consumed_at IS NULL';
        $oldEmptyConsumption = $finalization ? ' AND OLD.consumed_at IS NULL' : '';
        $this->resourceGuard($definitions, 'inventory_reservations_initial', 'inventory_reservations', 'insert',
            "NEW.state = 'held' AND NEW.attempt_id IS NULL AND NEW.pending_at IS NULL AND NEW.expired_at IS NULL AND NEW.expires_at > NEW.created_at".$emptyConsumption);
        $identity = $this->unchanged(['id', 'public_id', 'quote_id', 'snapshot', 'snapshot_hash', 'canonicalization_version', 'created_at', 'expires_at']);
        $pending = "NEW.state = 'pending' AND NEW.attempt_id IS NOT NULL AND NEW.pending_at IS NOT NULL AND NEW.pending_at >= OLD.created_at AND NEW.pending_at < OLD.expires_at AND NEW.expired_at IS NULL";
        $expired = "NEW.state = 'expired' AND NEW.attempt_id IS NULL AND NEW.pending_at IS NULL AND NEW.expired_at IS NOT NULL AND NEW.expired_at >= OLD.expires_at";
        $old = "OLD.state = 'held' AND OLD.attempt_id IS NULL AND OLD.pending_at IS NULL AND OLD.expired_at IS NULL AND (({$pending}) OR ({$expired}))".$emptyConsumption.$oldEmptyConsumption;
        $consumed = $finalization ? ' OR ('.$this->consume('inventory_reservation_id').' AND '.$this->unchanged(['attempt_id', 'pending_at', 'expired_at']).' AND OLD.expired_at IS NULL)' : '';
        $this->resourceGuard($definitions, 'inventory_reservations_transition', 'inventory_reservations', 'update', "{$identity} AND (({$old}){$consumed}".($release ? ' OR ('.$this->releaseResource('inventory_reservation_id', ['attempt_id', 'pending_at', 'expired_at'], $refund).')' : '').')');

        $this->resourceGuard($definitions, 'promotion_uses_guard_insert', 'promotion_uses', 'insert',
            "NEW.state = 'held' AND NEW.attempt_id IS NULL AND NEW.pending_at IS NULL AND NEW.expires_at > NEW.created_at".$emptyConsumption);
        $identity = $this->unchanged(['id', 'promotion_campaign_id', 'quote_pricing_id', 'created_at', 'expires_at']);
        $old = "OLD.state = 'held' AND NEW.state = 'pending' AND OLD.attempt_id IS NULL AND OLD.pending_at IS NULL AND NEW.attempt_id IS NOT NULL AND NEW.pending_at IS NOT NULL AND NEW.pending_at >= OLD.created_at AND NEW.pending_at < OLD.expires_at".$emptyConsumption.$oldEmptyConsumption;
        $consumed = $finalization ? ' OR ('.$this->consume('promotion_use_id').' AND '.$this->unchanged(['attempt_id', 'pending_at']).')' : '';
        $this->resourceGuard($definitions, 'promotion_uses_guard_update', 'promotion_uses', 'update', "{$identity} AND (({$old}){$consumed}".($release ? ' OR ('.$this->releaseResource('promotion_use_id', ['attempt_id', 'pending_at'], $refund).')' : '').')');

        return $definitions;
    }

    private function releaseResource(string $resource, array $unchanged, bool $refund): string
    {
        $legacy = "OLD.state = 'pending' AND NEW.state = 'released' AND OLD.consumed_at IS NULL AND NEW.consumed_at IS NULL"
            .' AND '.$this->unchanged($unchanged)
            .' AND EXISTS (SELECT 1 FROM test_unpaid_releases r JOIN order_attempts a ON a.id = r.order_attempt_id'
            ." WHERE r.{$resource} = NEW.id AND a.public_id = NEW.attempt_id AND a.order_id = r.order_id"
            ." AND r.mode = 'test' AND r.policy_version = 'test-unpaid-release-v1')";
        if (! $refund) {
            return $legacy;
        }
        $resolved = "OLD.state = 'pending' AND NEW.state = 'released' AND OLD.consumed_at IS NULL AND NEW.consumed_at IS NULL"
            .' AND '.$this->unchanged($unchanged)
            .' AND EXISTS (SELECT 1 FROM test_refund_resolutions r JOIN test_refund_resolution_requests q ON q.id = r.request_record_id'
            .' JOIN order_finalizations f ON f.id = r.order_finalization_id JOIN order_attempts a ON a.id = r.order_attempt_id'
            ." WHERE r.{$resource} = NEW.id AND a.public_id = NEW.attempt_id AND a.order_id = r.order_id AND f.order_id = r.order_id"
            .' AND f.order_attempt_id = a.id AND q.order_finalization_id = f.id AND q.actor_id = r.actor_id'
            ." AND r.policy_version = 'test-refunded-exception-release-v1' AND q.policy_version = r.policy_version"
            .' AND '.$this->exact('f.mode')." = 'test' AND ".$this->exact('f.outcome')." = 'paid_exception'"
            .' AND '.$this->exact('f.policy_version')." = 'test-order-finalization-v1')";

        return "({$legacy}) OR ({$resolved})";
    }

    private function consume(string $resource): string
    {
        return "OLD.state = 'pending' AND NEW.state = 'consumed' AND OLD.consumed_at IS NULL AND NEW.consumed_at IS NOT NULL"
            .' AND OLD.attempt_id IS NOT NULL AND OLD.pending_at IS NOT NULL AND NEW.consumed_at >= OLD.pending_at'
            .' AND EXISTS (SELECT 1 FROM order_finalizations f JOIN order_attempts a ON a.id = f.order_attempt_id'
            ." WHERE f.outcome = 'paid' AND f.mode = 'test' AND a.{$resource} = NEW.id"
            .' AND a.public_id = NEW.attempt_id AND f.order_id = a.order_id AND f.finalized_at = NEW.consumed_at)';
    }

    private function unchanged(array $fields): string
    {
        if (DB::getDriverName() === 'sqlite') {
            return implode(' AND ', array_map(fn ($field) => "NEW.{$field} IS OLD.{$field}", $fields));
        }

        // Existing resource columns predate ASCII identity columns. Preserve their bytes, not collation equivalence.
        return implode(' AND ', array_map(fn ($field) => "CAST(NEW.{$field} AS BINARY) <=> CAST(OLD.{$field} AS BINARY)", $fields));
    }

    private function resourceGuard(array &$definitions, string $name, string $table, string $operation, string $allowed): void
    {
        if ($operation !== 'update') {
            return;
        }
        if (DB::getDriverName() === 'mysql') {
            $allowed = preg_replace('/(?<![\w$.])(?:NEW\.state|OLD\.state)(?![\w$])/', 'CAST($0 AS BINARY)', $allowed)
                ?? throw new LogicException('The resource guard could not be rewritten.');
        }
        $signal = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid or immutable finalization evidence';";
        $body = "BEGIN IF NOT COALESCE(({$allowed}), 0) THEN {$signal} END IF; END";
        $sql = DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} WHEN NOT COALESCE(({$allowed}), 0) BEGIN SELECT RAISE(ABORT, 'Invalid or immutable finalization evidence'); END"
            : "CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW {$body}";
        $definitions[$name] = compact('table', 'operation', 'body', 'sql');
    }

    private function replaceResourceGuards(bool $refund): void
    {
        foreach ($this->resourceDefinitions($refund) as $name => $guard) {
            DB::unprepared('DROP TRIGGER '.$name);
            DB::unprepared($guard['sql']);
        }
    }

    public function up(): void
    {
        $this->preflight(false);
        foreach (self::TABLES as $table) {
            foreach ($this->tableStatements($table) as $statement) {
                DB::statement($statement);
            }
        }
        foreach ($this->guards() as $guard) {
            DB::unprepared($guard['sql']);
        }
        $this->replaceResourceGuards(true);
    }

    private function preflight(bool $installed): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new LogicException('Refund resolutions require guarded SQLite or MySQL.');
        }
        $sqlite = DB::getDriverName() === 'sqlite';
        $guards = $this->guards();
        $resources = $this->resourceDefinitions($installed);
        if ($sqlite && DB::table('sqlite_temp_master')->whereIn(DB::raw('name COLLATE NOCASE'), [...self::TABLES, 'inventory_reservations', 'promotion_uses', ...array_keys($guards), ...array_keys($resources)])
            ->orWhereIn(DB::raw('tbl_name COLLATE NOCASE'), [...self::TABLES, 'inventory_reservations', 'promotion_uses'])->exists()) {
            throw new LogicException('Temporary refund resolution objects prevent migration.');
        }
        foreach (self::TABLES as $table) {
            $objects = $sqlite ? DB::table('sqlite_master')->whereRaw('name COLLATE NOCASE = ?', [$table])->get()
                : DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::getDatabaseName())->whereRaw('LOWER(TABLE_NAME) = ?', [$table])->get();
            $object = $objects->first();
            $valid = ! $installed ? $objects->isEmpty() : $objects->count() === 1 && ($sqlite
                ? $object->name === $table && $object->type === 'table'
                : $object->TABLE_NAME === $table && $object->TABLE_TYPE === 'BASE TABLE' && $object->ENGINE === 'InnoDB');
            if (! $valid) {
                throw new LogicException('Unexpected refund resolution table ownership.');
            }
            if (! $sqlite) {
                try {
                    $definition = DB::selectOne('SHOW CREATE TABLE '.$table);
                    if (str_starts_with($definition->{'Create Table'} ?? '', 'CREATE TEMPORARY TABLE ')) {
                        throw new LogicException('Temporary refund resolution tables prevent migration.');
                    }
                } catch (QueryException $error) {
                    if (($error->errorInfo[0] ?? null) !== '42S02' || ($error->errorInfo[1] ?? null) !== 1146) {
                        throw $error;
                    }
                }
            }
            if (! $installed) {
                foreach ($this->tableStatements($table) as $statement) {
                    if ($sqlite && preg_match('/\Acreate (?:unique )?index "([^"]+)" /', $statement, $match)
                        && DB::table('sqlite_master')->whereRaw('name COLLATE NOCASE = ?', [$match[1]])->exists()) {
                        throw new LogicException('Foreign refund resolution index name refused.');
                    }
                    if (! $sqlite && preg_match('/\bconstraint `([^`]+)`/', $statement, $match)
                        && DB::table('information_schema.TABLE_CONSTRAINTS')->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())->whereRaw('LOWER(CONSTRAINT_NAME) = ?', [$match[1]])->exists()) {
                        throw new LogicException('Foreign refund resolution constraint name refused.');
                    }
                }
            }
        }
        if (! $sqlite) {
            foreach (['inventory_reservations', 'promotion_uses'] as $table) {
                $definition = DB::selectOne('SHOW CREATE TABLE '.$table);
                if (str_starts_with($definition->{'Create Table'} ?? '', 'CREATE TEMPORARY TABLE ')) {
                    throw new LogicException('Temporary resource tables prevent refund resolution migration.');
                }
            }
        }
        foreach ($guards + $resources as $name => $guard) {
            $objects = $sqlite ? DB::table('sqlite_master')->whereRaw('name COLLATE NOCASE = ?', [$name])->get()
                : DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->whereRaw('LOWER(TRIGGER_NAME) = ?', [$name])->get();
            $object = $objects->first();
            $mustExist = $installed || isset($resources[$name]);
            $valid = ! $mustExist ? $objects->isEmpty() : $objects->count() === 1 && ($sqlite
                ? $object->type === 'trigger' && $object->name === $name && $object->tbl_name === $guard['table'] && $object->sql === $guard['sql']
                : $object->TRIGGER_NAME === $name && $object->EVENT_OBJECT_TABLE === $guard['table'] && $object->ACTION_TIMING === 'BEFORE'
                    && $object->EVENT_MANIPULATION === strtoupper($guard['operation']) && $object->ACTION_STATEMENT === $guard['body']);
            if (! $valid) {
                throw new LogicException('Unexpected refund resolution guard ownership: '.$name);
            }
        }
    }

    private function ownedSchema(): void
    {
        $sqlite = DB::getDriverName() === 'sqlite';
        foreach (self::TABLES as $table) {
            $types = ['id' => 'bigint unsigned', 'public_id' => 'varchar(36)'];
            foreach ($this->foreignKeys($table) as $column => $parent) {
                $types[$column] = 'bigint unsigned';
            }
            $types += $table === self::TABLES[0]
                ? ['request_id' => 'varchar(36)', 'expected_sequence' => 'int unsigned', 'policy_version' => 'varchar(64)', 'created_at' => 'datetime']
                : ['evidence_ciphertext' => 'longtext', 'evidence_hash' => 'varchar(64)', 'canonicalization_version' => 'varchar(32)', 'policy_version' => 'varchar(64)', 'released_at' => 'datetime'];
            if ($sqlite && DB::table('sqlite_master')->where('type', 'table')->where('name', $table)->value('sql')
                !== preg_replace('/\Acreate table /', 'CREATE TABLE ', $this->tableStatements($table)[0])) {
                throw new LogicException('Unexpected refund resolution table definition.');
            }
            $columns = Schema::getColumns($table);
            if (array_column($columns, 'name') !== array_keys($types)) {
                throw new LogicException('Unexpected refund resolution columns.');
            }
            foreach ($columns as $column) {
                if ($column['nullable'] !== ($column['name'] === 'promotion_use_id') || $column['default'] !== null
                    || (! $sqlite && $column['type'] !== $types[$column['name']])) {
                    throw new LogicException('Unexpected refund resolution column definition.');
                }
            }
            if (! $sqlite) {
                $definition = DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $table)->sole();
                if ($definition->TABLE_COLLATION !== DB::connection()->getConfig('collation') || $definition->CREATE_OPTIONS !== '' || $definition->TABLE_COMMENT !== '') {
                    throw new LogicException('Unexpected refund resolution table options.');
                }
                foreach (DB::table('information_schema.COLUMNS')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $table)->get() as $column) {
                    $collation = str_starts_with($types[$column->COLUMN_NAME], 'varchar') ? 'ascii_bin'
                        : ($column->COLUMN_NAME === 'evidence_ciphertext' ? DB::connection()->getConfig('collation') : null);
                    if ($column->COLLATION_NAME !== $collation || $column->GENERATION_EXPRESSION !== '' || $column->COLUMN_COMMENT !== ''
                        || $column->EXTRA !== ($column->COLUMN_NAME === 'id' ? 'auto_increment' : '')) {
                        throw new LogicException('Unexpected refund resolution column attributes.');
                    }
                }
                if (DB::table('information_schema.TABLE_CONSTRAINTS')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $table)->where('CONSTRAINT_TYPE', 'CHECK')->exists()) {
                    throw new LogicException('Unexpected refund resolution check constraint.');
                }
            }
            $foreign = $this->foreignKeys($table);
            $actualForeign = Schema::getForeignKeys($table);
            if (count($foreign) !== count($actualForeign)) {
                throw new LogicException('Unexpected refund resolution foreign keys.');
            }
            foreach ($actualForeign as $key) {
                $column = $key['columns'][0] ?? '';
                if (! isset($foreign[$column]) || $key['columns'] !== [$column] || $key['foreign_table'] !== $foreign[$column]
                    || $key['foreign_columns'] !== ['id'] || $key['foreign_schema'] !== ($sqlite ? 'main' : DB::getDatabaseName())
                    || $key['on_delete'] !== 'restrict' || $key['on_update'] !== 'no action') {
                    throw new LogicException('Unexpected refund resolution foreign key definition.');
                }
                unset($foreign[$column]);
            }
            $this->ownedIndexes($table);
            $count = $sqlite ? DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', $table)->count()
                : DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->where('EVENT_OBJECT_TABLE', $table)->count();
            if ($count !== 3) {
                throw new LogicException('Foreign refund resolution guards prevent rollback.');
            }
        }
    }

    private function ownedIndexes(string $table): void
    {
        $sqlite = DB::getDriverName() === 'sqlite';
        $expected = ['primary' => [['id'], true]];
        foreach ($this->uniqueColumns($table) as $column) {
            $expected[$this->prefix($table).'_'.$column.'_unique'] = [[$column], true];
        }
        if ($table === self::TABLES[0]) {
            $expected['refund_request_finalization_request_unique'] = [['order_finalization_id', 'request_id'], true];
        }
        if (! $sqlite) {
            $expected[$this->prefix($table).'_actor_id_fk'] = [['actor_id'], false];
        }
        foreach (Schema::getIndexes($table) as $index) {
            $name = $index['primary'] ? 'primary' : $index['name'];
            if (! isset($expected[$name]) || [$index['columns'], $index['unique']] !== $expected[$name] || $index['type'] !== ($sqlite ? null : 'btree')) {
                throw new LogicException('Unexpected refund resolution index definition.');
            }
            unset($expected[$name]);
        }
        if ($expected !== []) {
            throw new LogicException('Missing refund resolution index.');
        }
        if ($sqlite) {
            foreach (DB::select('PRAGMA index_list('.$table.')') as $index) {
                if ($index->partial !== 0) {
                    throw new LogicException('Unexpected partial refund resolution index.');
                }
                foreach (DB::select('PRAGMA index_xinfo('.DB::connection()->getPdo()->quote($index->name).')') as $part) {
                    if ($part->key === 1 && ($part->cid < 0 || $part->coll !== 'BINARY' || $part->desc !== 0)) {
                        throw new LogicException('Unexpected refund resolution index expression.');
                    }
                }
            }
        } else {
            foreach (DB::table('information_schema.STATISTICS')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $table)->get() as $part) {
                if ($part->SUB_PART !== null || $part->EXPRESSION !== null || $part->COLLATION !== 'A' || $part->IS_VISIBLE !== 'YES' || $part->INDEX_COMMENT !== '') {
                    throw new LogicException('Unexpected refund resolution index attributes.');
                }
            }
        }
    }

    private function refuseExternalReferences(): void
    {
        if (DB::getDriverName() === 'mysql') {
            if (DB::table('information_schema.KEY_COLUMN_USAGE')->where('REFERENCED_TABLE_SCHEMA', DB::getDatabaseName())->whereIn('REFERENCED_TABLE_NAME', self::TABLES)
                ->where(fn ($query) => $query->where('TABLE_SCHEMA', '<>', DB::getDatabaseName())->orWhereNotIn('TABLE_NAME', self::TABLES))->exists()) {
                throw new LogicException('Foreign refund resolution references prevent rollback.');
            }

            return;
        }
        foreach (DB::table('sqlite_master')->where('type', 'table')->whereNotIn('name', self::TABLES)->pluck('name') as $table) {
            foreach (Schema::getForeignKeys($table) as $key) {
                if (in_array(strtolower($key['foreign_table']), self::TABLES, true)) {
                    throw new LogicException('Foreign refund resolution references prevent rollback.');
                }
            }
        }
    }

    public function down(): void
    {
        // Ownership, dependencies and retained rows are checked before any implicit-commit DDL.
        $this->preflight(true);
        $this->ownedSchema();
        $this->refuseExternalReferences();
        foreach (self::TABLES as $table) {
            if (DB::table($table)->exists()) {
                throw new LogicException('Refund resolution evidence must be retained.');
            }
        }
        $this->replaceResourceGuards(false);
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::drop($table);
        }
    }
};
