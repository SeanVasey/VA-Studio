<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const LEGACY_GUARD = 'site_release_images_valid_insert';

    private const RELATED_TRACKS_GUARD = 'site_release_images_valid_insert_v4';

    public function up(): void
    {
        $this->requireDriver();
        $this->refuseTemporaryShadows();
        $installed = $this->preflight();
        // MySQL DDL commits independently. Keep the old guard until its protective successor exists.
        if (! $installed[self::RELATED_TRACKS_GUARD]) {
            DB::unprepared($this->insertGuard(self::RELATED_TRACKS_GUARD, true)['statement']);
        }
        if ($installed[self::LEGACY_GUARD]) {
            DB::unprepared('DROP TRIGGER '.self::LEGACY_GUARD);
        }
    }

    public function down(): void
    {
        $this->requireDriver();
        $this->refuseTemporaryShadows();
        // An image-free v4 release is still retained evidence that older application code cannot read.
        if (DB::table('site_releases')->where('schema_version', 4)->exists()) {
            throw new LogicException('Schema 4 site releases must be retained; image compatibility rollback is refused.');
        }

        $installed = $this->preflight();
        // Restore the restrictive predecessor before removing the successor; never leave inserts unguarded.
        if (! $installed[self::LEGACY_GUARD]) {
            DB::unprepared($this->insertGuard(self::LEGACY_GUARD, false)['statement']);
        }
        if ($installed[self::RELATED_TRACKS_GUARD]) {
            DB::unprepared('DROP TRIGGER '.self::RELATED_TRACKS_GUARD);
        }
    }

    /** Validate both names before the first DDL, including a predecessor that will be removed. */
    private function preflight(): array
    {
        $this->requireDriver();
        $installed = [];
        foreach ([self::LEGACY_GUARD => false, self::RELATED_TRACKS_GUARD => true] as $name => $relatedTracks) {
            $definition = $this->insertGuard($name, $relatedTracks);
            if (DB::getDriverName() === 'sqlite') {
                $existing = DB::table('sqlite_master')->where('type', 'trigger')->whereRaw('name COLLATE NOCASE = ?', [$name])->first();
                // Migration 000028 emitted lowercase "insert"; this migration's down emits
                // uppercase "INSERT". These are the only two owned predecessor spellings.
                $owned = [$definition['statement']];
                if (! $relatedTracks) {
                    $owned[] = str_replace(' BEFORE INSERT ON ', ' BEFORE insert ON ', $definition['statement']);
                }
                $matches = $existing === null || ($existing->name === $name && $existing->tbl_name === 'site_release_images'
                    && in_array($existing->sql, $owned, true));
            } else {
                $existing = DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())
                    ->where('TRIGGER_NAME', $name)->first();
                $matches = $existing === null || ($existing->TRIGGER_NAME === $name && $existing->EVENT_OBJECT_TABLE === 'site_release_images'
                    && $existing->ACTION_TIMING === 'BEFORE' && $existing->EVENT_MANIPULATION === 'INSERT'
                    && $existing->ACTION_STATEMENT === $definition['body']);
            }
            if (! $matches) {
                throw new LogicException("Unexpected site release image guard definition for {$name}; existing guards and evidence are unchanged.");
            }
            $installed[$name] = $existing !== null;
        }
        if (! in_array(true, $installed, true)) {
            throw new LogicException('Site release image insert protection is missing; investigate before retrying.');
        }

        return $installed;
    }

    private function requireDriver(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new LogicException('Site release image guards require SQLite or MySQL.');
        }
    }

    private function refuseTemporaryShadows(): void
    {
        $tables = ['site_release_images', 'site_releases', 'site_images', 'site_publication_revisions', 'site_publication_schedules'];
        if (DB::getDriverName() === 'sqlite') {
            $names = array_merge($tables, [self::LEGACY_GUARD, self::RELATED_TRACKS_GUARD,
                'site_release_images_retain', 'site_release_images_immutable']);
            if (DB::table('sqlite_temp_master')->whereIn(DB::raw('name COLLATE NOCASE'), $names)
                ->orWhereIn(DB::raw('tbl_name COLLATE NOCASE'), $tables)->exists()) {
                throw new LogicException('Temporary site image object shadows retained evidence; existing guards and evidence are unchanged.');
            }

            return;
        }
        foreach ($tables as $table) {
            try {
                $definition = DB::selectOne('SHOW CREATE TABLE '.DB::connection()->getQueryGrammar()->wrapTable($table));
            } catch (QueryException $error) {
                if (($error->errorInfo[0] ?? null) === '42S02' && ($error->errorInfo[1] ?? null) === 1146) {
                    continue;
                }
                throw $error;
            }
            if (str_starts_with($definition->{'Create Table'} ?? '', 'CREATE TEMPORARY TABLE ')) {
                throw new LogicException('Temporary site image table shadows retained evidence; existing guards and evidence are unchanged.');
            }
        }
    }

    private function insertGuard(string $name, bool $relatedTracks): array
    {
        $driver = DB::getDriverName();
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
            return ['statement' => "CREATE TRIGGER {$name} BEFORE INSERT ON site_release_images WHEN NOT COALESCE(({$valid}), 0) BEGIN SELECT RAISE(ABORT, 'Site release image evidence is invalid or immutable'); END"];
        }

        $body = "BEGIN IF NOT COALESCE(({$valid}), 0) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Site release image evidence is invalid or immutable'; END IF; END";

        return ['statement' => "CREATE TRIGGER {$name} BEFORE INSERT ON site_release_images FOR EACH ROW {$body}", 'body' => $body];
    }
};
