<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_releases', function (Blueprint $table): void {
            $table->id(); $table->string('label', 120); $table->unsignedInteger('schema_version');
            $table->longText('content'); $table->string('content_hash', 64); $table->string('canonicalization_version', 32);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete(); $table->dateTime('created_at');
        });
        Schema::create('site_publications', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary(); $table->unsignedInteger('revision');
            $table->foreignId('active_release_id')->nullable()->constrained('site_releases')->restrictOnDelete();
            $table->dateTime('updated_at')->nullable();
        });
        Schema::create('site_publication_revisions', function (Blueprint $table): void {
            $table->id(); $table->unsignedInteger('revision')->unique();
            $table->foreignId('release_id')->constrained('site_releases')->restrictOnDelete();
            $table->foreignId('previous_release_id')->nullable()->constrained('site_releases')->restrictOnDelete();
            $table->string('operation', 16); $table->string('content_hash', 64);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete(); $table->dateTime('created_at');
        });
        DB::table('site_publications')->insert(['id' => 1, 'revision' => 0, 'active_release_id' => null, 'updated_at' => null]);
        foreach (['site_releases', 'site_publication_revisions'] as $table) {
            foreach (['update', 'delete'] as $operation) {
                $this->guard($table, 'immutable_'.$operation, $operation);
            }
        }
        $this->guard('site_publications', 'retain', 'delete');
        $this->guard('site_publications', 'singleton', 'insert', 'NEW.id = 1 AND NEW.revision = 0 AND NEW.active_release_id IS NULL AND NEW.updated_at IS NULL');
        $pointerShape = 'NEW.id = OLD.id AND NEW.id = 1 AND NEW.revision = OLD.revision + 1 AND NEW.revision BETWEEN 1 AND 2147483646 AND NEW.active_release_id IS NOT NULL AND NEW.updated_at IS NOT NULL';
        $history = 'EXISTS (SELECT 1 FROM site_publication_revisions r WHERE r.revision = NEW.revision AND r.release_id = NEW.active_release_id';
        $previous = DB::getDriverName() === 'sqlite' ? 'r.previous_release_id IS OLD.active_release_id' : 'r.previous_release_id <=> OLD.active_release_id';
        $this->guard('site_publications', 'transition', 'update', $pointerShape.' AND '.$history.' AND '.$previous.')');
        $matchPrevious = DB::getDriverName() === 'sqlite' ? 'NEW.previous_release_id IS p.active_release_id' : 'NEW.previous_release_id <=> p.active_release_id';
        $advance = "NEW.operation IN ('publish', 'rollback') AND NEW.revision BETWEEN 1 AND 2147483646"
            .' AND EXISTS (SELECT 1 FROM site_publications p WHERE p.id = 1 AND NEW.revision = p.revision + 1 AND '.$matchPrevious.')';
        $baseline = "NEW.operation = 'baseline' AND NEW.revision = 0 AND NEW.previous_release_id IS NULL"
            .' AND EXISTS (SELECT 1 FROM site_publications p WHERE p.id = 1 AND p.revision = 0 AND p.active_release_id IS NULL)';
        $this->guard('site_publication_revisions', 'transition', 'insert',
            '(('.$advance.') OR ('.$baseline.')) AND EXISTS (SELECT 1 FROM site_releases r WHERE r.id = NEW.release_id AND r.content_hash = NEW.content_hash)');
    }

    private function guard(string $table, string $suffix, string $operation, ?string $valid = null): void
    {
        $name = $table.'_'.$suffix;
        if (DB::getDriverName() === 'sqlite') {
            $when = $valid === null ? '' : ' WHEN NOT COALESCE(('.$valid.'), 0)';
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table}{$when} BEGIN SELECT RAISE(ABORT, 'Site content evidence is invalid or immutable'); END");
        } elseif (DB::getDriverName() === 'mysql') {
            $body = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Site content evidence is invalid or immutable';";
            if ($valid !== null) { $body = "IF NOT COALESCE(({$valid}), 0) THEN {$body} END IF;"; }
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW BEGIN {$body} END");
        }
    }

    public function down(): void
    {
        if (DB::table('site_releases')->exists() || DB::table('site_publication_revisions')->exists()
            || DB::table('site_publications')->where('revision', '>', 0)->exists()) {
            throw new \LogicException('Site content history must be retained; populated migration rollback is refused.');
        }
        foreach (['site_releases', 'site_publication_revisions'] as $table) {
            foreach (['update', 'delete'] as $operation) { DB::unprepared('DROP TRIGGER IF EXISTS '.$table.'_immutable_'.$operation); }
        }
        foreach (['retain', 'singleton', 'transition'] as $suffix) { DB::unprepared('DROP TRIGGER IF EXISTS site_publications_'.$suffix); }
        DB::unprepared('DROP TRIGGER IF EXISTS site_publication_revisions_transition');
        Schema::dropIfExists('site_publication_revisions');
        Schema::dropIfExists('site_publications');
        Schema::dropIfExists('site_releases');
    }
};
