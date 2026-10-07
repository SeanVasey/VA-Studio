<?php

namespace App\Domain\Services\Projects;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

final class ServiceProjectSchema
{
    public static function install(): void
    {
        $driver = DB::getDriverName();
        $prefix = DB::connection()->getTablePrefix();
        if (! in_array($driver, ['sqlite', 'mysql'], true) || ! preg_match('/\A[a-zA-Z0-9_]{0,16}\z/D', $prefix)) {
            throw new LogicException('Service projects require SQLite or MySQL and a bounded safe prefix.');
        }
        $parent = $prefix.'service_projects';
        $events = $prefix.'service_project_events';
        $indexes = [$parent.'_public', $parent.'_request', $events.'_public', $events.'_number', $events.'_request'];
        $triggers = [];
        foreach ([$parent, $events] as $name) {
            foreach (['immutable', 'retain', 'insert'] as $suffix) {
                $triggers[] = $name.'_'.$suffix;
            }
        }
        self::preflight($driver, [$parent, $events], $triggers, $indexes);
        Schema::create('service_projects', function (Blueprint $table) use ($parent): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->uuid('public_id');
            $table->foreignId('customer_account_id')->constrained('customer_accounts')->restrictOnDelete();
            $table->foreignId('service_version_id')->constrained('service_draft_versions')->restrictOnDelete();
            $table->longText('service_manifest');
            $table->char('service_hash', 64);
            $table->longText('brief');
            $table->char('brief_hash', 64);
            $table->uuid('creation_key');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->unique('public_id', $parent.'_public');
            $table->unique(['customer_account_id', 'creation_key'], $parent.'_request');
        });
        Schema::create('service_project_events', function (Blueprint $table) use ($events): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->uuid('public_id');
            $table->foreignId('project_id')->constrained('service_projects')->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->string('operation', 40);
            $table->string('actor_kind', 8);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->uuid('request_key');
            $table->char('request_hash', 64);
            $table->longText('payload');
            $table->char('payload_hash', 64);
            $table->timestamp('created_at');
            $table->unique('public_id', $events.'_public');
            $table->unique(['project_id', 'number'], $events.'_number');
            $table->unique(['project_id', 'request_key'], $events.'_request');
        });
        $wrap = DB::connection()->getQueryGrammar()->wrap(...);
        foreach ([$parent, $events] as $table) {
            self::guard($driver, $table.'_immutable', $wrap($table), 'UPDATE');
            self::guard($driver, $table.'_retain', $wrap($table), 'DELETE');
        }
        $hash = fn (string $column): string => $driver === 'mysql'
            ? '(CHAR_LENGTH(NEW.'.$column.') = 64 AND REGEXP_LIKE(NEW.'.$column.", '^[a-f0-9]{64}$', 'c'))"
            : '(length(NEW.'.$column.') = 64 AND NEW.'.$column." NOT GLOB '*[^a-f0-9]*')";
        self::guard($driver, $parent.'_insert', $wrap($parent), 'INSERT',
            'NOT '.$hash('service_hash').' OR NOT '.$hash('brief_hash').' OR EXISTS (SELECT 1 FROM '.$wrap($parent)
            .' WHERE id = NEW.id OR public_id = NEW.public_id OR (customer_account_id = NEW.customer_account_id AND creation_key = NEW.creation_key))');
        self::guard($driver, $events.'_insert', $wrap($events), 'INSERT',
            'NEW.number < 1 OR NEW.number > 1000 OR NEW.actor_kind NOT IN (\'staff\', \'buyer\') OR NOT '.$hash('request_hash').' OR NOT '.$hash('payload_hash')
            .' OR EXISTS (SELECT 1 FROM '.$wrap($events).' WHERE id = NEW.id OR public_id = NEW.public_id'
            .' OR (project_id = NEW.project_id AND (number = NEW.number OR request_key = NEW.request_key)))');
    }

    private static function guard(string $driver, string $name, string $table, string $event, ?string $condition = null): void
    {
        $name = DB::connection()->getQueryGrammar()->wrap($name);
        if ($driver === 'sqlite') {
            DB::unprepared('CREATE TRIGGER '.$name.' BEFORE '.$event.' ON '.$table.($condition ? ' WHEN '.$condition : '')
                ." BEGIN SELECT RAISE(ABORT, 'Retain service project evidence'); END");
        } else {
            $signal = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Retain service project evidence';";
            DB::unprepared('CREATE TRIGGER '.$name.' BEFORE '.$event.' ON '.$table.' FOR EACH ROW BEGIN '
                .($condition ? 'IF '.$condition.' THEN '.$signal.' END IF;' : $signal).' END');
        }
    }

    private static function preflight(string $driver, array $tables, array $triggers, array $indexes): void
    {
        $physical = $tables;
        $tables = array_map(strtolower(...), $tables);
        $names = array_map(strtolower(...), [...$tables, ...$triggers, ...$indexes]);
        $marks = fn (array $values): string => implode(',', array_fill(0, count($values), '?'));
        if ($driver === 'sqlite') {
            foreach (['sqlite_master', 'sqlite_temp_master'] as $catalog) {
                if (DB::selectOne('SELECT 1 AS present FROM '.$catalog.' WHERE lower(name) IN ('.$marks($names).')'
                    .' OR lower(tbl_name) IN ('.$marks($tables).') LIMIT 1', [...$names, ...$tables])) {
                    throw new LogicException('Retain existing or partial service project schema for inspection.');
                }
            }
        } else {
            $database = DB::getDatabaseName();
            foreach ([['TABLES', 'TABLE_NAME'], ['TRIGGERS', 'TRIGGER_NAME'], ['STATISTICS', 'INDEX_NAME']] as [$catalog, $column]) {
                $scope = $catalog === 'TRIGGERS' ? 'TRIGGER_SCHEMA' : 'TABLE_SCHEMA';
                if (DB::selectOne('SELECT 1 AS present FROM information_schema.'.$catalog.' WHERE '.$scope.' = ? AND lower('.$column.') IN ('.$marks($names).') LIMIT 1', [$database, ...$names])) {
                    throw new LogicException('Retain existing or partial service project schema for inspection.');
                }
            }
            foreach ($physical as $table) {
                try {
                    DB::selectOne('SHOW CREATE TABLE '.DB::connection()->getQueryGrammar()->wrap($table));
                } catch (QueryException $error) {
                    if (($error->errorInfo[0] ?? null) === '42S02' && ($error->errorInfo[1] ?? null) === 1146) {
                        continue;
                    }
                    throw $error;
                }
                throw new LogicException('Retain temporary service project schema for inspection.');
            }
        }
    }
}
