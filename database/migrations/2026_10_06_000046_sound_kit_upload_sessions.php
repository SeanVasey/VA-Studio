<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'sound_kit_upload_sessions';

    private const TRIGGERS = ['sound_kit_upload_identity', 'sound_kit_upload_retain', 'sound_kit_upload_insert'];

    public function up(): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new LogicException('Kit uploads require MySQL or SQLite.');
        }
        $this->preflight($driver);
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('sound_kit_draft_id')->constrained('sound_kit_drafts')->restrictOnDelete();
            $table->unsignedInteger('expected_version');
            $table->char('profile_sha256', 64);
            $table->string('original_name', 180);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->unsignedBigInteger('received_bytes')->default(0);
            $table->json('parts');
            $table->string('status', 16);
            $table->foreignId('revision_id')->nullable()->constrained('sound_kit_revisions')->restrictOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('cleaned_at')->nullable();
            $table->timestamps();
            $table->index(['actor_id', 'status', 'expires_at'], 'sound_kit_upload_actor_status_expiry');
        });
        $different = fn (string $field): string => $driver === 'mysql'
            ? "NOT (CAST(OLD.{$field} AS BINARY) <=> CAST(NEW.{$field} AS BINARY))" : "OLD.{$field} IS NOT NEW.{$field}";
        $identity = ['id', 'public_id', 'actor_id', 'sound_kit_draft_id', 'expected_version', 'profile_sha256',
            'original_name', 'size_bytes', 'sha256', 'expires_at', 'created_at'];
        $terminal = implode(' OR ', array_map($different, ['status', 'revision_id', 'parts', 'received_bytes']));
        $this->guard($driver, 'sound_kit_upload_identity', 'UPDATE', implode(' OR ', array_map($different, $identity))
            ." OR (OLD.status IN ('completed', 'cancelled') AND ({$terminal}))");
        $this->guard($driver, 'sound_kit_upload_retain', 'DELETE');
        $this->guard($driver, 'sound_kit_upload_insert', 'INSERT',
            'EXISTS (SELECT 1 FROM sound_kit_upload_sessions WHERE id = NEW.id OR public_id = NEW.public_id)');
    }

    private function guard(string $driver, string $name, string $event, ?string $when = null): void
    {
        if ($driver === 'sqlite') {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON sound_kit_upload_sessions ".($when === null ? '' : "WHEN {$when} ")
                ."BEGIN SELECT RAISE(ABORT, 'Retain kit upload evidence'); END");
        } else {
            $signal = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Retain kit upload evidence';";
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON sound_kit_upload_sessions FOR EACH ROW BEGIN "
                .($when === null ? $signal : "IF {$when} THEN {$signal} END IF;").' END');
        }
    }

    private function preflight(string $driver): void
    {
        $names = [self::TABLE, ...self::TRIGGERS, 'sound_kit_upload_sessions_public_id_unique', 'sound_kit_upload_actor_status_expiry'];
        if ($driver === 'sqlite') {
            foreach (['sqlite_master', 'sqlite_temp_master'] as $catalog) {
                if (DB::table($catalog)->whereIn(DB::raw('lower(name)'), $names)->orWhereRaw('lower(tbl_name) = ?', [self::TABLE])->exists()) {
                    throw new LogicException('Existing kit upload schema requires inspection; nothing was changed.');
                }
            }

            return;
        }
        if (DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::getDatabaseName())->whereRaw('LOWER(TABLE_NAME) = ?', [self::TABLE])->exists()
            || DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->whereIn(DB::raw('LOWER(TRIGGER_NAME)'), self::TRIGGERS)->exists()) {
            throw new LogicException('Existing kit upload schema requires inspection; nothing was changed.');
        }
        try {
            DB::selectOne('SHOW CREATE TABLE sound_kit_upload_sessions');
        } catch (QueryException $error) {
            if (($error->errorInfo[0] ?? null) === '42S02' && ($error->errorInfo[1] ?? null) === 1146) {
                return;
            }
            throw $error;
        }
        throw new LogicException('A temporary kit upload table requires inspection; nothing was changed.');
    }

    public function down(): void
    {
        // Operational rollback never erases retained session identities, outcomes or interrupted transport.
    }
};
