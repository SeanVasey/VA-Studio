<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracks', function (Blueprint $table) {
            $table->string('published_slug')->nullable();
            $table->unsignedInteger('metadata_version')->default(0);
        });
        // Preserve observed URL identity, including unpublished history, without inventing dates or edits.
        DB::table('tracks')->where('status', 'published')->orWhereNotNull('published_at')
            ->update(['published_slug' => DB::raw('slug')]);

        if (DB::getDriverName() === 'sqlite') {
            $invalid = "(NEW.published_slug IS NOT NULL AND NEW.slug IS NOT NEW.published_slug) OR ((NEW.status = 'published' OR NEW.published_at IS NOT NULL) AND NEW.published_slug IS NULL)";
            DB::unprepared("CREATE TRIGGER tracks_public_url_insert BEFORE INSERT ON tracks WHEN {$invalid} BEGIN SELECT RAISE(ABORT, 'Published track URL must be reserved'); END");
            DB::unprepared("CREATE TRIGGER tracks_public_url_update BEFORE UPDATE ON tracks WHEN ({$invalid}) OR (OLD.published_slug IS NOT NULL AND NEW.published_slug IS NOT OLD.published_slug) BEGIN SELECT RAISE(ABORT, 'Published track URL is permanent'); END");
            DB::unprepared("CREATE TRIGGER tracks_public_url_delete BEFORE DELETE ON tracks WHEN OLD.published_slug IS NOT NULL BEGIN SELECT RAISE(ABORT, 'Published track URL must be retained'); END");
        } elseif (DB::getDriverName() === 'mysql') {
            // Binary, null-safe comparisons also reject case-only rewrites on a case-insensitive database.
            $invalid = "(NEW.published_slug IS NOT NULL AND NOT (BINARY NEW.slug <=> BINARY NEW.published_slug)) OR ((NEW.status = 'published' OR NEW.published_at IS NOT NULL) AND NEW.published_slug IS NULL)";
            DB::unprepared("CREATE TRIGGER tracks_public_url_insert BEFORE INSERT ON tracks FOR EACH ROW BEGIN IF {$invalid} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Published track URL must be reserved'; END IF; END");
            DB::unprepared("CREATE TRIGGER tracks_public_url_update BEFORE UPDATE ON tracks FOR EACH ROW BEGIN IF ({$invalid}) OR (OLD.published_slug IS NOT NULL AND NOT (BINARY NEW.published_slug <=> BINARY OLD.published_slug)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Published track URL is permanent'; END IF; END");
            DB::unprepared("CREATE TRIGGER tracks_public_url_delete BEFORE DELETE ON tracks FOR EACH ROW BEGIN IF OLD.published_slug IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Published track URL must be retained'; END IF; END");
        }
    }

    public function down(): void
    {
        foreach (['insert', 'update', 'delete'] as $operation) {
            DB::unprepared('DROP TRIGGER IF EXISTS tracks_public_url_'.$operation);
        }
        Schema::table('tracks', fn (Blueprint $table) => $table->dropColumn(['published_slug', 'metadata_version']));
    }
};
