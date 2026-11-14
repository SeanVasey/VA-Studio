<?php

namespace App\Domain\Memberships\Production;

use App\Domain\Catalog\Models\Offer;
use App\Domain\Catalog\Models\Track;
use App\Domain\Commerce\ProductionCheckout\CurrentSelection;
use App\Domain\Commerce\ProductionPreparation\PreparationSelection;
use App\Domain\Media\PrivateMediaFiles;
use App\Support\CanonicalJson;
use App\Support\Money\MinorUnits;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Pdo\Mysql;
use Pdo\Sqlite;
use PDOStatement;

/** Current catalog evidence only; no credit cost, membership policy, grant readiness or authority mint. */
final class MembershipCatalogEvidence
{
    private const TABLES = ['tracks', 'offers', 'rights_declarations', 'media_assets', 'stems_recordings',
        'offer_revisions', 'license_versions', 'license_templates', 'license_review_evidence',
        'media_processing_runs', 'rights_scope_offers', 'audit_events'];

    /** Caller owns T23 and billing/policy locks before entering the catalog footprint. */
    public static function lock(MembershipRows $rows, string $locator, int $expectedRevision, CarbonImmutable $at, int $deadlineNs, int $startedNs): MembershipCatalogSnapshot
    {
        MembershipException::require(preg_match('/\A[1-9][0-9]{0,15}\z/D', $locator) === 1
            && (string) (int) $locator === $locator && (int) $locator <= MinorUnits::MAX
            && $expectedRevision > 0 && $expectedRevision <= MinorUnits::MAX, 'invalid_selection');
        $rows->assertCurrent();
        $pdo = $rows->identity();
        $driver = $rows->driver();
        $schema = self::schema($pdo, $driver);
        // Open every original table before SHOW: the transaction retains their MySQL metadata locks.
        // SHOW then checks current engine/source identity, including local temporary shadows.
        foreach (self::TABLES as $table) {
            self::query($pdo, 'SELECT * FROM '.self::qualified($schema, $table).' LIMIT 0', []);
        }
        $definitions = self::definitions($pdo, $driver, $schema);
        // Routing reads are nonlocking. CurrentSelection acquires its complete footprint in its
        // established track/offer order and must agree with these selected identities afterward.
        $offers = self::query($pdo, 'SELECT * FROM '.self::qualified($schema, 'offers').' WHERE id = ? LIMIT 2', [(int) $locator]);
        $revisions = self::query($pdo, 'SELECT * FROM '.self::qualified($schema, 'offer_revisions').' WHERE id = ? LIMIT 2', [$expectedRevision]);
        MembershipException::require(count($offers) === 1 && count($revisions) === 1, 'selection_absent');
        $offer = $offers[0];
        $revision = $revisions[0];
        MembershipException::require($offer['current_revision_id'] === $expectedRevision && $revision['offer_id'] === $offer['id']
            && $revision['track_id'] === $offer['track_id'], 'stale_selection');
        $item = ['trackId' => $offer['track_id'], 'offerId' => $offer['id'], 'offerRevisionId' => $revision['id'], 'licenseVersionId' => $revision['license_version_id']];
        $selection = CurrentSelection::load($rows->current(), [$item], $at);
        $rows->assertCurrent();
        $binding = self::binding($selection, $at);
        // The original wall/monotonic pair caps effective-until through the last raw closure.
        // A late proof or receipt cannot renew the remaining license lifetime.
        $remainingMicros = $binding->validUntilUnixMicros - (int) $at->format('Uu');
        MembershipException::require($remainingMicros > 0 && $remainingMicros <= 60_000_000, 'expired_license_proof');
        $deadlineNs = min($deadlineNs, $startedNs + $remainingMicros * 1000);
        $files = self::filePlans($selection);
        $plans = self::plans($pdo, $schema, $selection);
        $snapshot = new MembershipCatalogSnapshot($pdo, $driver, $schema, $definitions, $plans, $selection, $files, $binding, $deadlineNs);
        self::assertCurrent($snapshot, $pdo, $driver);

        return $snapshot;
    }

