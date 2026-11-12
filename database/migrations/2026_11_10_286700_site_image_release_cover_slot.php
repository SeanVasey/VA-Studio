<?php

use App\Support\MigrationDefinitions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * BL-67: the albums page reuses the site image pipeline (D-25) for square release cover art, so the intake trigger must admit the
 * new `release_cover` slot (profile site-image-v2). Nothing else changes: the ready trigger already requires six variants for
 * every slot but `share`, which is exactly what the cover slot produces (three widths, JPEG and WebP).
 */
return new class extends Migration
{
    private const ORIGINAL = "'hero_desktop', 'hero_mobile', 'studio', 'share'";

    private const WITH_COVER = "'hero_desktop', 'hero_mobile', 'studio', 'share', 'release_cover'";

    public function up(): void
    {
        $this->replace(self::WITH_COVER);
    }

    public function down(): void
    {
        if (DB::table('site_images')->where('slot', 'release_cover')->exists()) {
            throw new LogicException('Release cover images and their provenance must be retained; populated migration rollback is refused.');
        }
        $this->replace(self::ORIGINAL);
    }

    private function replace(string $slots): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql'], true)) {
            throw new LogicException('Site images require SQLite or MySQL.');
        }
        $valid = "NEW.status = 'quarantined' AND NEW.attempts = 0 AND NEW.claim_token IS NULL AND NEW.claimed_until IS NULL"
            .' AND NEW.failure_code IS NULL AND NEW.profile_version IS NULL AND NEW.profile_fingerprint IS NULL AND NEW.evidence IS NULL'
            .' AND NEW.manifest_sha256 IS NULL AND NEW.processed_at IS NULL'
            ." AND NEW.slot IN ({$slots}) AND NEW.mime_type IN ('image/jpeg', 'image/png') AND LENGTH(NEW.source_sha256) = 64"
            ." AND SUBSTR(NEW.source_path, 1, 23) = 'site-images/quarantine/' AND NEW.size_bytes > 0 AND NEW.width > 0 AND NEW.height > 0"
            .' AND LENGTH(TRIM(NEW.credit)) > 0';
        DB::unprepared('DROP TRIGGER IF EXISTS site_images_valid_insert');
        if ($driver === 'sqlite') {
            DB::unprepared("CREATE TRIGGER site_images_valid_insert BEFORE insert ON site_images WHEN NOT COALESCE(({$valid}), 0) BEGIN SELECT RAISE(ABORT, 'Site image evidence is invalid or immutable'); END");

            return;
        }
        // The creating migration's own byte-for-byte rewrite, so MySQL compares slots and paths exactly as it always has.
        $original = MigrationDefinitions::load('2026_09_30_000027_site_images.php');
        $valid = $original->bytewise($valid);
        DB::unprepared("CREATE TRIGGER site_images_valid_insert BEFORE insert ON site_images FOR EACH ROW BEGIN IF NOT COALESCE(({$valid}), 0) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Site image evidence is invalid or immutable'; END IF; END");
    }
};
