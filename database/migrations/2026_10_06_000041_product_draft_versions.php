<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['product_drafts', 'product_draft_versions', 'product_draft_members'];

    private const TRIGGERS = ['product_drafts_identity', 'product_drafts_retain',
        'product_draft_versions_immutable', 'product_draft_versions_retain',
        'product_draft_members_immutable', 'product_draft_members_retain'];

    public function up(): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new LogicException('Product draft versions require MySQL or SQLite.');
        }
        $this->preflight($driver);
        // MySQL DDL commits separately. Any interrupted prefix is retained and refused on
        // retry, never adopted or dropped. Recovery requires inspecting schema and journal.
        Schema::create('product_drafts', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 16);
            $table->string('title', 180);
            $table->unsignedInteger('version');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('product_draft_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_draft_id')->constrained('product_drafts')->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->json('manifest');
            $table->char('manifest_sha256', 64);
            $table->foreignId('source_version_id')->nullable()->constrained('product_draft_versions')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->unique(['product_draft_id', 'number'], 'product_draft_versions_draft_number_unique');
        });
        Schema::create('product_draft_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_draft_version_id')->constrained('product_draft_versions')->restrictOnDelete();
            $table->unsignedInteger('position');
            $table->foreignId('track_id')->constrained('tracks')->restrictOnDelete();
            $table->string('title');
            $table->unsignedInteger('metadata_version');
            $table->unsignedInteger('publication_version');
            $table->unique(['product_draft_version_id', 'position'], 'product_draft_members_version_position_unique');
            $table->unique(['product_draft_version_id', 'track_id'], 'product_draft_members_version_track_unique');
        });
        foreach (['product_draft_versions', 'product_draft_members'] as $table) {
            $this->guard($driver, $table.'_immutable', $table, 'UPDATE');
            $this->guard($driver, $table.'_retain', $table, 'DELETE');
        }
        $this->guard($driver, 'product_drafts_retain', 'product_drafts', 'DELETE');
        $changed = $driver === 'mysql'
            ? 'NOT (OLD.id <=> NEW.id) OR NOT (OLD.kind <=> NEW.kind) OR NOT (OLD.created_by <=> NEW.created_by) OR NOT (OLD.created_at <=> NEW.created_at)'
            : 'OLD.id IS NOT NEW.id OR OLD.kind IS NOT NEW.kind OR OLD.created_by IS NOT NEW.created_by OR OLD.created_at IS NOT NEW.created_at';
        $this->guard($driver, 'product_drafts_identity', 'product_drafts', 'UPDATE', $changed);
    }

    private function guard(string $driver, string $name, string $table, string $event, ?string $when = null): void
    {
        if ($driver === 'sqlite') {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} ".($when === null ? '' : "WHEN {$when} ")
                ."BEGIN SELECT RAISE(ABORT, 'Retain immutable product draft evidence'); END");
        } else {
            $signal = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Retain immutable product draft evidence';";
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW BEGIN "
                .($when === null ? $signal : "IF {$when} THEN {$signal} END IF;").' END');
        }
    }

    private function preflight(string $driver): void
    {
        $indexes = ['product_draft_versions_draft_number_unique', 'product_draft_members_version_position_unique', 'product_draft_members_version_track_unique'];
        $names = [...self::TABLES, ...self::TRIGGERS, ...$indexes];
        if ($driver === 'sqlite') {
            foreach (['sqlite_master', 'sqlite_temp_master'] as $catalog) {
                if (DB::table($catalog)->whereIn(DB::raw('lower(name)'), $names)
                    ->orWhereIn(DB::raw('lower(tbl_name)'), self::TABLES)->exists()) {
                    throw new LogicException('An existing or temporary product draft schema object requires inspection; nothing was changed.');
                }
            }

            return;
        }
        $database = DB::getDatabaseName();
        if (DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', $database)->whereIn(DB::raw('LOWER(TABLE_NAME)'), self::TABLES)->exists()
            || DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', $database)->whereIn(DB::raw('LOWER(TRIGGER_NAME)'), self::TRIGGERS)->exists()) {
            throw new LogicException('An existing product draft schema object requires inspection; nothing was changed.');
        }
        foreach (self::TABLES as $table) {
            try {
                DB::selectOne('SHOW CREATE TABLE '.DB::connection()->getQueryGrammar()->wrapTable($table));
            } catch (QueryException $error) {
                if (($error->errorInfo[0] ?? null) === '42S02' && ($error->errorInfo[1] ?? null) === 1146) {
                    continue;
                }
                throw $error;
            }
            throw new LogicException('A temporary product draft table requires inspection; nothing was changed.');
        }
    }

    public function down(): void
    {
        // Code rollback never destroys a composition, its source identities, or its audit
        // subjects. Removing retained data requires a separately reviewed retention migration.
    }
};