    /** Callback-capable storage resolution/file work precedes the caller's last raw fence. */
    public static function proveBytes(MembershipCatalogSnapshot $snapshot): void
    {
        CurrentSelection::proveBytes($snapshot->selection);
    }

    /**
     * Captured native files/PDO only. No clock, facade, container, canonicalizer, renderer or resolver callback.
     * Use locking=false in the original committed READ ONLY frame. This is evidence comparison,
     * never proof issuance; the owned consumer supplies frame, configuration, identity and deadline.
     */
    public static function assertCurrent(MembershipCatalogSnapshot $snapshot, PDO $pdo, string $driver, bool $locking = true): void
    {
        MembershipException::require($snapshot->pdo === $pdo && $snapshot->driver === $driver
            && self::schema($pdo, $driver) === $snapshot->schema, 'changed_catalog_source');
        foreach ($snapshot->files as $file) {
            self::nativeBytes($file);
        }
        MembershipException::require(self::definitions($pdo, $driver, $snapshot->schema) === $snapshot->definitions, 'changed_catalog_source');
        $suffix = $locking && $driver === 'mysql' ? ' FOR UPDATE' : '';
        foreach ($snapshot->plans as $plan) {
            MembershipException::require(self::query($pdo, $plan['sql'].$suffix, $plan['parameters']) === $plan['rows'], 'changed_catalog');
        }
    }

    /** Resolve each path during callback-capable work, then bind native identity and exact bytes. */
    private static function filePlans(array $selection): array
    {
        $files = [];
        foreach ($selection['bytes'] as $bytes) {
            $asset = PreparationSelection::one($selection['graph']['media'], $bytes['asset_id']);
            $path = app(PrivateMediaFiles::class)->resolve($asset['storage_path']);
            MembershipException::require(str_starts_with($path, '/') && ! str_contains($path, '://'), 'private_bytes');
            clearstatcache(true, $path);
            $stat = @lstat($path);
            MembershipException::require(is_array($stat), 'private_bytes');
            $directories = [];
            for ($parent = dirname($path); $parent !== '/'; $parent = dirname($parent)) {
                clearstatcache(true, $parent);
                $directory = @lstat($parent);
                MembershipException::require(is_array($directory) && ($directory['mode'] & 0170000) === 0040000, 'private_bytes');
                $directories[$parent] = array_intersect_key($directory, array_flip(['dev', 'ino', 'mode']));
            }
            $file = ['path' => $path, 'sha256' => $bytes['sha256'], 'size_bytes' => $bytes['size_bytes'],
                'stat' => self::fileStat($stat), 'directories' => $directories];
            self::nativeBytes($file);
            $files[] = $file;
        }

        return $files;
    }

    /** Resolved plain local files only; never calls the application storage adapter or resolver. */
    private static function nativeBytes(array $file): void
    {
        $path = $file['path'];
        // Open first. A replaced file wrapper must not run a stat callback, restore the native
        // wrapper, and then pass a later fopen check. A substituted stream never passes this gate.
        $input = @fopen($path, 'rb');
        try {
            MembershipException::require(is_resource($input), 'private_bytes');
            $metadata = stream_get_meta_data($input);
            $opened = fstat($input);
            MembershipException::require($metadata['wrapper_type'] === 'plainfile' && $metadata['stream_type'] === 'STDIO'
                && is_array($opened) && self::fileStat($opened) === $file['stat'], 'private_bytes');
            foreach ($file['directories'] as $directory => $expected) {
                clearstatcache(true, $directory);
                $stat = @lstat($directory);
                MembershipException::require(is_array($stat) && array_intersect_key($stat, array_flip(['dev', 'ino', 'mode'])) === $expected, 'private_bytes');
            }
            clearstatcache(true, $path);
            $before = @lstat($path);
            MembershipException::require(is_array($before) && self::fileStat($before) === $file['stat']
                && ($before['mode'] & 0170000) === 0100000 && $before['size'] === $file['size_bytes'], 'private_bytes');
            $hash = hash_init('sha256');
            MembershipException::require(hash_update_stream($hash, $input) === $file['size_bytes']
                && hash_equals($file['sha256'], hash_final($hash)), 'private_bytes');
            $after = fstat($input);
            clearstatcache(true, $path);
            $named = @lstat($path);
            MembershipException::require(is_array($after) && is_array($named)
                && self::fileStat($after) === $file['stat'] && self::fileStat($named) === $file['stat'], 'private_bytes');
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
        }
    }

