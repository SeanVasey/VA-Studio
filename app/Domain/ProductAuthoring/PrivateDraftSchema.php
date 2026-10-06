<?php

namespace App\Domain\ProductAuthoring;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

/** Explicitly owned additive schemas; interrupted DDL is retained for inspection. */
final class PrivateDraftSchema
{
    public static function install(string $kind): void
    {
        if (! in_array($kind, ['service', 'merch'], true)) {
            throw new LogicException('Unknown private product schema.');
        }
        $driver = DB::getDriverName();
        if (! in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new LogicException('Private product drafts require MySQL or SQLite.');
        }
        $prefix = DB::connection()->getTablePrefix();
        if (! preg_match('/\A[a-zA-Z0-9_]{0,16}\z/D', $prefix)) {
            throw new LogicException('Private draft schema prefixes must use up to 16 letters, digits or underscores.');
        }
        $logicalParent = $kind.'_drafts';
        $logicalVersions = $kind.'_draft_versions';
        $parent = $prefix.$logicalParent;
        $versions = $prefix.$logicalVersions;
        $triggers = [$parent.'_identity', $parent.'_retain', $parent.'_insert',
            $versions.'_immutable', $versions.'_retain', $versions.'_insert'];
        $indexes = [$parent.'_creation_review_unique', $versions.'_draft_number_unique'];
        self::preflight($driver, [$parent, $versions], $triggers, $indexes);
        Schema::create($logicalParent, function (Blueprint $table) use ($parent): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('title', 180);
            $table->unsignedInteger('version');
            $table->char('creation_review_hash', 64);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique('creation_review_hash', $parent.'_creation_review_unique');
        });
        Schema::create($logicalVersions, function (Blueprint $table) use ($logicalParent, $versions): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('draft_id')->constrained($logicalParent)->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->longText('manifest');
            $table->char('manifest_sha256', 64);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->unique(['draft_id', 'number'], $versions.'_draft_number_unique');
        });
        $parentSql = DB::connection()->getQueryGrammar()->wrap($parent);
        $versionsSql = DB::connection()->getQueryGrammar()->wrap($versions);
        $same = fn (string $field): string => $driver === 'mysql'
            ? '(CAST(OLD.'.$field.' AS BINARY) <=> CAST(NEW.'.$field.' AS BINARY))'
            : '(OLD.'.$field.' IS NEW.'.$field.')';
        $identity = implode(' OR ', array_map(fn ($field): string => 'NOT '.$same($field), ['id', 'creation_review_hash', 'created_by', 'created_at']));
        $identity .= ' OR NEW.version < 1 OR NEW.version > 100 OR (NEW.version != OLD.version AND NEW.version != OLD.version + 1)'
            .' OR (NOT '.$same('title').' AND NEW.version != OLD.version + 1)';
        self::guard($driver, $parent.'_identity', $parentSql, 'UPDATE', $identity);
        self::guard($driver, $parent.'_retain', $parentSql, 'DELETE');
        foreach (['UPDATE' => '_immutable', 'DELETE' => '_retain'] as $event => $suffix) {
            self::guard($driver, $versions.$suffix, $versionsSql, $event);
        }
        $valid = fn (string $field): string => $driver === 'mysql'
            ? '(CHAR_LENGTH(NEW.'.$field.') = 64 AND REGEXP_LIKE(NEW.'.$field.", '^[0-9a-f]{64}$', 'c'))"
            : '(length(NEW.'.$field.') = 64 AND NEW.'.$field." NOT GLOB '*[^0-9a-f]*')";
        // SQLite REPLACE skips DELETE triggers when recursive_triggers is off. Refuse every
        // existing primary/unique collision in BEFORE INSERT, independent of that setting.
        self::guard($driver, $parent.'_insert', $parentSql, 'INSERT', 'NEW.version != 1 OR NOT '.$valid('creation_review_hash')
            .' OR EXISTS (SELECT 1 FROM '.$parentSql.' WHERE id = NEW.id OR creation_review_hash = NEW.creation_review_hash)');
        self::guard($driver, $versions.'_insert', $versionsSql, 'INSERT', 'NEW.number < 1 OR NEW.number > 100 OR NOT '.$valid('manifest_sha256')
            .' OR EXISTS (SELECT 1 FROM '.$versionsSql.' WHERE id = NEW.id OR (draft_id = NEW.draft_id AND number = NEW.number))');
    }

    private static function guard(string $driver, string $name, string $table, string $event, ?string $condition = null): void
    {
        $name = DB::connection()->getQueryGrammar()->wrap($name);
        if ($driver === 'sqlite') {
            DB::unprepared('CREATE TRIGGER '.$name.' BEFORE '.$event.' ON '.$table.($condition === null ? '' : ' WHEN '.$condition)
                ." BEGIN SELECT RAISE(ABORT, 'Retain private product draft evidence'); END");

            return;
        }
        $signal = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Retain private product draft evidence';";
        DB::unprepared('CREATE TRIGGER '.$name.' BEFORE '.$event.' ON '.$table.' FOR EACH ROW BEGIN '
            .($condition === null ? $signal : 'IF '.$condition.' THEN '.$signal.' END IF;').' END');
    }

    private static function preflight(string $driver, array $tables, array $triggers, array $indexes): void
    {
        $physicalTables = $tables;
        $tables = array_map(strtolower(...), $tables);
        $triggers = array_map(strtolower(...), $triggers);
        $indexes = array_map(strtolower(...), $indexes);
        $placeholders = fn (array $values): string => implode(', ', array_fill(0, count($values), '?'));
        if ($driver === 'sqlite') {
            foreach (['sqlite_master', 'sqlite_temp_master'] as $catalog) {
                $names = [...$tables, ...$triggers, ...$indexes];
                if (DB::selectOne('SELECT 1 AS present FROM '.$catalog.' WHERE lower(name) IN ('.$placeholders($names).')'
                    .' OR lower(tbl_name) IN ('.$placeholders($tables).') LIMIT 1', [...$names, ...$tables]) !== null) {
                    throw new LogicException('An existing or temporary private draft schema object requires inspection; nothing was changed.');
                }
            }

            return;
        }
        $database = DB::getDatabaseName();
        foreach ([['TABLES', 'TABLE_SCHEMA', 'TABLE_NAME', $tables], ['TRIGGERS', 'TRIGGER_SCHEMA', 'TRIGGER_NAME', $triggers],
            ['STATISTICS', 'TABLE_SCHEMA', 'INDEX_NAME', $indexes]] as [$catalog, $schemaColumn, $nameColumn, $names]) {
            if (DB::selectOne('SELECT 1 AS present FROM information_schema.'.$catalog.' WHERE '.$schemaColumn.' = ?'
                .' AND LOWER('.$nameColumn.') IN ('.$placeholders($names).') LIMIT 1', [$database, ...$names]) !== null) {
                throw new LogicException('An existing private draft schema object requires inspection; nothing was changed.');
            }
        }
        foreach ($physicalTables as $table) {
            try {
                DB::selectOne('SHOW CREATE TABLE '.DB::connection()->getQueryGrammar()->wrap($table));
            } catch (QueryException $error) {
                if (($error->errorInfo[0] ?? null) === '42S02' && ($error->errorInfo[1] ?? null) === 1146) {
                    continue;
                }
                throw $error;
            }
            throw new LogicException('A temporary private draft table requires inspection; nothing was changed.');
        }
    }
}
