<?php

namespace App\Domain\Commerce\ProductionPolicy;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

/** Read-only rollback admission. A fixed object name is never proof of ownership. */
final class CapabilityMigrationOwnership
{
    public function preflight(array $definitions, array $guards): bool
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql'], true)) {
            $this->reject('database driver');
        }
        $tables = array_keys($definitions);
        $blueprints = [];
        foreach ($definitions as $table => $definition) {
            $blueprint = new Blueprint(DB::connection(), $table);
            $blueprint->create();
            $definition($blueprint);
            $blueprints[$table] = $blueprint;
        }
        if ($driver === 'sqlite') {
            return $this->sqlite($blueprints, $guards);
        }

        return $this->mysql($blueprints, $guards, $tables);
    }

    private function sqlite(array $blueprints, array $guards): bool
    {
        $statements = [];
        $names = [...array_keys($blueprints), ...array_keys($guards)];
        foreach ($blueprints as $table => $blueprint) {
            $statements[$table] = $blueprint->toSql();
            foreach (array_slice($statements[$table], 1) as $sql) {
                if (preg_match('/\Acreate (?:unique )?index "([^"]+)" /', $sql, $match) !== 1) {
                    $this->reject('compiled index');
                }
                $names[] = $match[1];
            }
        }
        foreach (DB::table('sqlite_temp_master')->get() as $object) {
            if (in_array(strtolower($object->name), $names, true) || in_array(strtolower($object->tbl_name), array_keys($blueprints), true)) {
                $this->reject('temporary object shadow');
            }
        }
        $present = [];
        foreach ($blueprints as $table => $blueprint) {
            $objects = DB::table('sqlite_master')->whereRaw('name COLLATE NOCASE = ?', [$table])->get();
            $object = $objects->first();
            if ($objects->count() > 1 || ($object !== null && ($object->type !== 'table' || $object->name !== $table
                || $object->sql !== preg_replace('/\Acreate table /', 'CREATE TABLE ', $statements[$table][0])))) {
                $this->reject('table identity or definition');
            }
            $present[$table] = $object !== null;
            $expectedIndexes = [];
            foreach (array_slice($statements[$table], 1) as $sql) {
                preg_match('/\Acreate (?:unique )?index "([^"]+)" /', $sql, $match);
                $expectedIndexes[$match[1]] = preg_replace_callback('/\Acreate (unique )?index /',
                    fn (array $match): string => 'CREATE '.(isset($match[1]) ? 'UNIQUE ' : '').'INDEX ', $sql);
            }
            foreach ($expectedIndexes as $name => $sql) {
                $objects = DB::table('sqlite_master')->whereRaw('name COLLATE NOCASE = ?', [$name])->get();
                $index = $objects->first();
                if ($objects->count() > 1 || ($present[$table] ? $index === null || $index->type !== 'index' || $index->name !== $name
                    || $index->tbl_name !== $table || $index->sql !== $sql : $index !== null)) {
                    $this->reject('index identity or definition');
                }
            }
            $indexes = DB::table('sqlite_master')->where('type', 'index')->whereRaw('tbl_name COLLATE NOCASE = ?', [$table])->pluck('name')->all();
            if (array_diff($indexes, array_keys($expectedIndexes)) !== []) {
                $this->reject('additional table index');
            }
        }
        $installed = count(array_filter($present));
        if ($installed !== 0 && $installed !== count($blueprints)) {
            $this->reject('partial table installation');
        }
        foreach ($guards as $name => $definition) {
            $objects = DB::table('sqlite_master')->whereRaw('name COLLATE NOCASE = ?', [$name])->get();
            $guard = $objects->first();
            if ($objects->count() > 1 || ($present[$definition['table']] ? $guard === null || $guard->type !== 'trigger' || $guard->name !== $name
                || $guard->tbl_name !== $definition['table'] || $guard->sql !== $definition['statement'] : $guard !== null)) {
                $this->reject('guard identity or definition');
            }
        }
        foreach ($blueprints as $table => $blueprint) {
            $all = DB::table('sqlite_master')->where('type', 'trigger')->whereRaw('tbl_name COLLATE NOCASE = ?', [$table])->pluck('name')->all();
            $expected = array_keys(array_filter($guards, fn (array $definition): bool => $definition['table'] === $table));
            if (array_diff($all, $expected) !== []) {
                $this->reject('additional table guard');
            }
        }
        foreach (['main' => 'sqlite_master', 'temp' => 'sqlite_temp_master'] as $schema => $catalog) {
            foreach (DB::table($catalog)->whereIn('type', ['table', 'view', 'trigger'])->get() as $object) {
                if ($schema === 'main' && (isset($blueprints[$object->name]) || isset($guards[$object->name]))) {
                    continue;
                }
                if ($object->type === 'table') {
                    $name = str_replace('"', '""', $object->name);
                    foreach (DB::select('PRAGMA '.$schema.'.foreign_key_list("'.$name.'")') as $key) {
                        if (in_array(strtolower($key->table), array_keys($blueprints), true)) {
                            $this->reject('external foreign key reference');
                        }
                    }
                } elseif ($this->references($object->sql, array_keys($blueprints))) {
                    $this->reject('external view or trigger reference');
                }
            }
        }

        return $installed !== 0;
    }

    private function mysql(array $blueprints, array $guards, array $tables): bool
    {
        $database = DB::getDatabaseName();
        $present = [];
        foreach ($blueprints as $table => $blueprint) {
            try {
                $shown = DB::selectOne('SHOW CREATE TABLE '.DB::connection()->getQueryGrammar()->wrapTable($table));
                if (str_starts_with($shown->{'Create Table'} ?? '', 'CREATE TEMPORARY TABLE ')) {
                    $this->reject('temporary table shadow');
                }
            } catch (QueryException $error) {
                if (($error->errorInfo[0] ?? null) !== '42S02' || ($error->errorInfo[1] ?? null) !== 1146) {
                    throw $error;
                }
            }
            $objects = DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', $database)->whereRaw('LOWER(TABLE_NAME) = ?', [$table])->get();
            $object = $objects->first();
            if ($objects->count() > 1 || ($object !== null && ($object->TABLE_NAME !== $table || $object->TABLE_TYPE !== 'BASE TABLE'
                || $object->ENGINE !== 'InnoDB' || $object->TABLE_COLLATION !== DB::connection()->getConfig('collation')
                || $object->CREATE_OPTIONS !== '' || $object->TABLE_COMMENT !== ''))) {
                $this->reject('table identity or storage definition');
            }
            $present[$table] = $object !== null;
            if ($present[$table]) {
                $this->mysqlTable($table, $blueprint);
            }
        }
        $installed = count(array_filter($present));
        if ($installed !== 0 && $installed !== count($tables)) {
            $this->reject('partial table installation');
        }
        foreach ($guards as $name => $definition) {
            $objects = DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', $database)->whereRaw('LOWER(TRIGGER_NAME) = ?', [$name])->get();
            $guard = $objects->first();
            if ($objects->count() > 1 || ($present[$definition['table']] ? $guard === null || $guard->TRIGGER_NAME !== $name
                || $guard->EVENT_OBJECT_TABLE !== $definition['table'] || $guard->ACTION_TIMING !== 'BEFORE'
                || $guard->EVENT_MANIPULATION !== $definition['operation'] || $guard->ACTION_STATEMENT !== $definition['body'] : $guard !== null)) {
                $this->reject('guard identity or definition');
            }
        }
        foreach (DB::table('information_schema.TRIGGERS')->get() as $guard) {
            if ($guard->TRIGGER_SCHEMA === $database && isset($guards[$guard->TRIGGER_NAME])) {
                continue;
            }
            if (($guard->TRIGGER_SCHEMA === $database && in_array($guard->EVENT_OBJECT_TABLE, $tables, true))
                || $this->dependsOn($guard->TRIGGER_SCHEMA, $guard->ACTION_STATEMENT, $tables, $database)) {
                $this->reject('external or additional table guard');
            }
        }
        foreach (DB::table('information_schema.KEY_COLUMN_USAGE')->where('REFERENCED_TABLE_SCHEMA', $database)->whereIn('REFERENCED_TABLE_NAME', $tables)->get() as $key) {
            if ($key->TABLE_SCHEMA !== $database || ! in_array($key->TABLE_NAME, $tables, true)) {
                $this->reject('external foreign key reference');
            }
        }
        foreach (DB::table('information_schema.VIEWS')->get() as $view) {
            if ($this->dependsOn($view->TABLE_SCHEMA, $view->VIEW_DEFINITION, $tables, $database)) {
                $this->reject('external view reference');
            }
        }
        foreach (DB::table('information_schema.ROUTINES')->get() as $routine) {
            if ($this->dependsOn($routine->ROUTINE_SCHEMA, $routine->ROUTINE_DEFINITION, $tables, $database)) {
                $this->reject('external routine reference');
            }
        }

        return $installed !== 0;
    }

    private function mysqlTable(string $table, Blueprint $blueprint): void
    {
        // Compile before inspecting commands so implied indexes/foreign keys are
        // included. Only this increment's exact columns and constraints qualify.
        $blueprint->toSql();
        $database = DB::getDatabaseName();
        $columns = DB::table('information_schema.COLUMNS')->where('TABLE_SCHEMA', $database)->where('TABLE_NAME', $table)->orderBy('ORDINAL_POSITION')->get();
        $expectedColumns = $blueprint->getColumns();
        if ($columns->pluck('COLUMN_NAME')->all() !== array_map(fn ($column): string => $column->name, $expectedColumns)) {
            $this->reject('column identity');
        }
        foreach ($expectedColumns as $position => $column) {
            $actual = $columns[$position];
            $type = match ($column->type) {
                'bigInteger' => 'bigint', 'integer' => 'int', 'string' => 'varchar('.$column->length.')', 'longText' => 'longtext', 'dateTime' => 'datetime',
                default => throw new LogicException('Unsupported owned capability column type.'),
            };
            if ($column->unsigned) {
                $type .= ' unsigned';
            }
            $charset = in_array($column->type, ['string', 'longText'], true) ? ($column->charset ?? DB::connection()->getConfig('charset')) : null;
            $collation = $charset === null ? null : ($column->collation ?? DB::connection()->getConfig('collation'));
            if ($actual->COLUMN_TYPE !== $type || $actual->CHARACTER_SET_NAME !== $charset || $actual->COLLATION_NAME !== $collation
                || $actual->IS_NULLABLE !== 'NO' || $actual->COLUMN_DEFAULT !== null || $actual->COLUMN_COMMENT !== ''
                || $actual->GENERATION_EXPRESSION !== '' || $actual->EXTRA !== ($column->autoIncrement ? 'auto_increment' : '')) {
                $this->reject('column definition');
            }
        }
        $requiredIndexes = ['primary' => [['id'], true]];
        $supportIndexes = [];
        $foreign = [];
        foreach ($blueprint->getCommands() as $command) {
            if ($command->name === 'unique') {
                $requiredIndexes[$command->index] = [$command->columns, true];
            } elseif ($command->name === 'foreign') {
                $foreign[$command->index] = ['columns' => $command->columns, 'table' => $command->on, 'references' => (array) $command->references];
                $supportIndexes[$command->index] = [$command->columns, false];
            }
        }
        foreach (Schema::getIndexes($table) as $index) {
            $expected = $requiredIndexes[$index['name']] ?? $supportIndexes[$index['name']] ?? null;
            if ($expected === null || [$index['columns'], $index['unique']] !== $expected || $index['type'] !== 'btree') {
                $this->reject('index definition');
            }
            unset($requiredIndexes[$index['name']]);
        }
        if ($requiredIndexes !== []) {
            $this->reject('missing required index');
        }
        foreach (DB::table('information_schema.STATISTICS')->where('TABLE_SCHEMA', $database)->where('TABLE_NAME', $table)->get() as $part) {
            if ($part->SUB_PART !== null || $part->EXPRESSION !== null || $part->COLLATION !== 'A' || $part->IS_VISIBLE !== 'YES' || $part->INDEX_COMMENT !== ''
                || ($part->INDEX_NAME !== 'PRIMARY' && $part->INDEX_NAME !== strtolower($part->INDEX_NAME))) {
                $this->reject('index part identity or definition');
            }
        }
        foreach (Schema::getForeignKeys($table) as $key) {
            $expected = $foreign[$key['name']] ?? null;
            if ($expected === null || $key['columns'] !== $expected['columns'] || $key['foreign_schema'] !== $database
                || $key['foreign_table'] !== $expected['table'] || $key['foreign_columns'] !== $expected['references']
                || $key['on_delete'] !== 'restrict' || $key['on_update'] !== 'no action') {
                $this->reject('foreign key definition');
            }
            unset($foreign[$key['name']]);
        }
        if ($foreign !== [] || DB::table('information_schema.TABLE_CONSTRAINTS')->where('TABLE_SCHEMA', $database)->where('TABLE_NAME', $table)->where('CONSTRAINT_TYPE', 'CHECK')->exists()) {
            $this->reject('missing or additional table constraint');
        }
    }

    /**
     * Unqualified names in a stored trigger, routine or view resolve to that object's
     * own schema, so another schema reaches these tables only by naming this database
     * or through dynamic SQL. Same-named objects in a parallel schema are not dependents.
     */
    private function dependsOn(mixed $schema, mixed $sql, array $tables, string $database): bool
    {
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
     * A regex failure refuses. The same expression is in `IdentityMigrationOwnership::qualifies()` and migration 243 `qualifiesDatabase()`.
     */
    private function qualifies(string $sql, string $database): bool
    {
        $name = preg_quote($database, '/');
        $match = preg_match('/(?:`'.$name.'`|"'.$name.'"|(?<![A-Za-z0-9_$\x{80}-\x{10FFFF}])'.$name.')(?:\s|\/\*.*?\*\/|(?:--\s|#)[^\n]*)*\./isu', $sql);
        if ($match === false) {
            $this->reject('unreadable dependency definition');
        }

        return $match === 1;
    }

    private function references(mixed $sql, array $tables): bool
    {
        if (! is_string($sql)) {
            $this->reject('unreadable dependency definition');
        }
        foreach ($tables as $table) {
            if (preg_match('/(?<![a-z0-9_])'.preg_quote($table, '/').'(?![a-z0-9_])/i', $sql) === 1) {
                return true;
            }
        }

        return false;
    }

    private function reject(string $part): never
    {
        throw new LogicException('Unexpected production capability '.$part.'; rollback refused before schema changes.');
    }
}