    private static function fileStat(array $stat): array
    {
        return array_intersect_key($stat, array_flip(['dev', 'ino', 'mode', 'size', 'mtime', 'ctime']));
    }

    /** Internal v1 contract: C4 must match these member-license terms, never membership-plan text. */
    private static function binding(array $selection, CarbonImmutable $at): MembershipLicenseBinding
    {
        MembershipException::require(count($selection['selection']['lines']) === 1, 'invalid_selection');
        $line = $selection['selection']['lines'][0];
        $license = $line['offer_snapshot']['license'];
        $deadline = (int) $at->format('Uu') + 60_000_000;
        foreach ($selection['graph']['offers'] as $offer) {
            if ((int) $offer['is_active'] !== 1) {
                continue;
            }
            $revision = PreparationSelection::one($selection['graph']['revisions'], $offer['current_revision_id']);
            $effective = PreparationSelection::one($selection['graph']['licenses'], $revision['license_version_id'])['effective_until'];
            if ($effective !== null) {
                $deadline = min($deadline, (int) (new DateTimeImmutable($effective, new DateTimeZone('UTC')))->format('Uu'));
            }
        }
        $terms = ['purpose' => 'membership-license-original-terms-v1', 'authored_source' => $license['authored_source'],
            'structured_terms' => $license['structured_terms'], 'renderer_version' => $license['renderer_version'],
            'render_fixture_hash' => $license['render_fixture_hash']];

        return new MembershipLicenseBinding($selection['selection_hash'], CanonicalJson::hash($license),
            CanonicalJson::hash($line['offer_snapshot']['assets']), CanonicalJson::hash($terms), $deadline,
            $line['track_id'], $line['offer_id'], $line['offer_revision_id'], $line['license_version_id']);
    }

    /** Capture exactly the complete predicates read by CurrentSelection, and compare their graph. */
    private static function plans(PDO $pdo, string $schema, array $selection): array
    {
        $plans = [];
        $graph = $selection['graph'];
        $ids = array_column($selection['items'], 'trackId');
        foreach (['tracks' => ['tracks', 'id'], 'offers' => ['offers', 'track_id'], 'rights' => ['rights_declarations', 'track_id'],
            'media' => ['media_assets', 'track_id'], 'bindings' => ['stems_recordings', 'track_id']] as $key => [$table, $column]) {
            self::set($pdo, $schema, $plans, $table, $column, $ids, $graph[$key]);
        }
        self::set($pdo, $schema, $plans, 'offer_revisions', 'offer_id', array_column($graph['offers'], 'id'), $graph['revisions']);
        self::set($pdo, $schema, $plans, 'license_versions', 'id', array_unique(array_column($graph['revisions'], 'license_version_id')), $graph['licenses']);
        self::set($pdo, $schema, $plans, 'license_templates', 'id', array_unique(array_column($graph['licenses'], 'license_template_id')), $graph['templates']);
        self::set($pdo, $schema, $plans, 'license_review_evidence', 'license_version_id', array_column($graph['licenses'], 'id'), $graph['reviews']);
        self::set($pdo, $schema, $plans, 'media_processing_runs', 'source_asset_id', array_column($graph['media'], 'id'), $graph['runs']);
        self::set($pdo, $schema, $plans, 'media_assets', 'processing_run_id', array_column($graph['runs'], 'id'), $graph['outputs']);
        self::set($pdo, $schema, $plans, 'rights_scope_offers', 'offer_revision_id', array_column($graph['revisions'], 'id'), $selection['links']);
        foreach ($graph['audits'] as [$type, $id, $expected]) {
            MembershipException::require(in_array($type, [Track::class, Offer::class], true), 'changed_catalog');
            self::plan($pdo, $schema, $plans, 'audit_events', 'subject_type = ? AND subject_id = ?', [$type, $id], $expected);
        }

        return $plans;
    }

