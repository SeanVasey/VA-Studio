<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['sound_kit_drafts', 'sound_kit_revisions'];

    private const TRIGGERS = ['sound_kit_drafts_identity', 'sound_kit_drafts_retain', 'sound_kit_drafts_insert',
        'sound_kit_revisions_identity', 'sound_kit_revisions_retain', 'sound_kit_revisions_insert'];

    public function up(): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new LogicException('Sound-kit evidence requires MySQL or SQLite.');
        }
        $this->preflight($driver);
        // Interrupted MySQL DDL remains available for inspection; a retry never adopts or drops it.
        Schema::create('sound_kit_drafts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('title', 180);
            $table->text('description');
            $table->string('provenance', 500);
            $table->unsignedInteger('version');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('sound_kit_revisions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('sound_kit_draft_id')->constrained('sound_kit_drafts')->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->char('command_sha256', 64);
            $table->string('original_name', 180);
            $table->string('source_path', 240);
            $table->char('source_sha256', 64);
            $table->unsignedBigInteger('source_size_bytes');
            $table->string('mime_type', 64);
            $table->json('profile');
            $table->char('profile_sha256', 64);
            $table->json('description_snapshot');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 16);
            $table->uuid('claim_token')->nullable();
            $table->timestamp('claimed_until')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('failure_code', 64)->nullable();
            $table->string('archive_path', 240)->nullable();
            $table->char('archive_sha256', 64)->nullable();
            $table->unsignedBigInteger('archive_size_bytes')->nullable();
            $table->json('manifest')->nullable();
            $table->char('manifest_sha256', 64)->nullable();
            $table->json('evidence')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->unique(['sound_kit_draft_id', 'number'], 'sound_kit_revision_number_unique');
            $table->unique(['sound_kit_draft_id', 'command_sha256'], 'sound_kit_revision_command_unique');
        });
        $same = fn (string $column): string => $driver === 'mysql'
            ? "NOT (CAST(OLD.{$column} AS BINARY) <=> CAST(NEW.{$column} AS BINARY))"
            : "OLD.{$column} IS NOT NEW.{$column}";
        $source = ['id', 'public_id', 'sound_kit_draft_id', 'number', 'command_sha256', 'original_name', 'source_path', 'source_sha256',
            'source_size_bytes', 'mime_type', 'profile', 'profile_sha256', 'description_snapshot', 'uploaded_by', 'created_at'];
        $this->guard($driver, 'sound_kit_drafts_identity', 'sound_kit_drafts', 'UPDATE', implode(' OR ', array_map($same, ['id', 'public_id', 'created_by', 'created_at'])));
        $this->guard($driver, 'sound_kit_revisions_identity', 'sound_kit_revisions', 'UPDATE', "OLD.status IN ('ready', 'failed') OR ".implode(' OR ', array_map($same, $source)));
        foreach (self::TABLES as $table) {
            $this->guard($driver, $table.'_retain', $table, 'DELETE');
            // BEFORE INSERT catches REPLACE even when SQLite recursive delete triggers are disabled.
            $collision = "EXISTS (SELECT 1 FROM {$table} WHERE id = NEW.id OR public_id = NEW.public_id";
            if ($table === 'sound_kit_revisions') {
                $collision .= ' OR (sound_kit_draft_id = NEW.sound_kit_draft_id AND (number = NEW.number OR command_sha256 = NEW.command_sha256))';
            }
            $this->guard($driver, $table.'_insert', $table, 'INSERT', $collision.')');
        }
    }

    private function guard(string $driver, string $name, string $table, string $event, ?string $when = null): void
    {
        if ($driver === 'sqlite') {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} ".($when === null ? '' : "WHEN {$when} ")
                ."BEGIN SELECT RAISE(ABORT, 'Retain sound-kit revision evidence'); END");
        } else {
            $signal = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Retain sound-kit revision evidence';";
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW BEGIN "
                .($when === null ? $signal : "IF {$when} THEN {$signal} END IF;").' END');
        }
    }

    private function preflight(string $driver): void
    {
        $names = [...self::TABLES, ...self::TRIGGERS, 'sound_kit_drafts_public_id_unique', 'sound_kit_revisions_public_id_unique',
            'sound_kit_revision_number_unique', 'sound_kit_revision_command_unique'];
        if ($driver === 'sqlite') {
            foreach (['sqlite_master', 'sqlite_temp_master'] as $catalog) {
                if (DB::table($catalog)->whereIn(DB::raw('lower(name)'), $names)->orWhereIn(DB::raw('lower(tbl_name)'), self::TABLES)->exists()) {
                    throw new LogicException('Existing sound-kit schema requires inspection; nothing was changed.');
                }
            }

            return;
        }
        $database = DB::getDatabaseName();
        if (DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', $database)->whereIn(DB::raw('LOWER(TABLE_NAME)'), self::TABLES)->exists()
            || DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', $database)->whereIn(DB::raw('LOWER(TRIGGER_NAME)'), self::TRIGGERS)->exists()) {
            throw new LogicException('Existing sound-kit schema requires inspection; nothing was changed.');
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
            throw new LogicException('A temporary sound-kit table requires inspection; nothing was changed.');
        }
    }

    public function down(): void
    {
        // Never destroy retained archive versions, audit subjects or interrupted processing evidence.
    }
};
