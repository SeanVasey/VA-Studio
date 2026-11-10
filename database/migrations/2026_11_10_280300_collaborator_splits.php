<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BL-05 (FP-035): collaborators and per-track splits. Additive staff catalog metadata. Nothing here references or changes
 * prices, orders, payments, contracts, grants or payouts, and no payment, tax or contact column exists.
 *
 * Integrity held by the database: a share is 1 to 10000 basis points; a collaborator appears once per track and split
 * type (unique key); the shares of one track and split type never total more than 10000; role and split type are closed
 * sets; a split's track, collaborator and type never change.
 */
return new class extends Migration
{
    private const TABLES = ['collaborators', 'track_collaborator_splits'];

    private const ROLES = "'producer', 'writer', 'performer'";

    private const TYPES = "'publishing', 'master'";

    private const INVALID = 'Collaborator split record is invalid or its identity is immutable';

    private const OVER = 'Collaborator splits for one track and type cannot exceed 10000 basis points';

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new LogicException('Collaborator splits require SQLite or MySQL.');
        }
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table)) {
                throw new LogicException('Unexpected collaborator split objects; migration refused.');
            }
        }
        $binary = DB::getDriverName() === 'mysql' ? 'ascii_bin' : 'BINARY';
        Schema::create('collaborators', function (Blueprint $table) use ($binary): void {
            $table->id();
            $table->string('display_name');
            $table->string('role', 16)->collation($binary);
            $table->string('source_reference')->nullable();
            $table->timestamps();
        });
        Schema::create('track_collaborator_splits', function (Blueprint $table) use ($binary): void {
            $table->id();
            $table->foreignId('track_id')->constrained('tracks')->restrictOnDelete();
            $table->foreignId('collaborator_id')->constrained('collaborators')->restrictOnDelete();
            $table->string('split_type', 16)->collation($binary);
            $table->unsignedSmallInteger('share_bps');
            $table->timestamps();
            $table->unique(['track_id', 'split_type', 'collaborator_id'], 'track_collaborator_splits_member_unique');
        });
        foreach ($this->guards() as $statement) {
            DB::unprepared($statement);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new LogicException('Retain recorded collaborator splits; rollback is refused while rows exist.');
            }
        }
        Schema::dropIfExists('track_collaborator_splits');
        Schema::dropIfExists('collaborators');
    }

    /** @return array<string, string> trigger name => statement */
    private function guards(): array
    {
        $sqlite = DB::getDriverName() === 'sqlite';
        $integer = fn (string $column): string => $sqlite ? "typeof({$column}) = 'integer' AND " : '';
        $share = $integer('NEW.share_bps').'NEW.share_bps >= 1 AND NEW.share_bps <= 10000';
        $collaborator = 'NEW.role IN ('.self::ROLES.') AND LENGTH(TRIM(NEW.display_name)) > 0';
        $valid = [
            'collaborators_insert_valid' => ['collaborators', 'INSERT', $collaborator],
            'collaborators_update_valid' => ['collaborators', 'UPDATE', 'NEW.id = OLD.id AND '.$collaborator],
            'track_collaborator_splits_insert_valid' => ['track_collaborator_splits', 'INSERT', 'NEW.split_type IN ('.self::TYPES.') AND '.$share],
            'track_collaborator_splits_update_valid' => ['track_collaborator_splits', 'UPDATE',
                'NEW.id = OLD.id AND NEW.track_id = OLD.track_id AND NEW.collaborator_id = OLD.collaborator_id'
                .' AND NEW.split_type = OLD.split_type AND '.$share],
        ];
        $guards = [];
        foreach ($valid as $name => [$table, $operation, $condition]) {
            $guards[$name] = $sqlite
                ? "CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} WHEN NOT COALESCE(({$condition}), 0) BEGIN SELECT RAISE(ABORT, '".self::INVALID."'); END"
                : "CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW BEGIN IF NOT COALESCE(({$condition}), 0) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::INVALID."'; END IF; END";
        }
        // The other shares of the same track and split type. An update excludes the row being changed.
        foreach (['INSERT' => '', 'UPDATE' => ' AND id <> OLD.id'] as $operation => $others) {
            $name = 'track_collaborator_splits_'.strtolower($operation).'_total';
            $recorded = 'SELECT COALESCE(SUM(share_bps), 0) FROM track_collaborator_splits WHERE track_id = NEW.track_id AND split_type = NEW.split_type'.$others;
            if ($sqlite) {
                // SQLite has one writer at a time, so a plain read of the other shares is current.
                $guards[$name] = "CREATE TRIGGER {$name} BEFORE {$operation} ON track_collaborator_splits WHEN ({$recorded}) + NEW.share_bps > 10000 BEGIN SELECT RAISE(ABORT, '".self::OVER."'); END";

                continue;
            }
            // MySQL: lock the track row first, then read the other shares with a shared locking (current) read. Two writers
            // of the same track therefore run one after the other even when they bypass the domain service, and a Repeatable
            // Read snapshot taken earlier in the transaction cannot hide a committed share. The read is FOR SHARE because
            // MySQL refuses FOR UPDATE on a trigger's own table (error 1442); the exclusive fence is the track row.
            $recorded = str_replace('SELECT COALESCE(SUM(share_bps), 0) FROM', 'SELECT COALESCE(SUM(share_bps), 0) INTO recorded_bps FROM', $recorded).' FOR SHARE';
            $guards[$name] = "CREATE TRIGGER {$name} BEFORE {$operation} ON track_collaborator_splits FOR EACH ROW BEGIN"
                .' DECLARE fenced_track BIGINT UNSIGNED DEFAULT NULL; DECLARE recorded_bps BIGINT DEFAULT 0;'
                .' SELECT id INTO fenced_track FROM tracks WHERE id = NEW.track_id FOR UPDATE;'
                ." {$recorded};"
                ." IF recorded_bps + NEW.share_bps > 10000 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::OVER."'; END IF; END";
        }

        return $guards;
    }
};
