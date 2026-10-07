<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->requireDriver();
        $this->refuseTemporaryShadows();
        $statements = $this->tableStatements();
        $exists = $this->tableExists();
        $missing = $exists ? $this->preflightTable($statements) : $statements;
        $guards = $this->guards();
        $installed = $this->preflightGuards($guards);
        $this->preflightExternalDependencies();
        if (! $exists) {
            $this->preflightAbsentTableNames($statements);
        } elseif (($missing !== [] || in_array(false, $installed, true)) && DB::table('inquiry_notification_intents')->exists()) {
            throw new LogicException('Unprotected retained inquiry notifications require investigation; missing protections cannot be retroactively installed.');
        }
        // CREATE TABLE, foreign keys, indexes and triggers commit separately on MySQL.
        // A retry adds only missing owned parts; mismatches are refused before any DDL.
        foreach ($missing as $statement) {
            DB::statement($statement);
        }
        foreach ($guards as $name => $definition) {
            if (! $installed[$name]) {
                DB::unprepared($definition['statement']);
            }
        }
    }

    private function tableStatements(): array
    {
        $table = new Blueprint(DB::connection(), 'inquiry_notification_intents');
        $table->create();
        $collation = DB::getDriverName() === 'mysql' ? 'ascii_bin' : 'BINARY';
        $table->id();
        $table->foreignId('customer_inquiry_id')->unique()->constrained('customer_inquiries')->restrictOnDelete();
        $table->foreignId('operator_user_id')->constrained('users')->restrictOnDelete();
        $table->string('kind', 24)->collation($collation);
        $table->string('state', 16)->collation($collation);
        $table->unsignedInteger('attempts');
        // Wider storage keeps UUID padding visible to the exact 36-byte guard after MySQL column coercion.
        $table->string('claim_token', 64)->collation($collation)->nullable();
        $table->dateTime('lease_expires_at')->nullable();
        $table->dateTime('next_attempt_at')->nullable();
        $table->string('outcome', 32)->collation($collation)->nullable();
        $table->dateTime('created_at');
        $table->dateTime('updated_at');
        $table->index(['state', 'next_attempt_at', 'id'], 'inquiry_notifications_due');
        $table->index(['state', 'lease_expires_at', 'id'], 'inquiry_notifications_lease');

        return $table->toSql();
    }

    private function guards(): array
    {
        $state = $this->exact('NEW.state');
        $oldState = $this->exact('OLD.state');
        $outcome = $this->exact('NEW.outcome');
        $clearClaim = 'NEW.claim_token IS NULL AND NEW.lease_expires_at IS NULL';
        $clearSchedule = 'NEW.next_attempt_at IS NULL';
        $clearOutcome = 'NEW.outcome IS NULL';
        $attempts = 'NEW.attempts = CAST(NEW.attempts AS '.(DB::getDriverName() === 'mysql' ? 'SIGNED' : 'INTEGER').') AND NEW.attempts BETWEEN 0 AND 3';
        $dates = $this->dateTime('NEW.created_at').' AND '.$this->dateTime('NEW.updated_at')
            .' AND (NEW.lease_expires_at IS NULL OR '.$this->dateTime('NEW.lease_expires_at').')'
            .' AND (NEW.next_attempt_at IS NULL OR '.$this->dateTime('NEW.next_attempt_at').')';
        $shape = "{$attempts} AND {$dates} AND NEW.updated_at >= NEW.created_at AND ("
            ."({$state} = 'pending' AND NEW.attempts = 0 AND {$clearClaim} AND {$clearSchedule} AND {$clearOutcome})"
            ." OR ({$state} = 'processing' AND NEW.attempts >= 1 AND ".$this->uuid('NEW.claim_token')
            ." AND NEW.lease_expires_at > NEW.updated_at AND {$clearSchedule} AND {$clearOutcome})"
            ." OR ({$state} = 'retry' AND NEW.attempts BETWEEN 1 AND 2 AND {$clearClaim}"
            ." AND NEW.next_attempt_at > NEW.updated_at AND {$outcome} = 'definitely_not_submitted')"
            ." OR ({$state} = 'submitted' AND NEW.attempts >= 1 AND {$clearClaim} AND {$clearSchedule} AND {$outcome} = 'handed_off')"
            ." OR ({$state} = 'unknown' AND NEW.attempts >= 1 AND {$clearClaim} AND {$clearSchedule}"
            ." AND {$outcome} IN ('handoff_uncertain', 'lease_expired'))"
            ." OR ({$state} = 'blocked' AND {$clearClaim} AND {$clearSchedule}"
            ." AND ({$outcome} IN ('authority_withdrawn', 'configuration_withdrawn') OR (NEW.attempts = 3 AND {$outcome} = 'retry_exhausted'))))";
        $parent = 'EXISTS (SELECT 1 FROM customer_inquiries i WHERE i.id = NEW.customer_inquiry_id AND i.operator_user_id = NEW.operator_user_id)';
        $guards['inquiry_notification_intents_insert'] = $this->guard('insert', $shape." AND {$state} = 'pending' AND ".$this->exact('NEW.kind')." = 'operator_inbox_v1'"
            .' AND NEW.created_at = NEW.updated_at AND '.$parent);

        $identity = implode(' AND ', array_map(fn (string $column): string => DB::getDriverName() === 'mysql'
            ? "BINARY NEW.{$column} = BINARY OLD.{$column}" : "NEW.{$column} IS OLD.{$column}",
            ['id', 'customer_inquiry_id', 'operator_user_id', 'kind', 'created_at']));
        $claim = "{$state} = 'processing' AND NEW.attempts = OLD.attempts + 1 AND OLD.attempts < 3"
            ." AND ({$oldState} = 'pending' OR ({$oldState} = 'retry' AND OLD.next_attempt_at <= NEW.updated_at))";
        $active = "{$oldState} = 'processing' AND OLD.lease_expires_at > NEW.updated_at AND NEW.attempts = OLD.attempts";
        $finish = "{$active} AND ({$state} IN ('submitted', 'retry', 'blocked')"
            ." OR ({$state} = 'unknown' AND {$outcome} = 'handoff_uncertain'))";
        $expired = "{$oldState} = 'processing' AND OLD.lease_expires_at <= NEW.updated_at"
            ." AND {$state} = 'unknown' AND {$outcome} = 'lease_expired' AND NEW.attempts = OLD.attempts";
        $withdrawn = "{$oldState} IN ('pending', 'retry') AND {$state} = 'blocked'"
            ." AND {$outcome} = 'authority_withdrawn' AND NEW.attempts = OLD.attempts";
        $guards['inquiry_notification_intents_update'] = $this->guard('update', $identity.' AND '.$shape.' AND NEW.updated_at >= OLD.updated_at'
            ." AND (({$claim}) OR ({$finish}) OR ({$expired}) OR ({$withdrawn}))");
        $guards['inquiry_notification_intents_delete'] = $this->guard('delete');

        return $guards;
    }

    private function exact(string $value): string
    {
        // ascii_bin still uses PAD SPACE; binary operands reject padded states, kinds and outcomes.
        return DB::getDriverName() === 'mysql' ? 'BINARY '.$value : $value;
    }

    private function uuid(string $value): string
    {
        if (DB::getDriverName() === 'sqlite') {
            // LENGTH(text) and GLOB stop at NUL: check all stored bytes as well.
            return "{$value} IS NOT NULL AND LENGTH(CAST({$value} AS BLOB)) = 36 AND LENGTH({$value}) = 36"
                ." AND SUBSTR({$value}, 9, 1) = '-' AND SUBSTR({$value}, 14, 1) = '-'"
                ." AND SUBSTR({$value}, 19, 1) = '-' AND SUBSTR({$value}, 24, 1) = '-'"
                ." AND LENGTH(REPLACE({$value}, '-', '')) = 32 AND REPLACE({$value}, '-', '') NOT GLOB '*[^0-9a-f]*'";
        }

        return "{$value} IS NOT NULL AND OCTET_LENGTH({$value}) = 36"
            ." AND REGEXP_LIKE({$value}, '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$', 'c')";
    }

    private function dateTime(string $value): string
    {
        // MySQL's DATETIME type validates its calendar. SQLite's TEXT affinity does not.
        return DB::getDriverName() === 'sqlite'
            ? "{$value} IS NOT NULL AND LENGTH(CAST({$value} AS BLOB)) = 19"
                ." AND STRFTIME('%Y-%m-%d %H:%M:%S', {$value}, '+0 days') = {$value}"
            : "{$value} IS NOT NULL";
    }

    private function guard(string $operation, ?string $valid = null): array
    {
        $name = 'inquiry_notification_intents_'.$operation;
        $message = 'Inquiry notification identity or transition is invalid';
        if (DB::getDriverName() === 'sqlite') {
            $when = $valid === null ? '' : ' WHEN NOT COALESCE(('.$valid.'), 0)';

            return ['operation' => strtoupper($operation), 'statement' => "CREATE TRIGGER {$name} BEFORE {$operation} ON inquiry_notification_intents{$when} BEGIN SELECT RAISE(ABORT, '{$message}'); END"];
        } else {
            $body = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}';";
            if ($valid !== null) {
                $body = "IF NOT COALESCE(({$valid}), 0) THEN {$body} END IF;";
            }

            return ['operation' => strtoupper($operation), 'statement' => "CREATE TRIGGER {$name} BEFORE {$operation} ON inquiry_notification_intents FOR EACH ROW BEGIN {$body} END", 'body' => "BEGIN {$body} END"];
        }
    }

    private function requireDriver(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new LogicException('Inquiry notification guards require SQLite or MySQL.');
        }
    }

    private function tableExists(): bool
    {
        if (DB::getDriverName() === 'mysql') {
            // I_S table-name equality can be case sensitive even when its collation is not.
            // Collect every folded match so a canonical name cannot mask a foreign sibling.
            $matches = DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::getDatabaseName())
                ->whereRaw('LOWER(TABLE_NAME) = ?', ['inquiry_notification_intents'])->get();
            if ($matches->isEmpty()) {
                return false;
            }
            if ($matches->count() !== 1 || $matches->first()->TABLE_NAME !== 'inquiry_notification_intents' || $matches->first()->TABLE_TYPE !== 'BASE TABLE') {
                $this->unexpected('table identity');
            }

            return true;
        }
        $existing = DB::table('sqlite_master')->whereRaw('name COLLATE NOCASE = ?', ['inquiry_notification_intents'])->first();
        if ($existing !== null && ($existing->name !== 'inquiry_notification_intents' || $existing->type !== 'table')) {
            $this->unexpected('table identity');
        }

        return $existing !== null;
    }

    private function refuseTemporaryShadows(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $names = ['inquiry_notification_intents', 'customer_inquiries', 'inquiry_notification_intents_insert',
                'inquiry_notification_intents_update', 'inquiry_notification_intents_delete',
                'inquiry_notification_intents_customer_inquiry_id_unique', 'inquiry_notifications_due', 'inquiry_notifications_lease'];
            if (DB::table('sqlite_temp_master')->whereIn(DB::raw('name COLLATE NOCASE'), $names)
                ->orWhereIn(DB::raw('tbl_name COLLATE NOCASE'), ['inquiry_notification_intents', 'customer_inquiries'])->exists()) {
                $this->unexpected('temporary object shadow');
            }

            return;
        }
        foreach (['inquiry_notification_intents', 'customer_inquiries'] as $table) {
            try {
                $definition = DB::selectOne('SHOW CREATE TABLE '.DB::connection()->getQueryGrammar()->wrapTable($table));
            } catch (QueryException $error) {
                if (($error->errorInfo[0] ?? null) === '42S02' && ($error->errorInfo[1] ?? null) === 1146) {
                    continue;
                }
                throw $error;
            }
            if (str_starts_with($definition->{'Create Table'} ?? '', 'CREATE TEMPORARY TABLE ')) {
                $this->unexpected('temporary table shadow');
            }
        }
    }

    /** SQLite retains the original SQL; comparison includes inline keys, checks and collations. */
    private function preflightTable(array $statements): array
    {
        if (DB::getDriverName() === 'mysql') {
            return $this->preflightMySqlTable($statements);
        }
        $sql = DB::table('sqlite_master')->where('type', 'table')->whereRaw('name COLLATE NOCASE = ?', ['inquiry_notification_intents'])->value('sql');
        if ($sql !== preg_replace('/\Acreate table /', 'CREATE TABLE ', $statements[0])) {
            $this->unexpected('table definition');
        }
        $expected = [];
        foreach (array_slice($statements, 1) as $statement) {
            if (preg_match('/\Acreate (?:unique )?index "([^"]+)" /', $statement, $match) !== 1) {
                $this->unexpected('compiled index definition');
            }
            $expected[$match[1]] = $statement;
        }
        foreach (DB::table('sqlite_master')->where('type', 'index')->whereRaw('tbl_name COLLATE NOCASE = ?', ['inquiry_notification_intents'])->get() as $index) {
            if (! isset($expected[$index->name]) || $index->sql !== preg_replace_callback('/\Acreate (unique )?index /',
                fn (array $match): string => 'CREATE '.(isset($match[1]) ? 'UNIQUE ' : '').'INDEX ', $expected[$index->name])) {
                $this->unexpected('index definition');
            }
            unset($expected[$index->name]);
        }
        // SQLite index names are database-wide, including names on an unrelated table.
        foreach (array_keys($expected) as $name) {
            if (DB::table('sqlite_master')->whereRaw('name COLLATE NOCASE = ?', [$name])->exists()) {
                $this->unexpected('foreign index identity');
            }
        }

        return array_values($expected);
    }

    private function preflightMySqlTable(array $statements): array
    {
        $database = DB::getDatabaseName();
        $collation = DB::connection()->getConfig('collation');
        $table = DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', $database)->where('TABLE_NAME', 'inquiry_notification_intents')->first();
        if ($table->ENGINE !== 'InnoDB' || $table->TABLE_COLLATION !== $collation || $table->CREATE_OPTIONS !== '' || $table->TABLE_COMMENT !== '') {
            $this->unexpected('table storage definition');
        }
        $types = [
            'id' => ['bigint unsigned', null, 'NO'], 'customer_inquiry_id' => ['bigint unsigned', null, 'NO'],
            'operator_user_id' => ['bigint unsigned', null, 'NO'], 'kind' => ['varchar(24)', 'ascii_bin', 'NO'],
            'state' => ['varchar(16)', 'ascii_bin', 'NO'], 'attempts' => ['int unsigned', null, 'NO'],
            'claim_token' => ['varchar(64)', 'ascii_bin', 'YES'], 'lease_expires_at' => ['datetime', null, 'YES'],
            'next_attempt_at' => ['datetime', null, 'YES'], 'outcome' => ['varchar(32)', 'ascii_bin', 'YES'],
            'created_at' => ['datetime', null, 'NO'], 'updated_at' => ['datetime', null, 'NO'],
        ];
        $columns = DB::table('information_schema.COLUMNS')->where('TABLE_SCHEMA', $database)->where('TABLE_NAME', 'inquiry_notification_intents')->orderBy('ORDINAL_POSITION')->get();
        if ($columns->pluck('COLUMN_NAME')->all() !== array_keys($types)) {
            $this->unexpected('column identity');
        }
        foreach ($columns as $column) {
            [$type, $columnCollation, $nullable] = $types[$column->COLUMN_NAME];
            if ($column->COLUMN_TYPE !== $type || $column->COLLATION_NAME !== $columnCollation || $column->IS_NULLABLE !== $nullable
                || $column->COLUMN_DEFAULT !== null || $column->COLUMN_COMMENT !== '' || $column->GENERATION_EXPRESSION !== ''
                || $column->EXTRA !== ($column->COLUMN_NAME === 'id' ? 'auto_increment' : '')) {
                $this->unexpected('column definition');
            }
        }
        $indexes = [
            'primary' => [['id'], true], 'inquiry_notification_intents_customer_inquiry_id_unique' => [['customer_inquiry_id'], true],
            'inquiry_notifications_due' => [['state', 'next_attempt_at', 'id'], false],
            'inquiry_notifications_lease' => [['state', 'lease_expires_at', 'id'], false],
            'inquiry_notification_intents_operator_user_id_foreign' => [['operator_user_id'], false],
            // MySQL creates this support index with the FK before the fluent unique index.
            'inquiry_notification_intents_customer_inquiry_id_foreign' => [['customer_inquiry_id'], false],
        ];
        foreach (Schema::getIndexes('inquiry_notification_intents') as $index) {
            if (! isset($indexes[$index['name']]) || [$index['columns'], $index['unique']] !== $indexes[$index['name']] || $index['type'] !== 'btree') {
                $this->unexpected('index definition');
            }
            unset($indexes[$index['name']]);
        }
        if (isset($indexes['primary'])) {
            $this->unexpected('primary key');
        }
        foreach (DB::table('information_schema.STATISTICS')->where('TABLE_SCHEMA', $database)->where('TABLE_NAME', 'inquiry_notification_intents')->get() as $part) {
            if ($part->SUB_PART !== null || $part->EXPRESSION !== null || $part->COLLATION !== 'A' || $part->IS_VISIBLE !== 'YES' || $part->INDEX_COMMENT !== '') {
                $this->unexpected('index part definition');
            }
            if ($part->INDEX_NAME !== 'PRIMARY' && $part->INDEX_NAME !== strtolower($part->INDEX_NAME)) {
                $this->unexpected('index identity');
            }
        }
        $foreign = ['customer_inquiry_id' => 'customer_inquiries', 'operator_user_id' => 'users'];
        $missingForeign = $foreign;
        foreach (Schema::getForeignKeys('inquiry_notification_intents') as $key) {
            $column = $key['columns'][0] ?? '';
            if (! isset($missingForeign[$column]) || $key['name'] !== 'inquiry_notification_intents_'.$column.'_foreign'
                || $key['columns'] !== [$column] || $key['foreign_schema'] !== $database || $key['foreign_table'] !== $foreign[$column]
                || $key['foreign_columns'] !== ['id'] || $key['on_delete'] !== 'restrict' || $key['on_update'] !== 'no action') {
                $this->unexpected('foreign key definition');
            }
            unset($missingForeign[$column]);
        }
        foreach (array_keys($foreign) as $column) {
            $uniqueSupports = $column === 'customer_inquiry_id' && ! isset($indexes['inquiry_notification_intents_customer_inquiry_id_unique']);
            if (! isset($missingForeign[$column]) && ! $uniqueSupports && isset($indexes['inquiry_notification_intents_'.$column.'_foreign'])) {
                $this->unexpected('foreign key supporting index');
            }
        }
        if (DB::table('information_schema.TABLE_CONSTRAINTS')->where('TABLE_SCHEMA', $database)->where('TABLE_NAME', 'inquiry_notification_intents')->where('CONSTRAINT_TYPE', 'CHECK')->exists()) {
            $this->unexpected('check constraint');
        }
        // Foreign key names are schema-wide. A same-name key on another table is not ours.
        foreach (array_keys($missingForeign) as $column) {
            if (DB::table('information_schema.TABLE_CONSTRAINTS')->where('CONSTRAINT_SCHEMA', $database)
                ->where('CONSTRAINT_NAME', 'inquiry_notification_intents_'.$column.'_foreign')->where('TABLE_NAME', '<>', 'inquiry_notification_intents')->exists()) {
                $this->unexpected('foreign constraint identity');
            }
        }
        $missing = [];
        foreach (array_slice($statements, 1) as $statement) {
            if (preg_match('/\b(?:constraint|index|unique) `([^`]+)`/', $statement, $match) !== 1) {
                $this->unexpected('compiled constraint definition');
            }
            $name = $match[1];
            $isForeign = str_ends_with($name, '_foreign');
            $column = $isForeign ? substr($name, strlen('inquiry_notification_intents_'), -strlen('_foreign')) : null;
            if ($isForeign ? isset($missingForeign[$column]) : isset($indexes[$name])) {
                $missing[] = $statement;
            }
        }

        return $missing;
    }

    private function preflightAbsentTableNames(array $statements): void
    {
        foreach (array_slice($statements, 1) as $statement) {
            if (DB::getDriverName() === 'sqlite') {
                if (preg_match('/\Acreate (?:unique )?index "([^"]+)" /', $statement, $match) !== 1
                    || DB::table('sqlite_master')->whereRaw('name COLLATE NOCASE = ?', [$match[1]])->exists()) {
                    $this->unexpected('foreign index identity');
                }
            } elseif (preg_match('/\bconstraint `([^`]+)`/', $statement, $match) === 1
                && DB::table('information_schema.TABLE_CONSTRAINTS')->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
                    ->where('CONSTRAINT_NAME', $match[1])->exists()) {
                $this->unexpected('foreign constraint identity');
            }
        }
    }

    private function preflightGuards(array $guards): array
    {
        $installed = [];
        foreach ($guards as $name => $definition) {
            if (DB::getDriverName() === 'sqlite') {
                $existing = DB::table('sqlite_master')->where('type', 'trigger')->whereRaw('name COLLATE NOCASE = ?', [$name])->first();
                $matches = $existing === null || ($existing->name === $name && $existing->tbl_name === 'inquiry_notification_intents' && $existing->sql === $definition['statement']);
            } else {
                $named = DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())
                    ->whereRaw('LOWER(TRIGGER_NAME) = ?', [$name])->get();
                if ($named->count() > 1) {
                    $this->unexpected('guard identity for '.$name);
                }
                $existing = $named->first();
                $matches = $existing === null || ($existing->TRIGGER_NAME === $name && $existing->EVENT_OBJECT_TABLE === 'inquiry_notification_intents' && $existing->ACTION_TIMING === 'BEFORE'
                    && $existing->EVENT_MANIPULATION === $definition['operation'] && $existing->ACTION_STATEMENT === $definition['body']);
            }
            if (! $matches) {
                $this->unexpected('guard definition for '.$name);
            }
            $installed[$name] = $existing !== null;
        }
        $names = DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('type', 'trigger')->whereRaw('tbl_name COLLATE NOCASE = ?', ['inquiry_notification_intents'])->pluck('name')->all()
            : DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->where('EVENT_OBJECT_TABLE', 'inquiry_notification_intents')->pluck('TRIGGER_NAME')->all();
        if (array_diff($names, array_keys($guards)) !== []) {
            $this->unexpected('additional table guard');
        }

        return $installed;
    }

    private function unexpected(string $part): never
    {
        throw new LogicException("Unexpected inquiry notification {$part}; existing schema, guards and evidence are unchanged. Investigate before retrying.");
    }

    /** Match the shared ownership floor while retaining this migration's interrupted-DDL recovery. */
    private function preflightExternalDependencies(): void
    {
        $table = 'inquiry_notification_intents';
        $ownedGuards = array_keys($this->guards());
        if (DB::getDriverName() === 'sqlite') {
            foreach (['main' => 'sqlite_master', 'temp' => 'sqlite_temp_master'] as $schema => $catalog) {
                foreach (DB::table($catalog)->whereIn('type', ['table', 'view', 'trigger'])->get() as $object) {
                    if ($schema === 'main' && ($object->name === $table || in_array($object->name, $ownedGuards, true))) {
                        continue;
                    }
                    if ($object->type === 'table') {
                        $name = str_replace('"', '""', $object->name);
                        foreach (DB::select('PRAGMA '.$schema.'.foreign_key_list("'.$name.'")') as $key) {
                            if (strtolower($key->table) === $table) {
                                $this->unexpected('external foreign key reference');
                            }
                        }
                    } elseif ($this->referencesTable($object->sql)) {
                        $this->unexpected('external view or trigger reference');
                    }
                }
            }

            return;
        }
        $database = DB::getDatabaseName();
        foreach (DB::table('information_schema.KEY_COLUMN_USAGE')->where('REFERENCED_TABLE_SCHEMA', $database)
            ->whereRaw('LOWER(REFERENCED_TABLE_NAME) = ?', [$table])->get() as $key) {
            if ($key->TABLE_SCHEMA !== $database || $key->TABLE_NAME !== $table) {
                $this->unexpected('external foreign key reference');
            }
        }
        foreach (DB::table('information_schema.TRIGGERS')->get() as $guard) {
            if ($guard->TRIGGER_SCHEMA === $database && in_array($guard->TRIGGER_NAME, $ownedGuards, true)) {
                continue;
            }
            if ($this->dependsOnTable($guard->TRIGGER_SCHEMA, $guard->ACTION_STATEMENT, $database)) {
                $this->unexpected('external trigger reference');
            }
        }
        foreach (DB::table('information_schema.VIEWS')->get() as $view) {
            if ($this->dependsOnTable($view->TABLE_SCHEMA, $view->VIEW_DEFINITION, $database)) {
                $this->unexpected('external view reference');
            }
        }
        foreach (DB::table('information_schema.ROUTINES')->get() as $routine) {
            if ($this->dependsOnTable($routine->ROUTINE_SCHEMA, $routine->ROUTINE_DEFINITION, $database)) {
                $this->unexpected('external routine reference');
            }
        }
    }

    /**
     * Unqualified names in a stored trigger, routine or view resolve to that object's
     * own schema, so another schema reaches this table only by naming this database
     * or through dynamic SQL. Same-named objects in a parallel schema are not dependents.
     */
    private function dependsOnTable(mixed $schema, mixed $sql, string $database): bool
    {
        if (! $this->referencesTable($sql)) {
            return false;
        }
        if (strtolower((string) $schema) === strtolower($database)) {
            return true;
        }
        foreach ([$database, 'prepare'] as $name) {
            if (preg_match('/(?<![a-z0-9_])'.preg_quote($name, '/').'(?![a-z0-9_])/i', $sql) === 1) {
                return true;
            }
        }

        return false;
    }

    private function referencesTable(mixed $sql): bool
    {
        if (! is_string($sql)) {
            $this->unexpected('unreadable dependency definition');
        }

        return preg_match('/(?<![a-z0-9_])inquiry_notification_intents(?![a-z0-9_])/i', $sql) === 1;
    }

    public function down(): void
    {
        $this->requireDriver();
        $this->refuseTemporaryShadows();
        $exists = $this->tableExists();
        if ($exists && DB::table('inquiry_notification_intents')->exists()) {
            throw new LogicException('Populated inquiry notification rollback requires an approved retention workflow.');
        }
        if ($exists) {
            $this->preflightTable($this->tableStatements());
        }
        $installed = $this->preflightGuards($this->guards());
        $this->preflightExternalDependencies();
        foreach (['insert', 'update', 'delete'] as $operation) {
            if ($installed['inquiry_notification_intents_'.$operation]) {
                DB::unprepared('DROP TRIGGER inquiry_notification_intents_'.$operation);
            }
        }
        if ($exists) {
            Schema::dropIfExists('inquiry_notification_intents');
        }
    }
};
