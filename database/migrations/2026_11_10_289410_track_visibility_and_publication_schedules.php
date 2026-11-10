<?php

use App\Domain\Catalog\TrackVisibility;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Track scheduling, unlisted and private states (BL-41, FP-032, DD-39, SQ-15).
 *
 * `tracks.status` becomes a closed set with a closed transition table, and `track_publication_schedules` records each
 * requested activation. Both are enforced by triggers so a writer that bypasses the domain services cannot leave a track
 * "scheduled" without a pending schedule, activate it without a resolved schedule, or reach an undefined state.
 * Existing migrations are untouched; the earlier `tracks_*` guards keep running beside these.
 */
return new class extends Migration
{
    private const MESSAGE = 'Track visibility or publication schedule evidence is invalid or immutable';

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new LogicException('Track visibility requires SQLite or MySQL.');
        }
        // Fail closed before any DDL: a row outside the closed set would make every later update of that track refuse.
        $unexpected = DB::table('tracks')->when(DB::getDriverName() === 'mysql',
            fn ($query) => $query->whereRaw('CAST(status AS BINARY) NOT IN ('.implode(',', array_fill(0, count(TrackVisibility::STATES), 'CAST(? AS BINARY)')).')', TrackVisibility::STATES),
            fn ($query) => $query->whereNotIn('status', TrackVisibility::STATES))->distinct()->pluck('status')->all();
        if ($unexpected !== []) {
            throw new LogicException('Unexpected track statuses exist ('.implode(', ', array_map('strval', $unexpected)).'); existing data is unchanged. Investigate before retrying.');
        }
        Schema::create('track_publication_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('track_id')->constrained('tracks')->restrictOnDelete();
            $table->string('target_status', 16);
            // UTC instant. Staff enter America/Chicago wall time; `entry_local` keeps exactly what they entered for the audit trail.
            $table->dateTime('publish_at');
            $table->string('entry_zone', 40);
            $table->string('entry_local', 16);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('created_at');
            // A pending row holds the track's only slot; a resolved row releases it (NULL), so at most one pending schedule per track.
            $table->string('state', 16);
            $table->unsignedTinyInteger('pending_slot')->nullable();
            $table->unique(['track_id', 'pending_slot']);
            // Staff alert evidence: a due schedule whose guards fail stays pending and records why.
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('last_attempt_at')->nullable();
            $table->dateTime('last_failure_at')->nullable();
            $table->string('last_failure_reason', 1000)->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('outcome', 32)->nullable();
            $table->unsignedInteger('publication_version')->nullable();
            $table->index(['state', 'publish_at']);
        });

        $sqlite = DB::getDriverName() === 'sqlite';
        $wholeMinute = $sqlite ? "strftime('%S', NEW.publish_at) = '00'" : 'SECOND(NEW.publish_at) = 0';
        $targets = "'".implode("','", TrackVisibility::TARGETS)."'";

        $this->guard('track_publication_schedules', 'retain', 'DELETE');
        $this->guard('track_publication_schedules', 'valid_insert', 'INSERT',
            "NEW.state = 'pending' AND NEW.pending_slot = 1 AND NEW.resolved_at IS NULL AND NEW.resolved_by IS NULL AND NEW.outcome IS NULL"
            .' AND NEW.publication_version IS NULL AND NEW.attempts = 0 AND NEW.last_attempt_at IS NULL AND NEW.last_failure_at IS NULL'
            ." AND NEW.last_failure_reason IS NULL AND NEW.target_status IN ({$targets}) AND NEW.entry_zone = 'America/Chicago'"
            .' AND NEW.publish_at > NEW.created_at AND '.$wholeMinute
            ." AND EXISTS (SELECT 1 FROM tracks t WHERE t.id = NEW.track_id AND t.status IN ('draft','scheduled'))");

        $identity = 'NEW.id = OLD.id AND NEW.track_id = OLD.track_id AND NEW.target_status = OLD.target_status AND NEW.publish_at = OLD.publish_at'
            .' AND NEW.entry_zone = OLD.entry_zone AND NEW.entry_local = OLD.entry_local AND NEW.created_by = OLD.created_by AND NEW.created_at = OLD.created_at';
        // A failed attempt changes only the alert evidence and leaves the schedule pending.
        $attempt = "NEW.state = 'pending' AND NEW.pending_slot = 1 AND NEW.resolved_at IS NULL AND NEW.resolved_by IS NULL AND NEW.outcome IS NULL"
            .' AND NEW.publication_version IS NULL AND NEW.attempts >= OLD.attempts AND NEW.last_attempt_at IS NOT NULL'
            .' AND NEW.last_attempt_at >= OLD.publish_at';
        $resolved = 'NEW.pending_slot IS NULL AND NEW.resolved_at IS NOT NULL AND NEW.resolved_at >= OLD.created_at';
        // Written in the activation transaction before the track moves; the track guard then requires this exact row.
        $published = "NEW.state = 'published' AND NEW.outcome = 'published' AND NEW.resolved_by IS NULL AND NEW.resolved_at >= OLD.publish_at"
            .' AND NEW.publication_version = (SELECT t.publication_version + 1 FROM tracks t WHERE t.id = OLD.track_id)'
            ." AND EXISTS (SELECT 1 FROM tracks t WHERE t.id = OLD.track_id AND t.status = 'scheduled')";
        $cancelled = "NEW.state = 'cancelled' AND NEW.outcome = 'cancelled' AND NEW.resolved_by IS NOT NULL AND NEW.publication_version IS NULL";
        // Rescheduling replaces the pending row; the track stays scheduled and the replacement is inserted in the same transaction.
        $superseded = "NEW.state = 'superseded' AND NEW.outcome = 'rescheduled' AND NEW.resolved_by IS NOT NULL AND NEW.publication_version IS NULL";
        $this->guard('track_publication_schedules', 'transition', 'UPDATE',
            "OLD.state = 'pending' AND OLD.pending_slot = 1 AND {$identity} AND (({$attempt}) OR ({$resolved} AND (({$published}) OR ({$cancelled}) OR ({$superseded}))))");

        $states = "'".implode("','", TrackVisibility::STATES)."'";
        $live = TrackVisibility::liveSql();
        $pairs = [];
        foreach (TrackVisibility::TRANSITIONS as $from => $tos) {
            $pairs[] = "(OLD.status = '{$from}' AND NEW.status IN ('".implode("','", $tos)."'))";
        }
        $pending = "EXISTS (SELECT 1 FROM track_publication_schedules s WHERE s.track_id = NEW.id AND s.state = 'pending' AND s.pending_slot = 1)";
        $activated = 'EXISTS (SELECT 1 FROM track_publication_schedules s WHERE s.track_id = NEW.id'
            ." AND s.state = 'published' AND s.target_status = NEW.status AND s.publication_version = NEW.publication_version)";
        // Never `scheduled` at birth: no schedule row can exist before its track.
        $this->guard('tracks', 'visibility_insert', 'INSERT', "NEW.status IN ({$states}) AND NEW.status <> 'scheduled'"
            ." AND (NEW.status <> 'unlisted' OR NEW.published_slug IS NOT NULL)");
        $this->guard('tracks', 'visibility_update', 'UPDATE',
            "NEW.status IN ({$states}) AND (NEW.status = OLD.status OR ".implode(' OR ', $pairs).')'
            ." AND (NEW.status <> 'scheduled' OR {$pending})"
            ." AND (OLD.status <> 'scheduled' OR NEW.status = 'scheduled' OR NOT {$pending})"
            ." AND (OLD.status <> 'scheduled' OR NEW.status NOT IN ({$live}) OR {$activated})"
            ." AND (NEW.status <> 'unlisted' OR NEW.published_slug IS NOT NULL)");
    }

    private function guard(string $table, string $name, string $operation, ?string $valid = null): void
    {
        $name = $table === 'tracks' ? 'tracks_'.$name : $table.'_'.$name;
        if ($valid !== null && DB::getDriverName() === 'mysql') {
            // utf8mb4_unicode_ci compares case- and trailing-space-insensitively, so a raw 'Published' or 'published ' would
            // pass an `IN ('published')` test while every exact-match reader treats it as an unknown state. Compare bytes.
            $valid = preg_replace('/\b((?:NEW|OLD|s|t)\.(?:status|state|target_status|outcome|entry_zone))\b/', 'CAST($1 AS BINARY)', $valid);
        }
        if (DB::getDriverName() === 'sqlite') {
            $when = $valid === null ? '' : ' WHEN NOT COALESCE(('.$valid.'), 0)';
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table}{$when} BEGIN SELECT RAISE(ABORT, '".self::MESSAGE."'); END");
        } else {
            $body = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MESSAGE."';";
            if ($valid !== null) {
                $body = "IF NOT COALESCE(({$valid}), 0) THEN {$body} END IF;";
            }
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW BEGIN {$body} END");
        }
    }

    public function down(): void
    {
        if (DB::table('track_publication_schedules')->exists()) {
            throw new LogicException('Track publication schedule history must be retained; populated migration rollback is refused.');
        }
        if (DB::table('tracks')->whereIn('status', ['scheduled', 'unlisted', 'private'])->exists()) {
            throw new LogicException('Tracks use the scheduled, unlisted or private state; return them to draft before rolling back.');
        }
        foreach (['tracks_visibility_insert', 'tracks_visibility_update', 'track_publication_schedules_retain', 'track_publication_schedules_valid_insert', 'track_publication_schedules_transition'] as $trigger) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$trigger);
        }
        Schema::dropIfExists('track_publication_schedules');
    }
};
