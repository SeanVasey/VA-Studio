<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * FP-028 follow-up (PR #259 review, P3): the `backup_evidence` insert guard from 270350 compared kind and status under the
 * column's case-, accent- and pad-insensitive MySQL collation, admitted a zero-byte successful daily backup, and on SQLite
 * admitted a non-integer byte count. The replacement guard compares kind and status byte for byte on MySQL (BINARY, as in
 * 000043), requires a positive byte count for a successful daily backup, and requires an integer byte count on SQLite.
 * The update and delete guards are untouched.
 *
 * 270350 may already have run on staging, so it is never edited: this migration refuses unless the installed guard is exactly
 * the 270350 definition, creates the replacement under a new name, then drops the original. While both exist a row must pass
 * both, so no instant admits a row the original guard refused.
 */
return new class extends Migration
{
    private const TABLE = 'backup_evidence';

    private const ORIGINAL = 'backup_evidence_insert';

    private const REPLACEMENT = 'backup_evidence_insert_v2';

    public function up(): void
    {
        $this->driver();
        // Refuse before any DDL unless the installed guard is exactly the 270350 definition; never adopt a drifted one.
        if (! $this->installed(self::ORIGINAL, $this->original())) {
            throw new LogicException('Unexpected backup evidence insert guard; nothing was changed. Investigate before retrying.');
        }
        if ($this->named(self::REPLACEMENT)->isNotEmpty()) {
            throw new LogicException('A replacement backup evidence guard already exists; nothing was changed. Investigate before retrying.');
        }
        DB::unprepared($this->create(self::REPLACEMENT, $this->replacement()));
        DB::unprepared('DROP TRIGGER '.self::ORIGINAL);
    }

    public function down(): void
    {
        $this->driver();
        // The 270350 guard admits every row the replacement admits, so retained evidence never blocks the rollback.
        if (! $this->installed(self::REPLACEMENT, $this->replacement()) || $this->named(self::ORIGINAL)->isNotEmpty()) {
            throw new LogicException('Unexpected backup evidence insert guard; nothing was changed. Investigate before retrying.');
        }
        DB::unprepared($this->create(self::ORIGINAL, $this->original()));
        DB::unprepared('DROP TRIGGER '.self::REPLACEMENT);
    }

    /** The 270350 condition, byte for byte. */
    private function original(): string
    {
        $hex = $this->sqlite() ? "NEW.sha256 NOT GLOB '*[^0-9a-f]*'" : "REGEXP_LIKE(NEW.sha256, '^[0-9a-f]{64}$', 'c')";

        return "NEW.kind IN ('daily', 'restore-drill') AND NEW.status IN ('ok', 'failed')"
            ." AND (NEW.sha256 IS NULL OR (length(NEW.sha256) = 64 AND {$hex}))"
            .' AND (NEW.bytes IS NULL OR NEW.bytes >= 0)'
            ." AND NOT (NEW.kind = 'daily' AND NEW.status = 'ok' AND (NEW.bytes IS NULL OR NEW.sha256 IS NULL))";
    }

    private function replacement(): string
    {
        $sqlite = $this->sqlite();
        $hex = $sqlite ? "NEW.sha256 NOT GLOB '*[^0-9a-f]*'" : "REGEXP_LIKE(NEW.sha256, '^[0-9a-f]{64}$', 'c')";
        $kind = $sqlite ? 'NEW.kind' : 'BINARY NEW.kind';
        $status = $sqlite ? 'NEW.status' : 'BINARY NEW.status';
        $bytes = $sqlite ? "TYPEOF(NEW.bytes) = 'integer' AND NEW.bytes >= 0" : 'NEW.bytes >= 0';

        return "{$kind} IN ('daily', 'restore-drill') AND {$status} IN ('ok', 'failed')"
            ." AND (NEW.sha256 IS NULL OR (length(NEW.sha256) = 64 AND {$hex}))"
            ." AND (NEW.bytes IS NULL OR ({$bytes}))"
            ." AND NOT ({$kind} = 'daily' AND {$status} = 'ok' AND (NEW.bytes IS NULL OR NEW.bytes <= 0 OR NEW.sha256 IS NULL))";
    }

    /** The trigger body in the 270350 form: the full statement on SQLite, the stored action statement on MySQL. */
    private function body(string $allowed): string
    {
        return $this->sqlite()
            ? " WHEN NOT COALESCE(({$allowed}), 0) BEGIN SELECT RAISE(ABORT, 'backup evidence shape refused'); END"
            : "BEGIN IF NOT COALESCE(({$allowed}), 0) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'backup evidence shape refused'; END IF; END";
    }

    private function create(string $name, string $allowed): string
    {
        return $this->sqlite()
            ? "CREATE TRIGGER {$name} BEFORE INSERT ON ".self::TABLE.$this->body($allowed)
            : "CREATE TRIGGER {$name} BEFORE INSERT ON ".self::TABLE.' FOR EACH ROW '.$this->body($allowed);
    }

    private function installed(string $name, string $allowed): bool
    {
        $rows = $this->named($name);
        if ($rows->count() !== 1) {
            return false;
        }
        $row = $rows->first();
        if ($this->sqlite()) {
            return $row->name === $name && $row->tbl_name === self::TABLE
                && $this->normalized($row->sql) === $this->normalized($this->create($name, $allowed));
        }

        return $row->TRIGGER_NAME === $name && $row->EVENT_OBJECT_TABLE === self::TABLE && $row->ACTION_TIMING === 'BEFORE'
            && $row->EVENT_MANIPULATION === 'INSERT' && $this->normalized($row->ACTION_STATEMENT) === $this->normalized($this->body($allowed));
    }

    private function named(string $name): Collection
    {
        return $this->sqlite()
            ? DB::table('sqlite_master')->where('type', 'trigger')->whereRaw('name COLLATE NOCASE = ?', [$name])->get()
            : DB::table('information_schema.TRIGGERS')->whereRaw('CAST(TRIGGER_SCHEMA AS BINARY) = ?', [DB::getDatabaseName()])
                ->whereRaw('LOWER(TRIGGER_NAME) = ?', [$name])->get();
    }

    private function normalized(string $statement): string
    {
        return preg_replace('/\s+/', ' ', trim($statement)) ?? throw new LogicException('Guard definition could not be read.');
    }

    private function sqlite(): bool
    {
        return DB::getDriverName() === 'sqlite';
    }

    private function driver(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new RuntimeException('Operational health signals require SQLite or MySQL.');
        }
    }
};
