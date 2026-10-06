<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['inquiry_order_contexts'];

    public function up(): void
    {
        $this->preflight();
        foreach (self::TABLES as $name) {
            foreach ($this->statements($name) as $statement) {
                DB::statement($statement);
            }
        }
        foreach ($this->guards() as $statement) {
            DB::unprepared($statement);
        }
    }

    private function statements(string $name): array
    {
        $table = new Blueprint(DB::connection(), $name);
        $table->create();
        $table->id();
        $table->foreignId('inquiry_id')->unique()->constrained('customer_inquiries')->restrictOnDelete();
        $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
        $table->unsignedSmallInteger('schema_version');
        $table->char('order_hash', 64);
        $table->char('inquiry_hash', 64);
        $table->char('context_hash', 64);
        $table->dateTime('created_at');
        $table->index('order_id');

        return $table->toSql();
    }

    private function guards(): array
    {
        $sqlite = DB::getDriverName() === 'sqlite';
        $hex = fn ($field) => $sqlite ? "length(NEW.$field) != 64 OR length(CAST(NEW.$field AS BLOB)) != 64 OR NEW.$field GLOB '*[^0-9a-f]*'"
            : "OCTET_LENGTH(NEW.$field) != 64 OR NOT REGEXP_LIKE(NEW.$field, '^[0-9a-f]{64}$', 'c')";
        $same = fn ($a, $b) => $sqlite ? "$a = $b COLLATE BINARY" : "BINARY $a = BINARY $b";
        $date = $sqlite ? "NEW.created_at IS NULL OR datetime(NEW.created_at, '+0 seconds') IS NULL OR NEW.created_at != strftime('%Y-%m-%d %H:%M:%S', NEW.created_at, '+0 seconds')" : 'NEW.created_at IS NULL';
        $valid = 'NEW.schema_version = 1 AND NOT ('.$hex('order_hash').' OR '.$hex('inquiry_hash').' OR '.$hex('context_hash')." OR $date)"
            .' AND EXISTS (SELECT 1 FROM customer_inquiries i WHERE i.id=NEW.inquiry_id AND '.$same('i.payload_hash', 'NEW.inquiry_hash')." AND i.state='new' AND i.version=0 AND i.created_at=NEW.created_at)"
            .' AND EXISTS (SELECT 1 FROM orders o WHERE o.id=NEW.order_id AND '.$same('o.payload_hash', 'NEW.order_hash').')'
            .' AND NOT EXISTS (SELECT 1 FROM inquiry_order_contexts WHERE id=NEW.id OR inquiry_id=NEW.inquiry_id)';
        $guards = [];
        foreach (['insert' => "NOT COALESCE(($valid), 0)", 'update' => '1=1', 'delete' => '1=1'] as $operation => $when) {
            $name = 'inquiry_order_contexts_'.$operation;
            $guards[$name] = $sqlite
                ? "CREATE TRIGGER $name BEFORE $operation ON inquiry_order_contexts WHEN $when BEGIN SELECT RAISE(ABORT, 'Order inquiry context is retained'); END"
                : "CREATE TRIGGER $name BEFORE $operation ON inquiry_order_contexts FOR EACH ROW BEGIN IF $when THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order inquiry context is retained'; END IF; END";
        }

        return $guards;
    }

    /** Refuse partial/foreign/temporary objects before the first DDL statement. Never adopt existing evidence. */
    private function preflight(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new LogicException('Order inquiry contexts require MySQL or SQLite.');
        }
        foreach (self::TABLES as $table) {
            if (DB::getDriverName() === 'sqlite') {
                foreach (['sqlite_master', 'sqlite_temp_master'] as $schema) {
                    if (DB::table($schema)->whereRaw('name COLLATE NOCASE = ?', [$table])->orWhereRaw('tbl_name COLLATE NOCASE = ?', [$table])->exists()) {
                        throw new LogicException('Existing order inquiry context objects require investigation.');
                    }
                }
            } else {
                if (DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::getDatabaseName())->whereRaw('LOWER(TABLE_NAME) = ?', [$table])->exists()) {
                    throw new LogicException('Existing order inquiry context objects require investigation.');
                }
                try {
                    DB::selectOne('SHOW CREATE TABLE '.$table);
                    throw new LogicException('Existing or temporary order inquiry context objects refused.');
                } catch (QueryException $error) {
                    if (($error->errorInfo[0] ?? null) !== '42S02' || ($error->errorInfo[1] ?? null) !== 1146) {
                        throw $error;
                    }
                }
            }
            foreach ($this->statements($table) as $statement) {
                if (DB::getDriverName() === 'sqlite' && preg_match('/\Acreate (?:unique )?index "([^"]+)" /', $statement, $match)) {
                    foreach (['sqlite_master', 'sqlite_temp_master'] as $schema) {
                        if (DB::table($schema)->whereRaw('name COLLATE NOCASE = ?', [$match[1]])->exists()) {
                            throw new LogicException('Foreign order inquiry context index refused.');
                        }
                    }
                }
                if (DB::getDriverName() === 'mysql' && preg_match('/\bconstraint `([^`]+)`/', $statement, $match)
                    && DB::table('information_schema.TABLE_CONSTRAINTS')->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())->whereRaw('LOWER(CONSTRAINT_NAME) = ?', [$match[1]])->exists()) {
                    throw new LogicException('Foreign order inquiry context constraint refused.');
                }
            }
        }
        foreach (array_keys($this->guards()) as $name) {
            if (DB::getDriverName() === 'sqlite') {
                foreach (['sqlite_master', 'sqlite_temp_master'] as $schema) {
                    if (DB::table($schema)->whereRaw('name COLLATE NOCASE = ?', [$name])->exists()) {
                        throw new LogicException('Foreign order inquiry context guard refused.');
                    }
                }
            } elseif (DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->whereRaw('LOWER(TRIGGER_NAME) = ?', [$name])->exists()) {
                throw new LogicException('Foreign order inquiry context guard refused.');
            }
        }
    }

    public function down(): void
    {
        $this->rollbackPreflight();
        $present = array_map(fn ($table) => Schema::hasTable($table), self::TABLES);
        if (count(array_unique($present)) !== 1) {
            throw new LogicException('Partial order inquiry context schema prevents rollback.');
        }
        if ($present[0]) {
            $this->ownedSchema();
            $this->refuseExternalReferences();
            foreach (self::TABLES as $table) {
                if (DB::table($table)->exists()) {
                    throw new LogicException('Retained order inquiry evidence prevents rollback.');
                }
            }
        }
        foreach ($this->guards() as $name => $sql) {
            $table = substr($name, 0, strrpos($name, '_'));
            $operation = strtoupper(substr($name, strrpos($name, '_') + 1));
            $trigger = DB::getDriverName() === 'sqlite'
                ? DB::table('sqlite_master')->whereRaw('name COLLATE NOCASE = ?', [$name])->first()
                : DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->whereRaw('LOWER(TRIGGER_NAME) = ?', [$name])->first();
            $matches = $present[0] && $trigger && (DB::getDriverName() === 'sqlite'
                ? $trigger->type === 'trigger' && $trigger->name === $name && $trigger->tbl_name === $table && $trigger->sql === $sql
                : $trigger->TRIGGER_NAME === $name && $trigger->EVENT_OBJECT_TABLE === $table && $trigger->ACTION_TIMING === 'BEFORE'
                    && $trigger->EVENT_MANIPULATION === $operation && $trigger->ACTION_STATEMENT === explode(' FOR EACH ROW ', $sql, 2)[1]);
            if (($present[0] || $trigger) && ! $matches) {
                throw new LogicException('Unexpected order inquiry guard ownership prevents rollback.');
            }
        }
        if ($present[0]) {
            foreach (array_keys($this->guards()) as $name) {
                DB::unprepared('DROP TRIGGER '.$name);
            }
            foreach (array_reverse(self::TABLES) as $table) {
                Schema::drop($table);
            }
        }
    }

    private function rollbackPreflight(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new LogicException('Order inquiry contexts require SQLite or MySQL.');
        }
        foreach (self::TABLES as $table) {
            if (DB::getDriverName() === 'sqlite') {
                if (DB::table('sqlite_temp_master')->whereRaw('tbl_name COLLATE NOCASE = ?', [$table])->orWhereIn(DB::raw('name COLLATE NOCASE'), [$table, ...array_keys($this->guards())])->exists()) {
                    throw new LogicException('Temporary order inquiry context objects refused.');
                }
                $object = DB::table('sqlite_master')->whereRaw('name COLLATE NOCASE = ?', [$table])->first();
                if ($object && ($object->type !== 'table' || $object->name !== $table)) {
                    throw new LogicException('Unexpected order inquiry context object.');
                }
            } else {
                $objects = DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::getDatabaseName())->whereRaw('LOWER(TABLE_NAME) = ?', [$table])->get();
                if ($objects->count() > 1 || ($objects->isNotEmpty() && ($objects[0]->TABLE_NAME !== $table || $objects[0]->TABLE_TYPE !== 'BASE TABLE' || $objects[0]->ENGINE !== 'InnoDB'))) {
                    throw new LogicException('Unexpected order inquiry context table identity.');
                }
                try {
                    $object = DB::selectOne('SHOW CREATE TABLE '.$table);
                    if (str_starts_with($object->{'Create Table'} ?? '', 'CREATE TEMPORARY TABLE ')) {
                        throw new LogicException('Temporary order inquiry context table refused.');
                    }
                } catch (QueryException $error) {
                    if (($error->errorInfo[0] ?? null) !== '42S02' || ($error->errorInfo[1] ?? null) !== 1146) {
                        throw $error;
                    }
                }
            }
        }
    }

    /** Refuse collateral DDL on a same-named empty foreign table or altered key. */
    private function ownedSchema(): void
    {
        $types = ['id' => 'bigint unsigned', 'inquiry_id' => 'bigint unsigned', 'order_id' => 'bigint unsigned',
            'schema_version' => 'smallint unsigned', 'order_hash' => 'char(64)', 'inquiry_hash' => 'char(64)',
            'context_hash' => 'char(64)', 'created_at' => 'datetime'];
        $nullable = [];
        foreach (['inquiry_order_contexts' => $types] as $table => $expected) {
            if (DB::getDriverName() === 'sqlite') {
                $sql = DB::table('sqlite_master')->where('type', 'table')->where('name', $table)->value('sql');
                if ($sql !== preg_replace('/\Acreate table /', 'CREATE TABLE ', $this->statements($table)[0])) {
                    throw new LogicException('Unexpected order inquiry context table definition.');
                }
            }
            $columns = Schema::getColumns($table);
            if (array_column($columns, 'name') !== array_keys($expected)) {
                throw new LogicException('Unexpected order inquiry context columns.');
            }
            foreach ($columns as $column) {
                if ($column['nullable'] !== in_array($column['name'], $nullable, true) || $column['default'] !== null
                    || (DB::getDriverName() === 'mysql' && $column['type'] !== $expected[$column['name']])) {
                    throw new LogicException('Unexpected order inquiry context column definition.');
                }
            }
            if (DB::getDriverName() === 'mysql') {
                $collation = DB::connection()->getConfig('collation');
                $definition = DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $table)->sole();
                if ($definition->TABLE_COLLATION !== $collation || $definition->CREATE_OPTIONS !== '' || $definition->TABLE_COMMENT !== '') {
                    throw new LogicException('Unexpected order inquiry context table options.');
                }
                foreach (DB::table('information_schema.COLUMNS')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $table)->get() as $column) {
                    $text = preg_match('/^(?:char|varchar|text)/', $expected[$column->COLUMN_NAME]);
                    if ($column->COLLATION_NAME !== ($text ? $collation : null) || $column->GENERATION_EXPRESSION !== '' || $column->COLUMN_COMMENT !== ''
                        || $column->EXTRA !== ($column->COLUMN_NAME === 'id' ? 'auto_increment' : '')) {
                        throw new LogicException('Unexpected order inquiry context column attributes.');
                    }
                }
                if (DB::table('information_schema.TABLE_CONSTRAINTS')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $table)->where('CONSTRAINT_TYPE', 'CHECK')->exists()) {
                    throw new LogicException('Unexpected order inquiry context check constraint.');
                }
            }
            $this->ownedIndexes($table);
            $actualForeign = Schema::getForeignKeys($table);
            $foreign = ['order_id' => ['orders', 'id'], 'inquiry_id' => ['customer_inquiries', 'id']];
            if (count($actualForeign) !== count($foreign)) {
                throw new LogicException('Unexpected order inquiry context foreign keys.');
            }
            foreach ($actualForeign as $key) {
                $column = $key['columns'][0] ?? '';
                if (! isset($foreign[$column]) || $key['columns'] !== [$column] || [$key['foreign_table'], $key['foreign_columns'][0] ?? ''] !== $foreign[$column]
                    || $key['foreign_schema'] !== (DB::getDriverName() === 'sqlite' ? 'main' : DB::getDatabaseName()) || count($key['foreign_columns']) !== 1 || $key['on_delete'] !== 'restrict' || $key['on_update'] !== 'no action') {
                    throw new LogicException('Unexpected order inquiry context foreign key definition.');
                }
                unset($foreign[$column]);
            }
            $triggers = DB::getDriverName() === 'sqlite'
                ? DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', $table)->count()
                : DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->where('EVENT_OBJECT_TABLE', $table)->count();
            if ($triggers !== 3) {
                throw new LogicException('Unexpected order inquiry context table guards.');
            }
        }
    }

    /** An unrelated child must not make DROP fail after the permanent guards have been removed. */
    private function refuseExternalReferences(): void
    {
        if (DB::getDriverName() === 'mysql') {
            if (DB::table('information_schema.KEY_COLUMN_USAGE')->where('REFERENCED_TABLE_SCHEMA', DB::getDatabaseName())
                ->whereIn('REFERENCED_TABLE_NAME', self::TABLES)
                ->where(fn ($query) => $query->where('TABLE_SCHEMA', '<>', DB::getDatabaseName())->orWhereNotIn('TABLE_NAME', self::TABLES))->exists()) {
                throw new LogicException('Foreign order inquiry context references prevent rollback.');
            }

            return;
        }
        foreach (DB::table('sqlite_master')->where('type', 'table')->whereNotIn('name', self::TABLES)->pluck('name') as $table) {
            foreach (Schema::getForeignKeys($table) as $key) {
                if (in_array(strtolower($key['foreign_table']), self::TABLES, true)) {
                    throw new LogicException('Foreign order inquiry context references prevent rollback.');
                }
            }
        }
    }

    private function ownedIndexes(string $table): void
    {
        $expected = ['primary' => [['id'], true], $table.'_inquiry_id_unique' => [['inquiry_id'], true],
            $table.'_order_id_index' => [['order_id'], false]];
        foreach (Schema::getIndexes($table) as $index) {
            $name = $index['primary'] ? 'primary' : $index['name'];
            if (! isset($expected[$name]) || [$index['columns'], $index['unique']] !== $expected[$name]
                || $index['type'] !== (DB::getDriverName() === 'mysql' ? 'btree' : null)) {
                throw new LogicException('Unexpected order inquiry context index definition.');
            }
            unset($expected[$name]);
        }
        if ($expected !== []) {
            throw new LogicException('Missing order inquiry context index.');
        }
        if (DB::getDriverName() === 'mysql') {
            foreach (DB::table('information_schema.STATISTICS')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $table)->get() as $part) {
                if ($part->SUB_PART !== null || $part->EXPRESSION !== null || $part->COLLATION !== 'A' || $part->IS_VISIBLE !== 'YES' || $part->INDEX_COMMENT !== '') {
                    throw new LogicException('Unexpected order inquiry context index attributes.');
                }
            }
        } else {
            foreach (DB::select('PRAGMA index_list('.$table.')') as $index) {
                if ($index->partial !== 0) {
                    throw new LogicException('Unexpected partial order inquiry context index.');
                }
                foreach (DB::select('PRAGMA index_xinfo('.DB::connection()->getPdo()->quote($index->name).')') as $part) {
                    if ($part->key === 1 && ($part->cid < 0 || $part->coll !== 'BINARY' || $part->desc !== 0)) {
                        throw new LogicException('Unexpected order inquiry context index expression.');
                    }
                }
            }
        }
    }
};
