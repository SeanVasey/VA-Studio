<?php

declare(strict_types=1);

namespace App\Domain\Migration\CatalogOnboarding;

use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

/** Current private SQLite reads bypass QueryExecuted callbacks, including storage-class identity. */
final class CatalogDatabaseEvidence
{
    private const TABLES = ['users', 'tracks', 'audit_events', 'catalog_import_batches', 'catalog_import_mappings',
        'media_assets', 'rights_declarations', 'offers', 'offer_revisions'];

    /** The pinned owned schema is actual live SQL, not a migrations-history claim. */
    public function schema(): string
    {
        $pdo = DB::connection()->getPdo();
        if (DB::getDriverName() !== 'sqlite' || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite'
            || $pdo->query('PRAGMA foreign_keys')->fetchColumn() !== 1
            || $pdo->query('PRAGMA writable_schema')->fetchColumn() !== 0
            || $pdo->query('PRAGMA ignore_check_constraints')->fetchColumn() !== 0) {
            throw new RuntimeException('catalog_target_schema_invalid');
        }
        $expected = $this->expectedSchema();
        $statement = $pdo->query("SELECT name, type, sql FROM sqlite_master WHERE tbl_name IN ('catalog_import_batches', 'catalog_import_mappings') ORDER BY name");
        $current = $statement->fetchAll(PDO::FETCH_ASSOC);
        if ($current !== $expected) {
            throw new RuntimeException('catalog_target_schema_invalid');
        }

        return hash('sha256', serialize($current));
    }

    private function expectedSchema(): array
    {
        $commonId = '"id" integer primary key autoincrement not null';
        $created = '"created_at" datetime not null default CURRENT_TIMESTAMP';
        $definitions = [
            'catalog_import_batches' => [$commonId, '"review_sha256" varchar not null', '"source_sha256" varchar not null',
                '"source_identity_sha256" varchar not null', '"target_commit" varchar not null', '"target_schema_sha256" varchar not null',
                '"transform_version" varchar not null', '"actor_id" integer not null', '"review_ciphertext" text not null', $created,
                'foreign key("actor_id") references "users"("id") on delete restrict'],
            'catalog_import_mappings' => [$commonId, '"batch_id" integer not null', '"record_key" varchar not null',
                '"source_record_sha256" varchar not null', '"track_id" integer not null', '"track_sha256" varchar not null',
                '"actor_id" integer not null', '"evidence_ciphertext" text not null', $created,
                'foreign key("batch_id") references "catalog_import_batches"("id") on delete restrict',
                'foreign key("track_id") references "tracks"("id") on delete restrict',
                'foreign key("actor_id") references "users"("id") on delete restrict'],
        ];
        $rows = [];
        foreach ($definitions as $table => $definition) {
            $rows[] = ['name' => $table, 'type' => 'table', 'sql' => 'CREATE TABLE "'.$table.'" ('.implode(', ', $definition).')'];
            $unique = $table === 'catalog_import_batches' ? ['review_sha256'] : ['record_key', 'track_id'];
            foreach ($unique as $column) {
                $name = $table.'_'.$column.'_unique';
                $rows[] = ['name' => $name, 'type' => 'index',
                    'sql' => 'CREATE UNIQUE INDEX "'.$name.'" on "'.$table.'" ("'.$column.'")'];
            }
            foreach (['update', 'delete'] as $operation) {
                $name = $table.'_immutable_'.$operation;
                $rows[] = ['name' => $name, 'type' => 'trigger',
                    'sql' => 'CREATE TRIGGER '.$name.' BEFORE '.strtoupper($operation).' ON '.$table." BEGIN SELECT RAISE(ABORT, 'Catalog import evidence is immutable'); END"];
            }
            $name = $table.'_immutable_replace';
            $conditions = array_map(static fn (string $column): string => '"'.$column.'" = NEW."'.$column.'"', ['id', ...$unique]);
            $rows[] = ['name' => $name, 'type' => 'trigger',
                'sql' => 'CREATE TRIGGER '.$name.' BEFORE INSERT ON '.$table.' WHEN EXISTS (SELECT 1 FROM '.$table.' WHERE '.implode(' OR ', $conditions).") BEGIN SELECT RAISE(ABORT, 'Catalog import evidence is immutable'); END"];
        }
        usort($rows, static fn (array $left, array $right): int => strcmp($left['name'], $right['name']));

        return $rows;
    }

    public function rows(string $table): array
    {
        if (DB::getDriverName() !== 'sqlite' || ! in_array($table, self::TABLES, true)) {
            throw new RuntimeException('catalog_target_invalid');
        }
        $pdo = DB::connection()->getPdo();
        $columns = $pdo->query('PRAGMA table_info("'.$table.'")')->fetchAll(PDO::FETCH_ASSOC);
        if ($columns === [] || count($columns) > 100) {
            throw new RuntimeException('catalog_target_invalid');
        }
        $select = [];
        foreach ($columns as $index => $column) {
            $name = '"'.str_replace('"', '""', $column['name']).'"';
            $select[] = $name;
            $select[] = 'typeof('.$name.') AS "__catalog_type_'.$index.'"';
        }
        $statement = $pdo->query('SELECT '.implode(', ', $select).' FROM "'.$table.'" ORDER BY "id"');
        $rows = [];
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            if (count($rows) >= 100000) {
                throw new RuntimeException('catalog_target_invalid');
            }
            $types = [];
            foreach ($columns as $index => $column) {
                $types[$column['name']] = $row['__catalog_type_'.$index];
                unset($row['__catalog_type_'.$index]);
            }
            if (! is_int($row['id']) || $row['id'] < 1) {
                throw new RuntimeException('catalog_target_invalid');
            }
            $rows[$row['id']] = ['attributes' => $row, 'types' => $types,
                'sha256' => hash('sha256', serialize([$row, $types]))];
        }

        return $rows;
    }

    public function target(array $excludeTracks = [], array $excludeMappings = [], array $excludeAudits = []): array
    {
        $snapshot = [];
        foreach (['tracks' => $excludeTracks, 'catalog_import_mappings' => $excludeMappings, 'audit_events' => $excludeAudits] as $table => $excluded) {
            $rows = $this->rows($table);
            foreach ($excluded as $id) {
                unset($rows[$id]);
            }
            $snapshot[$table] = array_map(static fn (array $row): array => ['id' => $row['attributes']['id'], 'sha256' => $row['sha256']], array_values($rows));
        }

        return $snapshot;
    }

    public function childless(int $trackId): bool
    {
        foreach (['media_assets', 'rights_declarations', 'offers', 'offer_revisions'] as $table) {
            foreach ($this->rows($table) as $row) {
                if ($row['attributes']['track_id'] === $trackId) {
                    return false;
                }
            }
        }

        return true;
    }
}
