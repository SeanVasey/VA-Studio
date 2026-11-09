<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FP-028 operational health signals the dashboard can read (App\Domain\Operations\OperationalSignals).
 *
 * `scheduler_heartbeats`: one row per scheduled task, upserted on each outcome (the every-minute heartbeat command and the
 * tracked scheduled commands). Mutable by design: only the latest success and failure matter.
 *
 * `backup_evidence`: append-only rows written by `vasey:backup-record`, which the host backup script calls after each run.
 * Kind, status, byte count and the dump's SHA-256 only: no path, credential or customer data. Triggers refuse any UPDATE or
 * DELETE, and an INSERT of an unknown kind or status, a malformed hash, a negative byte count, or a successful daily backup
 * without its size and hash.
 */
return new class extends Migration
{
    private const EVIDENCE = 'backup_evidence';

    private const TRIGGERS = ['backup_evidence_insert', 'backup_evidence_immutable', 'backup_evidence_retain'];

    public function up(): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql'], true)) {
            throw new RuntimeException('Operational health signals require SQLite or MySQL.');
        }
        Schema::create('scheduler_heartbeats', function (Blueprint $table): void {
            $table->string('task', 100)->primary();
            $table->string('last_outcome', 16);
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamp('updated_at');
        });
        Schema::create(self::EVIDENCE, function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 32);
            $table->string('status', 16);
            $table->unsignedBigInteger('bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->timestamp('recorded_at');
            $table->index(['kind', 'status', 'id'], 'backup_evidence_kind_status');
        });
        $hex = $driver === 'sqlite' ? "NEW.sha256 NOT GLOB '*[^0-9a-f]*'" : "REGEXP_LIKE(NEW.sha256, '^[0-9a-f]{64}$', 'c')";
        $shape = "NEW.kind IN ('daily', 'restore-drill') AND NEW.status IN ('ok', 'failed')"
            ." AND (NEW.sha256 IS NULL OR (length(NEW.sha256) = 64 AND {$hex}))"
            .' AND (NEW.bytes IS NULL OR NEW.bytes >= 0)'
            ." AND NOT (NEW.kind = 'daily' AND NEW.status = 'ok' AND (NEW.bytes IS NULL OR NEW.sha256 IS NULL))";
        [$insert, $immutable, $retain] = self::TRIGGERS;
        if ($driver === 'sqlite') {
            DB::unprepared("CREATE TRIGGER {$insert} BEFORE INSERT ON ".self::EVIDENCE." WHEN NOT COALESCE(({$shape}), 0) BEGIN SELECT RAISE(ABORT, 'backup evidence shape refused'); END");
            DB::unprepared("CREATE TRIGGER {$immutable} BEFORE UPDATE ON ".self::EVIDENCE." BEGIN SELECT RAISE(ABORT, 'backup evidence is append-only'); END");
            DB::unprepared("CREATE TRIGGER {$retain} BEFORE DELETE ON ".self::EVIDENCE." BEGIN SELECT RAISE(ABORT, 'backup evidence is retained'); END");
        } else {
            DB::unprepared("CREATE TRIGGER {$insert} BEFORE INSERT ON ".self::EVIDENCE." FOR EACH ROW BEGIN IF NOT COALESCE(({$shape}), 0) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'backup evidence shape refused'; END IF; END");
            DB::unprepared("CREATE TRIGGER {$immutable} BEFORE UPDATE ON ".self::EVIDENCE." FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'backup evidence is append-only'");
            DB::unprepared("CREATE TRIGGER {$retain} BEFORE DELETE ON ".self::EVIDENCE." FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'backup evidence is retained'");
        }
    }

    public function down(): void
    {
        // Recorded backup and restore-drill evidence is retained: rollback only while nothing was recorded.
        if (DB::table(self::EVIDENCE)->exists()) {
            throw new LogicException('Retain recorded backup evidence; refusing rollback.');
        }
        foreach (self::TRIGGERS as $trigger) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$trigger);
        }
        Schema::drop(self::EVIDENCE);
        Schema::drop('scheduler_heartbeats');
    }
};
