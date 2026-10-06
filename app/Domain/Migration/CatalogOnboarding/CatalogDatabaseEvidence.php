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
