<?php

namespace Tests\Support;

use App\Domain\Migration\LegacyRedirects\LegacyRouteInventory;

/**
 * Synthetic legacy redirect maps and inventories for tests only. Every path is under /legacy-fixture/ so it can never
 * be mistaken for a real legacy URL; the shipped map is resources/legacy-redirects/map.json.
 */
final class LegacyRedirectFixtures
{
    public const HOST = 'legacy.example.test';

    /** @param  list<array<string, mixed>>  $entries  @return array{map: string, inventory: string} */
    public static function write(string $directory, array $entries, array $externalHosts = [], ?array $inventoryEntries = null): array
    {
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $csv = implode(',', LegacyRouteInventory::COLUMNS)."\n";
        foreach ($inventoryEntries ?? $entries as $entry) {
            $csv .= implode(',', [$entry['route_id'], $entry['source_host'], $entry['source_path'], '/', 'SRC-TEST', 'Recommendation',
                'needs_live_browser_and_catalog_export', 'owner_control_not_yet_verified', 'synthetic test row'])."\n";
        }
        $inventory = $directory.'/inventory.csv';
        file_put_contents($inventory, $csv);
        $map = $directory.'/map.json';
        file_put_contents($map, json_encode(self::map($entries, $externalHosts, hash('sha256', $csv), count($inventoryEntries ?? $entries)), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return ['map' => $map, 'inventory' => $inventory];
    }

    public static function map(array $entries, array $externalHosts = [], string $sha256 = '', int $rows = 1): array
    {
        return [
            'schema' => 'vasey.legacy-redirects', 'version' => 1, 'reviewed_on' => '2026-10-24',
            'inventory' => ['path' => 'inventory.csv', 'sha256' => $sha256 === '' ? str_repeat('0', 64) : $sha256, 'rows' => $rows],
            'query_string' => 'drop', 'external_hosts' => $externalHosts, 'entries' => $entries,
        ];
    }

    public static function redirect(string $id, string $path, array $target): array
    {
        return ['route_id' => $id, 'source_host' => self::HOST, 'source_path' => $path, 'disposition' => 'redirect', 'status' => 301, 'target' => $target];
    }

    public static function gone(string $id, string $path): array
    {
        return ['route_id' => $id, 'source_host' => self::HOST, 'source_path' => $path, 'disposition' => 'gone', 'status' => 410];
    }

    public static function retained(string $id, string $path): array
    {
        return ['route_id' => $id, 'source_host' => self::HOST, 'source_path' => $path, 'disposition' => 'retained'];
    }

    public static function unresolved(string $id, string $path, string $reason = 'Synthetic unresolved row.'): array
    {
        return ['route_id' => $id, 'source_host' => self::HOST, 'source_path' => $path, 'disposition' => 'unresolved', 'reason' => $reason];
    }

    public static function remove(string $directory): void
    {
        foreach (glob($directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
}
