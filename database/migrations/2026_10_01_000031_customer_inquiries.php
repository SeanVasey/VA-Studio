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
        if (! $exists) {
            $this->preflightAbsentTableNames($statements);
        } elseif (($missing !== [] || in_array(false, $installed, true)) && DB::table('customer_inquiries')->exists()) {
            throw new LogicException('Unprotected retained inquiries require investigation; missing protections cannot be retroactively installed.');
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
        $table = new Blueprint(DB::connection(), 'customer_inquiries');
        $table->create();
        $collation = DB::getDriverName() === 'mysql' ? 'ascii_bin' : 'BINARY';
        $table->id();
        $table->char('public_id', 36)->collation($collation)->unique();
        $table->char('owner_hash', 64)->collation($collation);
        $table->char('request_key', 36)->collation($collation)->unique();
        $table->char('payload_hash', 64)->collation($collation);
        $table->longText('payload');
        $table->text('privacy_notice');
        $table->char('privacy_notice_hash', 64)->collation($collation);
        $table->string('retention_policy_reference', 120);
        $table->foreignId('operator_user_id')->constrained('users')->restrictOnDelete();
        $table->foreignId('site_release_id')->constrained('site_releases')->restrictOnDelete();
        $table->char('site_content_hash', 64)->collation($collation);
        $table->string('state', 16)->collation($collation);
        $table->unsignedInteger('version');
        $table->dateTime('created_at');
        $table->dateTime('updated_at');
        $table->unique(['owner_hash', 'request_key']);
        $table->index(['state', 'id']);

        return $table->toSql();
    }

    private function guards(): array
    {
        $same = array_map(fn (string $column): string => DB::getDriverName() === 'mysql'
            ? "BINARY NEW.{$column} = BINARY OLD.{$column}" : "NEW.{$column} IS OLD.{$column}", [
                'id', 'public_id', 'owner_hash', 'request_key', 'payload_hash', 'payload', 'privacy_notice',
                'privacy_notice_hash', 'retention_policy_reference', 'operator_user_id', 'site_release_id', 'site_content_hash', 'created_at',
            ]);
        // MySQL ascii_bin is PAD SPACE: VARCHAR values need binary operands to reject padded states.
        $newState = DB::getDriverName() === 'mysql' ? 'BINARY NEW.state' : 'NEW.state';
        $oldState = DB::getDriverName() === 'mysql' ? 'BINARY OLD.state' : 'OLD.state';

        return [
            'customer_inquiries_insert' => $this->guard('insert', "{$newState} = 'new' AND NEW.version = 0"),
            'customer_inquiries_update' => $this->guard('update', implode(' AND ', $same).' AND NEW.version = OLD.version + 1 AND NEW.version BETWEEN 1 AND 2147483646'
                ." AND (({$oldState} = 'new' AND {$newState} IN ('read', 'archived')) OR ({$oldState} = 'read' AND {$newState} = 'archived')) AND NEW.updated_at >= OLD.updated_at"),
            'customer_inquiries_delete' => $this->guard('delete'),
        ];
    }

    private function guard(string $operation, ?string $valid = null): array
    {
        $name = 'customer_inquiries_'.$operation;
        if (DB::getDriverName() === 'sqlite') {
            $when = $valid === null ? '' : ' WHEN NOT COALESCE(('.$valid.'), 0)';

            return ['operation' => strtoupper($operation), 'statement' => "CREATE TRIGGER {$name} BEFORE {$operation} ON customer_inquiries{$when} BEGIN SELECT RAISE(ABORT, 'Inquiry evidence or transition is invalid'); END"];
        } else {
            $body = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Inquiry evidence or transition is invalid';";
            if ($valid !== null) {
                $body = "IF NOT COALESCE(({$valid}), 0) THEN {$body} END IF;";
            }
            $body = "BEGIN {$body} END";

            return ['operation' => strtoupper($operation), 'statement' => "CREATE TRIGGER {$name} BEFORE {$operation} ON customer_inquiries FOR EACH ROW {$body}", 'body' => $body];
        }
    }

    private function requireDriver(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new LogicException('Inquiry guards require SQLite or MySQL.');
        }
    }

    private function tableExists(): bool
    {
        $existing = DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->whereRaw('name COLLATE NOCASE = ?', ['customer_inquiries'])->first()
            : DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', 'customer_inquiries')->first();
        if ($existing !== null && (DB::getDriverName() === 'sqlite' ? ($existing->name !== 'customer_inquiries' || $existing->type !== 'table')
            : ($existing->TABLE_NAME !== 'customer_inquiries' || $existing->TABLE_TYPE !== 'BASE TABLE'))) {
            $this->unexpected('table identity');
        }

        return $existing !== null;
    }

    private function refuseTemporaryShadows(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $names = ['customer_inquiries', 'customer_inquiries_insert', 'customer_inquiries_update', 'customer_inquiries_delete',
                'customer_inquiries_public_id_unique', 'customer_inquiries_request_key_unique', 'customer_inquiries_owner_hash_request_key_unique',
                'customer_inquiries_state_id_index'];
            if (DB::table('sqlite_temp_master')->whereIn(DB::raw('name COLLATE NOCASE'), $names)
                ->orWhereRaw('tbl_name COLLATE NOCASE = ?', ['customer_inquiries'])->exists()) {
                $this->unexpected('temporary object shadow');
            }

            return;
        }
        try {
            $definition = DB::selectOne('SHOW CREATE TABLE '.DB::connection()->getQueryGrammar()->wrapTable('customer_inquiries'));
        } catch (QueryException $error) {
            if (($error->errorInfo[0] ?? null) === '42S02' && ($error->errorInfo[1] ?? null) === 1146) {
                return;
            }
            throw $error;
        }
        if (str_starts_with($definition->{'Create Table'} ?? '', 'CREATE TEMPORARY TABLE ')) {
            $this->unexpected('temporary table shadow');
        }
    }

    /** SQLite retains the original SQL; comparison includes inline keys, checks and collations. */
    private function preflightTable(array $statements): array
    {
        if (DB::getDriverName() === 'mysql') {
            return $this->preflightMySqlTable($statements);
        }
        $sql = DB::table('sqlite_master')->where('type', 'table')->whereRaw('name COLLATE NOCASE = ?', ['customer_inquiries'])->value('sql');
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
        foreach (DB::table('sqlite_master')->where('type', 'index')->whereRaw('tbl_name COLLATE NOCASE = ?', ['customer_inquiries'])->get() as $index) {
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
        $table = DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', $database)->where('TABLE_NAME', 'customer_inquiries')->first();
        if ($table->ENGINE !== 'InnoDB' || $table->TABLE_COLLATION !== $collation || $table->CREATE_OPTIONS !== '' || $table->TABLE_COMMENT !== '') {
            $this->unexpected('table storage definition');
        }
        $types = [
            'id' => ['bigint unsigned', null], 'public_id' => ['char(36)', 'ascii_bin'], 'owner_hash' => ['char(64)', 'ascii_bin'],
            'request_key' => ['char(36)', 'ascii_bin'], 'payload_hash' => ['char(64)', 'ascii_bin'], 'payload' => ['longtext', $collation],
            'privacy_notice' => ['text', $collation], 'privacy_notice_hash' => ['char(64)', 'ascii_bin'],
            'retention_policy_reference' => ['varchar(120)', $collation], 'operator_user_id' => ['bigint unsigned', null],
            'site_release_id' => ['bigint unsigned', null], 'site_content_hash' => ['char(64)', 'ascii_bin'],
            'state' => ['varchar(16)', 'ascii_bin'], 'version' => ['int unsigned', null], 'created_at' => ['datetime', null], 'updated_at' => ['datetime', null],
        ];
        $columns = DB::table('information_schema.COLUMNS')->where('TABLE_SCHEMA', $database)->where('TABLE_NAME', 'customer_inquiries')->orderBy('ORDINAL_POSITION')->get();
        if ($columns->pluck('COLUMN_NAME')->all() !== array_keys($types)) {
            $this->unexpected('column identity');
        }
        foreach ($columns as $column) {
            [$type, $columnCollation] = $types[$column->COLUMN_NAME];
            if ($column->COLUMN_TYPE !== $type || $column->COLLATION_NAME !== $columnCollation || $column->IS_NULLABLE !== 'NO'
                || $column->COLUMN_DEFAULT !== null || $column->COLUMN_COMMENT !== '' || $column->GENERATION_EXPRESSION !== ''
                || $column->EXTRA !== ($column->COLUMN_NAME === 'id' ? 'auto_increment' : '')) {
                $this->unexpected('column definition');
            }
        }
        $indexes = [
            'primary' => [['id'], true], 'customer_inquiries_public_id_unique' => [['public_id'], true],
            'customer_inquiries_request_key_unique' => [['request_key'], true],
            'customer_inquiries_owner_hash_request_key_unique' => [['owner_hash', 'request_key'], true],
            'customer_inquiries_state_id_index' => [['state', 'id'], false],
            'customer_inquiries_operator_user_id_foreign' => [['operator_user_id'], false],
            'customer_inquiries_site_release_id_foreign' => [['site_release_id'], false],
        ];
        foreach (Schema::getIndexes('customer_inquiries') as $index) {
            if (! isset($indexes[$index['name']]) || [$index['columns'], $index['unique']] !== $indexes[$index['name']] || $index['type'] !== 'btree') {
                $this->unexpected('index definition');
            }
            unset($indexes[$index['name']]);
        }
        if (isset($indexes['primary'])) {
            $this->unexpected('primary key');
        }
        foreach (DB::table('information_schema.STATISTICS')->where('TABLE_SCHEMA', $database)->where('TABLE_NAME', 'customer_inquiries')->get() as $part) {
            if ($part->SUB_PART !== null || $part->EXPRESSION !== null || $part->COLLATION !== 'A' || $part->IS_VISIBLE !== 'YES' || $part->INDEX_COMMENT !== '') {
                $this->unexpected('index part definition');
            }
            if ($part->INDEX_NAME !== 'PRIMARY' && $part->INDEX_NAME !== strtolower($part->INDEX_NAME)) {
                $this->unexpected('index identity');
            }
        }
        $foreign = ['operator_user_id' => 'users', 'site_release_id' => 'site_releases'];
        $missingForeign = $foreign;
        foreach (Schema::getForeignKeys('customer_inquiries') as $key) {
            $column = $key['columns'][0] ?? '';
            if (! isset($missingForeign[$column]) || $key['name'] !== 'customer_inquiries_'.$column.'_foreign'
                || $key['columns'] !== [$column] || $key['foreign_schema'] !== $database || $key['foreign_table'] !== $foreign[$column]
                || $key['foreign_columns'] !== ['id'] || $key['on_delete'] !== 'restrict' || $key['on_update'] !== 'no action') {
                $this->unexpected('foreign key definition');
            }
            unset($missingForeign[$column]);
        }
        foreach (array_keys($foreign) as $column) {
            if (! isset($missingForeign[$column]) && isset($indexes['customer_inquiries_'.$column.'_foreign'])) {
                $this->unexpected('foreign key supporting index');
            }
        }
        if (DB::table('information_schema.TABLE_CONSTRAINTS')->where('TABLE_SCHEMA', $database)->where('TABLE_NAME', 'customer_inquiries')->where('CONSTRAINT_TYPE', 'CHECK')->exists()) {
            $this->unexpected('check constraint');
        }
        // Foreign key names are schema-wide. A same-name key on another table is not ours.
        foreach (array_keys($missingForeign) as $column) {
            if (DB::table('information_schema.TABLE_CONSTRAINTS')->where('CONSTRAINT_SCHEMA', $database)
                ->where('CONSTRAINT_NAME', 'customer_inquiries_'.$column.'_foreign')->where('TABLE_NAME', '<>', 'customer_inquiries')->exists()) {
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
            $column = $isForeign ? substr($name, strlen('customer_inquiries_'), -strlen('_foreign')) : null;
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
                $matches = $existing === null || ($existing->name === $name && $existing->tbl_name === 'customer_inquiries' && $existing->sql === $definition['statement']);
            } else {
                $existing = DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->where('TRIGGER_NAME', $name)->first();
                $matches = $existing === null || ($existing->TRIGGER_NAME === $name && $existing->EVENT_OBJECT_TABLE === 'customer_inquiries' && $existing->ACTION_TIMING === 'BEFORE'
                    && $existing->EVENT_MANIPULATION === $definition['operation'] && $existing->ACTION_STATEMENT === $definition['body']);
            }
            if (! $matches) {
                $this->unexpected('guard definition for '.$name);
            }
            $installed[$name] = $existing !== null;
        }
        $names = DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('type', 'trigger')->whereRaw('tbl_name COLLATE NOCASE = ?', ['customer_inquiries'])->pluck('name')->all()
            : DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->where('EVENT_OBJECT_TABLE', 'customer_inquiries')->pluck('TRIGGER_NAME')->all();
        if (array_diff($names, array_keys($guards)) !== []) {
            $this->unexpected('additional table guard');
        }

        return $installed;
    }

    private function unexpected(string $part): never
    {
        throw new LogicException("Unexpected inquiry {$part}; existing schema, guards and evidence are unchanged. Investigate before retrying.");
    }

    public function down(): void
    {
        $this->requireDriver();
        $this->refuseTemporaryShadows();
        $exists = $this->tableExists();
        if ($exists && DB::table('customer_inquiries')->exists()) {
            throw new LogicException('Populated inquiry rollback requires an approved retention workflow.');
        }
        if ($exists) {
            $this->preflightTable($this->tableStatements());
        }
        $installed = $this->preflightGuards($this->guards());
        foreach (['insert', 'update', 'delete'] as $operation) {
            if ($installed['customer_inquiries_'.$operation]) {
                DB::unprepared('DROP TRIGGER customer_inquiries_'.$operation);
            }
        }
        if ($exists) {
            Schema::dropIfExists('customer_inquiries');
        }
    }
};
