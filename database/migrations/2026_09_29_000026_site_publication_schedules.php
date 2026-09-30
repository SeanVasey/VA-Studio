<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_publication_schedules', function (Blueprint $table): void {
            $table->id(); $table->foreignId('release_id')->constrained('site_releases')->restrictOnDelete();
            $table->string('release_content_hash', 64); $table->dateTime('publish_at'); $table->unsignedInteger('expected_revision');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete(); $table->dateTime('created_at');
            // A pending row holds the only slot; resolved rows release it (NULL), so one pending schedule exists at most.
            $table->string('state', 16); $table->unsignedTinyInteger('pending_slot')->nullable()->unique();
            $table->dateTime('resolved_at')->nullable(); $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('outcome', 32)->nullable(); $table->unsignedInteger('publication_revision')->nullable()->unique();
            $table->index(['state', 'publish_at']);
        });
        $sqlite = DB::getDriverName() === 'sqlite';
        $wholeMinute = $sqlite ? "strftime('%S', NEW.publish_at) = '00'" : 'SECOND(NEW.publish_at) = 0';
        $graceEnd = $sqlite ? "datetime(OLD.publish_at, '+60 minutes')" : 'OLD.publish_at + INTERVAL 60 MINUTE';
        $this->guard('site_publication_schedules', 'retain', 'delete');
        $this->guard('site_publication_schedules', 'valid_insert', 'insert',
            "NEW.state = 'pending' AND NEW.pending_slot = 1 AND NEW.resolved_at IS NULL AND NEW.resolved_by IS NULL"
            .' AND NEW.outcome IS NULL AND NEW.publication_revision IS NULL AND NEW.publish_at > NEW.created_at AND '.$wholeMinute
            .' AND EXISTS (SELECT 1 FROM site_releases r WHERE r.id = NEW.release_id AND r.content_hash = NEW.release_content_hash)'
            .' AND EXISTS (SELECT 1 FROM site_publications p WHERE p.id = 1 AND p.revision = NEW.expected_revision'
            .' AND (p.active_release_id IS NULL OR p.active_release_id <> NEW.release_id))');
        $identity = "OLD.state = 'pending' AND OLD.pending_slot = 1 AND NEW.id = OLD.id AND NEW.release_id = OLD.release_id"
            .' AND NEW.release_content_hash = OLD.release_content_hash AND NEW.publish_at = OLD.publish_at'
            .' AND NEW.expected_revision = OLD.expected_revision AND NEW.created_by = OLD.created_by AND NEW.created_at = OLD.created_at'
            .' AND NEW.pending_slot IS NULL AND NEW.resolved_at IS NOT NULL AND NEW.resolved_at >= OLD.created_at';
        // Publication is recorded in the same transaction after its history row and pointer advance.
        $published = "NEW.state = 'published' AND NEW.outcome = 'published' AND NEW.resolved_by IS NULL"
            .' AND NEW.publication_revision = OLD.expected_revision + 1 AND NEW.resolved_at >= OLD.publish_at AND NEW.resolved_at <= '.$graceEnd
            ." AND EXISTS (SELECT 1 FROM site_publication_revisions h WHERE h.revision = NEW.publication_revision AND h.release_id = OLD.release_id AND h.operation = 'publish' AND h.actor_id = OLD.created_by AND h.content_hash = OLD.release_content_hash)"
            .' AND EXISTS (SELECT 1 FROM site_publications p WHERE p.id = 1 AND p.revision = NEW.publication_revision AND p.active_release_id = OLD.release_id)';
        $cancelled = "NEW.state = 'cancelled' AND NEW.outcome = 'cancelled' AND NEW.resolved_by IS NOT NULL AND NEW.publication_revision IS NULL";
        // A staff publication or restore replaced the reviewed site: link that exact revision, operator and pointer.
        $superseded = "NEW.state = 'superseded' AND NEW.outcome IN ('manual_publish', 'manual_rollback') AND NEW.resolved_by IS NOT NULL"
            .' AND NEW.publication_revision > OLD.expected_revision'
            .' AND EXISTS (SELECT 1 FROM site_publication_revisions h WHERE h.revision = NEW.publication_revision AND h.actor_id = NEW.resolved_by'
            ." AND ((NEW.outcome = 'manual_publish' AND h.operation = 'publish') OR (NEW.outcome = 'manual_rollback' AND h.operation = 'rollback')))"
            .' AND EXISTS (SELECT 1 FROM site_publications p WHERE p.id = 1 AND p.revision = NEW.publication_revision)';
        $failed = "NEW.state = 'failed' AND NEW.outcome IN ('actor_unauthorized', 'stale_revision', 'integrity')"
            .' AND NEW.resolved_by IS NULL AND NEW.publication_revision IS NULL AND NEW.resolved_at >= OLD.publish_at';
        $expired = "NEW.state = 'expired' AND NEW.outcome = 'grace_expired' AND NEW.resolved_by IS NULL AND NEW.publication_revision IS NULL"
            .' AND NEW.resolved_at > '.$graceEnd;
        $this->guard('site_publication_schedules', 'transition', 'update',
            $identity.' AND (('.$published.') OR ('.$cancelled.') OR ('.$superseded.') OR ('.$failed.') OR ('.$expired.'))');
    }

    private function guard(string $table, string $suffix, string $operation, ?string $valid = null): void
    {
        $name = $table.'_'.$suffix;
        if (DB::getDriverName() === 'sqlite') {
            $when = $valid === null ? '' : ' WHEN NOT COALESCE(('.$valid.'), 0)';
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table}{$when} BEGIN SELECT RAISE(ABORT, 'Site publication schedule evidence is invalid or immutable'); END");
        } elseif (DB::getDriverName() === 'mysql') {
            $body = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Site publication schedule evidence is invalid or immutable';";
            if ($valid !== null) { $body = "IF NOT COALESCE(({$valid}), 0) THEN {$body} END IF;"; }
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW BEGIN {$body} END");
        }
    }

    public function down(): void
    {
        if (DB::table('site_publication_schedules')->exists()) {
            throw new \LogicException('Site publication schedule history must be retained; populated migration rollback is refused.');
        }
        foreach (['retain', 'valid_insert', 'transition'] as $suffix) { DB::unprepared('DROP TRIGGER IF EXISTS site_publication_schedules_'.$suffix); }
        Schema::dropIfExists('site_publication_schedules');
    }
};