    private static function set(PDO $pdo, string $schema, array &$plans, string $table, string $column, array $ids, array $expected): void
    {
        $ids = array_values($ids);
        if ($ids === []) {
            MembershipException::require($expected === [], 'changed_catalog');

            return;
        }
        self::plan($pdo, $schema, $plans, $table, $column.' IN ('.implode(',', array_fill(0, count($ids), '?')).')', $ids, $expected);
    }

    private static function plan(PDO $pdo, string $schema, array &$plans, string $table, string $where, array $parameters, array $expected): void
    {
        $sql = 'SELECT * FROM '.self::qualified($schema, $table).' WHERE '.$where.' ORDER BY id LIMIT 257';
        $rows = self::query($pdo, $sql.($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''), $parameters);
        $interpreted = $rows;
        if ($table === 'audit_events') {
            foreach ($interpreted as &$row) {
                $row['context'] = json_decode($row['context'], true, 8, JSON_THROW_ON_ERROR);
            }
        }
        MembershipException::require($interpreted === $expected, 'changed_catalog');
        $plans[] = compact('sql', 'parameters', 'rows');
    }

    private static function schema(PDO $pdo, string $driver): string
    {
        MembershipException::require(in_array($driver, ['mysql', 'sqlite'], true)
            && in_array($pdo::class, [PDO::class, Mysql::class, Sqlite::class], true)
            && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === $driver && $pdo->inTransaction()
            && $pdo->getAttribute(PDO::ATTR_STATEMENT_CLASS) === [PDOStatement::class]
            && $pdo->getAttribute(PDO::ATTR_STRINGIFY_FETCHES) === false, 'changed_catalog_source');
        $schema = $driver === 'mysql' ? $pdo->query('SELECT DATABASE()')->fetchColumn() : 'main';
        MembershipException::require(is_string($schema) && preg_match('/\A[a-zA-Z0-9_]+\z/D', $schema) === 1, 'changed_catalog_source');

        return $schema;
    }

    private static function definitions(PDO $pdo, string $driver, string $schema): array
    {
        $definitions = [];
        if ($driver === 'sqlite') {
            $temporary = $pdo->query('SELECT name, tbl_name FROM temp.sqlite_schema')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($temporary as $row) {
                MembershipException::require(! in_array(strtolower($row['name']), self::TABLES, true)
                    && ! in_array(strtolower($row['tbl_name']), self::TABLES, true), 'catalog_shadow');
            }
        }
        foreach (self::TABLES as $table) {
            if ($driver === 'mysql') {
                $definition = $pdo->query('SHOW CREATE TABLE '.self::qualified($schema, $table))->fetchAll(PDO::FETCH_NUM);
                MembershipException::require(count($definition) === 1 && $definition[0][0] === $table
                    && is_string($definition[0][1] ?? null), 'catalog_shadow_or_engine');
                $definition[0][1] = MembershipCatalogDefinition::mysql($definition[0][1], $table);
            } else {
                $definition = self::query($pdo, 'SELECT name, type, sql FROM main.sqlite_schema WHERE name = ? COLLATE NOCASE', [$table]);
                MembershipException::require(count($definition) === 1 && $definition[0]['name'] === $table && $definition[0]['type'] === 'table'
                    && is_string($definition[0]['sql']) && str_starts_with(strtoupper($definition[0]['sql']), 'CREATE TABLE '), 'catalog_shadow');
            }
            $definitions[$table] = $definition;
        }

        return $definitions;
    }

    private static function qualified(string $schema, string $table): string
    {
        return '`'.$schema.'`.`'.$table.'`';
    }

    private static function query(PDO $pdo, string $sql, array $parameters): array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
