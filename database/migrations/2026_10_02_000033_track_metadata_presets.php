<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'track_metadata_presets';

    private const ARCHIVE_INDEX = 'track_metadata_presets_archived_at_index';

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'sqlite'], true)) {
            throw new LogicException('Track metadata presets require MySQL or SQLite.');
        }
        $this->refuseTemporaryShadows();
        $statements = $this->tableStatements();
        $exists = $this->tableExists();
        $missing = $exists ? $this->preflightTable($statements) : $statements;
        if (! $exists && DB::getDriverName() === 'sqlite'
            && DB::table('sqlite_master')->whereRaw('name COLLATE NOCASE = ?', [self::ARCHIVE_INDEX])->exists()) {
            $this->unexpected('foreign index identity');
        }
        // MySQL commits CREATE TABLE and its index separately. Validate every surviving
        // owned part before resuming a prefix or retrying after a missing migration journal entry.
        foreach ($missing as $statement) {
            DB::statement($statement);
        }
    }

    private function tableStatements(): array
    {
        $table = new Blueprint(DB::connection(), self::TABLE);
        $table->create();
        $table->id();
        $table->string('name');
        $table->json('metadata');
        $table->unsignedInteger('version')->default(1);
        $table->timestamp('archived_at')->nullable()->index();
        $table->timestamps();

        return $table->toSql();
    }

    private function tableExists(): bool
    {
        if (DB::getDriverName() === 'mysql') {
            $matches = DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::getDatabaseName())
                ->whereRaw('LOWER(TABLE_NAME) = ?', [self::TABLE])->get();
            if ($matches->isEmpty()) {
                return false;
            }
            if ($matches->count() !== 1 || $matches->first()->TABLE_NAME !== self::TABLE || $matches->first()->TABLE_TYPE !== 'BASE TABLE') {
                $this->unexpected('table identity');
            }

            return true;
        }
        $existing = DB::table('sqlite_master')->whereRaw('name COLLATE NOCASE = ?', [self::TABLE])->first();
        if ($existing !== null && ($existing->name !== self::TABLE || $existing->type !== 'table')) {
            $this->unexpected('table identity');
        }

        return $existing !== null;
    }

    private function refuseTemporaryShadows(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            if (DB::table('sqlite_temp_master')->whereIn(DB::raw('name COLLATE NOCASE'), [self::TABLE, self::ARCHIVE_INDEX])
                ->orWhereRaw('tbl_name COLLATE NOCASE = ?', [self::TABLE])->exists()) {
                $this->unexpected('temporary object shadow');
            }

            return;
        }
        try {
            $definition = DB::selectOne('SHOW CREATE TABLE '.DB::connection()->getQueryGrammar()->wrapTable(self::TABLE));
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

    private function preflightTable(array $statements): array
    {
        if (DB::getDriverName() === 'mysql') {
            return $this->preflightMySqlTable($statements);
        }
        $sql = DB::table('sqlite_master')->where('type', 'table')->where('name', self::TABLE)->value('sql');
        if ($sql !== preg_replace('/\Acreate table /', 'CREATE TABLE ', $statements[0])) {
            $this->unexpected('table definition');
        }
        if (DB::table('sqlite_master')->where('type', 'trigger')->whereRaw('tbl_name COLLATE NOCASE = ?', [self::TABLE])->exists()) {
            $this->unexpected('additional table trigger');
        }
        $expected = [];
        foreach (array_slice($statements, 1) as $statement) {
            if (preg_match('/\Acreate index "([^"]+)" /', $statement, $match) !== 1 || $match[1] !== self::ARCHIVE_INDEX) {
                $this->unexpected('compiled index definition');
            }
            $expected[$match[1]] = $statement;
        }
        foreach (DB::table('sqlite_master')->where('type', 'index')->whereRaw('tbl_name COLLATE NOCASE = ?', [self::TABLE])->get() as $index) {
            if (! isset($expected[$index->name]) || $index->sql !== preg_replace('/\Acreate index /', 'CREATE INDEX ', $expected[$index->name])) {
                $this->unexpected('index definition');
            }
            unset($expected[$index->name]);
        }
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
        $table = DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', $database)->where('TABLE_NAME', self::TABLE)->first();
        if ($table->ENGINE !== 'InnoDB' || $table->TABLE_COLLATION !== $collation || $table->CREATE_OPTIONS !== '' || $table->TABLE_COMMENT !== '') {
            $this->unexpected('table storage definition');
        }
        $types = [
            'id' => ['bigint unsigned', null, 'NO'], 'name' => ['varchar(255)', $collation, 'NO'],
            'metadata' => ['json', null, 'NO'], 'version' => ['int unsigned', null, 'NO'],
            'archived_at' => ['timestamp', null, 'YES'], 'created_at' => ['timestamp', null, 'YES'], 'updated_at' => ['timestamp', null, 'YES'],
        ];
        $columns = DB::table('information_schema.COLUMNS')->where('TABLE_SCHEMA', $database)->where('TABLE_NAME', self::TABLE)->orderBy('ORDINAL_POSITION')->get();
        if ($columns->pluck('COLUMN_NAME')->all() !== array_keys($types)) {
            $this->unexpected('column identity');
        }
        foreach ($columns as $column) {
            [$type, $columnCollation, $nullable] = $types[$column->COLUMN_NAME];
            $defaultMatches = $column->COLUMN_NAME === 'version' ? (string) $column->COLUMN_DEFAULT === '1' : $column->COLUMN_DEFAULT === null;
            if ($column->COLUMN_TYPE !== $type || $column->COLLATION_NAME !== $columnCollation || $column->IS_NULLABLE !== $nullable || ! $defaultMatches
                || $column->COLUMN_COMMENT !== '' || $column->GENERATION_EXPRESSION !== '' || $column->EXTRA !== ($column->COLUMN_NAME === 'id' ? 'auto_increment' : '')) {
                $this->unexpected('column definition');
            }
        }
        $expectedIndexes = ['primary' => [['id'], true], self::ARCHIVE_INDEX => [['archived_at'], false]];
        foreach (Schema::getIndexes(self::TABLE) as $index) {
            if (! isset($expectedIndexes[$index['name']]) || [$index['columns'], $index['unique']] !== $expectedIndexes[$index['name']] || $index['type'] !== 'btree') {
                $this->unexpected('index definition');
            }
            unset($expectedIndexes[$index['name']]);
        }
        if (isset($expectedIndexes['primary'])) {
            $this->unexpected('primary key');
        }
        foreach (DB::table('information_schema.STATISTICS')->where('TABLE_SCHEMA', $database)->where('TABLE_NAME', self::TABLE)->get() as $part) {
            if (! in_array($part->INDEX_NAME, ['PRIMARY', self::ARCHIVE_INDEX], true) || $part->SUB_PART !== null || $part->EXPRESSION !== null
                || $part->COLLATION !== 'A' || $part->IS_VISIBLE !== 'YES' || $part->INDEX_COMMENT !== '') {
                $this->unexpected('index part definition');
            }
        }
        if (Schema::getForeignKeys(self::TABLE) !== []
            || DB::table('information_schema.TABLE_CONSTRAINTS')->where('TABLE_SCHEMA', $database)->where('TABLE_NAME', self::TABLE)->where('CONSTRAINT_TYPE', 'CHECK')->exists()
            || DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', $database)->where('EVENT_OBJECT_TABLE', self::TABLE)->exists()) {
            $this->unexpected('additional constraint or trigger');
        }
        if ($expectedIndexes === []) {
            return [];
        }
        if (count($statements) !== 2 || ! str_contains($statements[1], '`'.self::ARCHIVE_INDEX.'`')) {
            $this->unexpected('compiled index definition');
        }

        return [$statements[1]];
    }

    private function unexpected(string $part): never
    {
        throw new LogicException("Unexpected track metadata preset {$part}; existing schema and data are unchanged. Investigate before retrying.");
    }

    public function down(): void
    {
        // Removing the UI/service must not erase retained reusable metadata or its audit subjects.
        // A deliberate data-removal migration would require a separate reviewed retention decision.
    }
};
