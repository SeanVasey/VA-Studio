<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes the site images each release references (D-25), so "has this image ever been live" is a join with publication history.
 * Rows are written with their release and never change; content() checks them against the release's own references.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_release_images', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_release_id')->constrained('site_releases')->restrictOnDelete();
            $table->string('slot', 16);
            $table->foreignId('site_image_id')->constrained('site_images')->restrictOnDelete();
            $table->dateTime('created_at');
            $table->unique(['site_release_id', 'slot']);
            $table->index('site_image_id');
        });

        foreach (['retain' => 'delete', 'immutable' => 'update'] as $suffix => $operation) {
            $this->guard($suffix, $operation);
        }
        // Only a ready image of the same slot, only for an image-bearing release, and only before that release is scheduled or published.
        $this->guard('valid_insert', 'insert',
            "NEW.slot IN ('hero_desktop', 'hero_mobile', 'studio', 'share')"
            ." AND EXISTS (SELECT 1 FROM site_images i WHERE i.id = NEW.site_image_id AND i.status = 'ready' AND i.slot = NEW.slot)"
            .' AND EXISTS (SELECT 1 FROM site_releases r WHERE r.id = NEW.site_release_id AND r.schema_version = 3)'
            .' AND NOT EXISTS (SELECT 1 FROM site_publication_revisions h WHERE h.release_id = NEW.site_release_id)'
            .' AND NOT EXISTS (SELECT 1 FROM site_publication_schedules s WHERE s.release_id = NEW.site_release_id)');
    }

    private function guard(string $suffix, string $operation, ?string $valid = null): void
    {
        $name = 'site_release_images_'.$suffix;
        if ($valid !== null && DB::getDriverName() === 'mysql') {
            $valid = $this->bytewise($valid);
        }
        if (DB::getDriverName() === 'sqlite') {
            $when = $valid === null ? '' : ' WHEN NOT COALESCE(('.$valid.'), 0)';
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON site_release_images{$when} BEGIN SELECT RAISE(ABORT, 'Site release image evidence is invalid or immutable'); END");
        } elseif (DB::getDriverName() === 'mysql') {
            $body = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Site release image evidence is invalid or immutable';";
            if ($valid !== null) { $body = "IF NOT COALESCE(({$valid}), 0) THEN {$body} END IF;"; }
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON site_release_images FOR EACH ROW BEGIN {$body} END");
        }
    }

    /**
     * Makes a MySQL guard condition compare slot and status spellings byte for byte, as SQLite does, even under MySQL's
     * case-insensitive PAD SPACE default collation. Only whole names are wrapped, so a column such as i.slot_group is never
     * read as i.slot plus a suffix.
     */
    public function bytewise(string $condition): string
    {
        $names = implode('|', array_map(fn (string $column): string => preg_quote($column, '/'), ['NEW.slot', 'i.slot', 'i.status']));

        return preg_replace('/(?<![\w$.])(?:'.$names.')(?![\w$])/', 'CAST($0 AS BINARY)', $condition)
            ?? throw new \LogicException('The guard condition could not be rewritten.');
    }

    public function down(): void
    {
        if (DB::table('site_release_images')->exists()) {
            throw new \LogicException('Site release image references must be retained; populated migration rollback is refused.');
        }
        foreach (['retain', 'immutable', 'valid_insert'] as $suffix) {
            DB::unprepared('DROP TRIGGER IF EXISTS site_release_images_'.$suffix);
        }
        Schema::dropIfExists('site_release_images');
    }
};
