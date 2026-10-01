<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const LEGACY_GUARD = 'site_release_images_valid_insert';

    private const RELATED_TRACKS_GUARD = 'site_release_images_valid_insert_v4';

    public function up(): void
    {
        // MySQL DDL commits independently. Keep the old guard until its protective successor exists.
        $this->installInsertGuard(self::RELATED_TRACKS_GUARD, true);
        DB::unprepared('DROP TRIGGER '.self::LEGACY_GUARD);
    }

    public function down(): void
    {
        // An image-free v4 release is still retained evidence that older application code cannot read.
        if (DB::table('site_releases')->where('schema_version', 4)->exists()) {
            throw new LogicException('Schema 4 site releases must be retained; image compatibility rollback is refused.');
        }

        // Restore the restrictive predecessor before removing the successor; never leave inserts unguarded.
        $this->installInsertGuard(self::LEGACY_GUARD, false);
        DB::unprepared('DROP TRIGGER '.self::RELATED_TRACKS_GUARD);
    }

    private function installInsertGuard(string $name, bool $relatedTracks): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql'], true)) {
            throw new LogicException('Site release image guards require SQLite or MySQL.');
        }
        // MySQL's ordinary collation ignores case and trailing spaces. These are exact slot/status spellings.
        $slot = $driver === 'mysql' ? 'CAST(NEW.slot AS BINARY)' : 'NEW.slot';
        $imageSlot = $driver === 'mysql' ? 'CAST(i.slot AS BINARY)' : 'i.slot';
        $status = $driver === 'mysql' ? 'CAST(i.status AS BINARY)' : 'i.status';
        $schema = $relatedTracks ? 'r.schema_version IN (3, 4)' : 'r.schema_version = 3';
        $valid = "{$slot} IN ('hero_desktop', 'hero_mobile', 'studio', 'share')"
            ." AND EXISTS (SELECT 1 FROM site_images i WHERE i.id = NEW.site_image_id AND {$status} = 'ready' AND {$imageSlot} = {$slot})"
            ." AND EXISTS (SELECT 1 FROM site_releases r WHERE r.id = NEW.site_release_id AND {$schema})"
            .' AND NOT EXISTS (SELECT 1 FROM site_publication_revisions h WHERE h.release_id = NEW.site_release_id)'
            .' AND NOT EXISTS (SELECT 1 FROM site_publication_schedules s WHERE s.release_id = NEW.site_release_id)';

        if ($driver === 'sqlite') {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE INSERT ON site_release_images WHEN NOT COALESCE(({$valid}), 0) BEGIN SELECT RAISE(ABORT, 'Site release image evidence is invalid or immutable'); END");
        } else {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE INSERT ON site_release_images FOR EACH ROW BEGIN IF NOT COALESCE(({$valid}), 0) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Site release image evidence is invalid or immutable'; END IF; END");
        }
    }
};
