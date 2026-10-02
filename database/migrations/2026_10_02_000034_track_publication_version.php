<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'tracks';

    private const COLUMN = 'publication_version';

    private const MAX_VERSION = 2147483647;

    private const INSERT_GUARD = 'tracks_publication_version_insert';

    private const UPDATE_GUARD = 'tracks_publication_version_update';

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new LogicException('Track publication revisions require SQLite or MySQL.');
        }
        $this->preflightTable();
        $hasColumn = $this->preflightColumn();
        $guards = $this->guards();
        $installed = $this->preflightGuards($guards);
        // Only the surviving creation prefix is a compatible interrupted installation.
        // Validate every owned object and retained value before any independently committed DDL.
        if ((! $hasColumn && in_array(true, $installed, true))
            || (! $installed[self::INSERT_GUARD] && $installed[self::UPDATE_GUARD])) {
            $this->unexpected('installation order');
        }
        if ($hasColumn) {
            $this->preflightColumnConstraints();
            $this->preflightValues();
        } else {
            foreach ($this->columnStatements() as $statement) {
                DB::statement($statement);
            }
        }
        foreach ($guards as $name => $definition) {
            if (! $installed[$name]) {
                DB::unprepared($definition['statement']);
            }
        }
    }

    private function columnStatements(): array
    {
        $table = new Blueprint(DB::connection(), self::TABLE);
        $table->integer(self::COLUMN)->default(0);

        return $table->toSql();
    }

    private function preflightTable(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $names = [self::TABLE, self::INSERT_GUARD, self::UPDATE_GUARD];
            if (DB::table('sqlite_temp_master')->whereIn(DB::raw('name COLLATE NOCASE'), $names)
                ->orWhereRaw('tbl_name COLLATE NOCASE = ?', [self::TABLE])->exists()) {
                $this->unexpected('temporary object shadow');
            }
            $table = DB::table('sqlite_master')->whereRaw('name COLLATE NOCASE = ?', [self::TABLE])->first();
            if ($table === null || $table->name !== self::TABLE || $table->type !== 'table') {
                $this->unexpected('table identity');
            }

            return;
        }
        $definition = DB::selectOne('SHOW CREATE TABLE '.DB::connection()->getQueryGrammar()->wrapTable(self::TABLE));
        if (str_starts_with($definition->{'Create Table'} ?? '', 'CREATE TEMPORARY TABLE ')) {
            $this->unexpected('temporary table shadow');
        }
        $tables = DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->whereRaw('LOWER(TABLE_NAME) = ?', [self::TABLE])->get();
        if ($tables->count() !== 1 || $tables->first()->TABLE_NAME !== self::TABLE
            || $tables->first()->TABLE_TYPE !== 'BASE TABLE' || $tables->first()->ENGINE !== 'InnoDB') {
            $this->unexpected('table identity');
        }
    }

    private function preflightColumn(): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            $columns = collect(DB::select("PRAGMA table_xinfo('tracks')"))
                ->filter(fn ($column) => strtolower($column->name) === self::COLUMN)->values();
            if ($columns->isEmpty()) {
                return false;
            }
            $column = $columns->first();
            if ($columns->count() !== 1 || $column->name !== self::COLUMN || $column->type !== 'INTEGER'
                || $column->notnull !== 1 || $column->dflt_value !== "'0'" || $column->pk !== 0 || $column->hidden !== 0) {
                $this->unexpected('column definition');
            }
            // PRAGMA does not reveal an extra inline CHECK or REFERENCES clause. Require the exact
            // fragment our additive ALTER emits, while leaving every existing track definition intact.
            $statements = $this->columnStatements();
            $prefix = 'alter table "tracks" add column ';
            if (count($statements) !== 1 || ! str_starts_with($statements[0], $prefix)) {
                $this->unexpected('compiled column definition');
            }
            $fragment = substr($statements[0], strlen($prefix));
            $sql = DB::table('sqlite_master')->where('type', 'table')->where('name', self::TABLE)->value('sql');
            $pattern = '/[(,]\s*'.preg_quote($fragment, '/').'\s*(?=[,)])/';
            if (! is_string($sql) || preg_match($pattern, $sql) !== 1
                || preg_match('/(?<![a-z0-9_])publication_version(?![a-z0-9_])/i', preg_replace($pattern, '', $sql, 1)) === 1) {
                $this->unexpected('column constraint definition');
            }

            return true;
        }
        $columns = DB::table('information_schema.COLUMNS')->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', self::TABLE)->whereRaw('LOWER(COLUMN_NAME) = ?', [self::COLUMN])->get();
        if ($columns->isEmpty()) {
            return false;
        }
        $column = $columns->first();
        if ($columns->count() !== 1 || $column->COLUMN_NAME !== self::COLUMN || $column->COLUMN_TYPE !== 'int'
            || $column->IS_NULLABLE !== 'NO' || (string) $column->COLUMN_DEFAULT !== '0' || $column->COLLATION_NAME !== null
            || $column->COLUMN_COMMENT !== '' || $column->GENERATION_EXPRESSION !== '' || $column->EXTRA !== '') {
            $this->unexpected('column definition');
        }
        if (DB::table('information_schema.KEY_COLUMN_USAGE')->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', self::TABLE)->where('COLUMN_NAME', self::COLUMN)->exists()) {
            $this->unexpected('column constraint definition');
        }
        // No CHECK constraint belongs to this increment; constraints naming its owned column
        // would be an unreviewed schema rather than an interrupted instance of this migration.
        foreach (DB::table('information_schema.CHECK_CONSTRAINTS AS checks')
            ->join('information_schema.TABLE_CONSTRAINTS AS constraints', function ($join): void {
                $join->on('constraints.CONSTRAINT_SCHEMA', '=', 'checks.CONSTRAINT_SCHEMA')
                    ->on('constraints.CONSTRAINT_NAME', '=', 'checks.CONSTRAINT_NAME');
            })->where('constraints.TABLE_SCHEMA', DB::getDatabaseName())->where('constraints.TABLE_NAME', self::TABLE)
            ->get(['checks.CHECK_CLAUSE']) as $constraint) {
            if (preg_match('/(?<![a-z0-9_])publication_version(?![a-z0-9_])/i', $constraint->CHECK_CLAUSE) === 1) {
                $this->unexpected('column check definition');
            }
        }

        return true;
    }

    private function preflightColumnConstraints(): void
    {
        foreach ([...Schema::getIndexes(self::TABLE), ...Schema::getForeignKeys(self::TABLE)] as $constraint) {
            if (in_array(self::COLUMN, $constraint['columns'], true)) {
                $this->unexpected('column index or foreign key definition');
            }
        }
    }

    private function guards(): array
    {
        $column = self::COLUMN;
        $max = self::MAX_VERSION;
        $valid = "NEW.{$column} IS NOT NULL AND NEW.{$column} >= 0 AND NEW.{$column} <= {$max}";
        if (DB::getDriverName() === 'sqlite') {
            $valid = "typeof(NEW.{$column}) = 'integer' AND {$valid}";
        }
        $guards = [];
        foreach ([self::INSERT_GUARD => 'INSERT', self::UPDATE_GUARD => 'UPDATE'] as $name => $operation) {
            $condition = $valid.($operation === 'UPDATE' ? " AND NEW.{$column} >= OLD.{$column}" : '');
            $message = 'Track publication revision is invalid or cannot decrease';
            if (DB::getDriverName() === 'sqlite') {
                $guards[$name] = ['operation' => $operation,
                    'statement' => "CREATE TRIGGER {$name} BEFORE {$operation} ON tracks WHEN NOT COALESCE(({$condition}), 0) BEGIN SELECT RAISE(ABORT, '{$message}'); END"];
            } else {
                $body = "BEGIN IF NOT COALESCE(({$condition}), 0) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END IF; END";
                $guards[$name] = ['operation' => $operation, 'body' => $body,
                    'statement' => "CREATE TRIGGER {$name} BEFORE {$operation} ON tracks FOR EACH ROW {$body}"];
            }
        }

        return $guards;
    }

    private function preflightGuards(array $guards): array
    {
        $installed = [];
        foreach ($guards as $name => $definition) {
            if (DB::getDriverName() === 'sqlite') {
                $named = DB::table('sqlite_master')->whereRaw('name COLLATE NOCASE = ?', [$name])->get();
                $existing = $named->first();
                $matches = $named->count() <= 1 && ($existing === null || ($existing->type === 'trigger'
                    && $existing->name === $name && $existing->tbl_name === self::TABLE && $existing->sql === $definition['statement']));
            } else {
                $named = DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())
                    ->whereRaw('LOWER(TRIGGER_NAME) = ?', [$name])->get();
                $existing = $named->first();
                $matches = $named->count() <= 1 && ($existing === null || ($existing->TRIGGER_NAME === $name
                    && $existing->EVENT_OBJECT_TABLE === self::TABLE && $existing->ACTION_TIMING === 'BEFORE'
                    && $existing->EVENT_MANIPULATION === $definition['operation'] && $existing->ACTION_STATEMENT === $definition['body']));
            }
            if (! $matches) {
                $this->unexpected('guard identity or definition for '.$name);
            }
            $installed[$name] = $existing !== null;
        }

        return $installed;
    }

    private function preflightValues(): void
    {
        $column = self::COLUMN;
        $query = DB::table(self::TABLE)->whereNull($column)->orWhere($column, '<', 0)->orWhere($column, '>', self::MAX_VERSION);
        if (DB::getDriverName() === 'sqlite') {
            $query->orWhereRaw("typeof({$column}) <> 'integer'");
        }
        if ($query->exists()) {
            $this->unexpected('retained revision values');
        }
    }

    private function unexpected(string $part): never
    {
        throw new LogicException("Unexpected track publication {$part}; existing schema and data are unchanged. Investigate before retrying.");
    }

    public function down(): void
    {
        // Counter history must survive interface rollback. Never remove its range/monotonic guards,
        // reset observed revisions, or erase the publication audits that refer to them.
    }
};
