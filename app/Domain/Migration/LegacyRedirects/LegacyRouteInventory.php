<?php

namespace App\Domain\Migration\LegacyRedirects;

/** Read-only view of docs/migration/legacy_route_inventory.csv for reconciliation. */
final class LegacyRouteInventory
{
    public const COLUMNS = ['route_id', 'source_host', 'source_path', 'proposed_target_path', 'source_ids', 'evidence_status',
        'verification_status', 'control_status', 'redirect_policy'];

    /** @param  list<array<string, string>>  $rows */
    private function __construct(public readonly string $sha256, public readonly array $rows) {}

    public static function read(string $path): self
    {
        if (is_link($path) || ! is_file($path) || ! is_readable($path) || filesize($path) > 1048576) {
            throw new LegacyRedirectMapInvalid(['The route inventory is missing, unreadable or larger than 1 MiB.']);
        }
        $bytes = (string) file_get_contents($path);
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $bytes);
        rewind($handle);
        $header = fgetcsv($handle, escape: '');
        if ($header !== self::COLUMNS) {
            fclose($handle);
            throw new LegacyRedirectMapInvalid(['The route inventory header is not the expected '.count(self::COLUMNS).' columns.']);
        }
        $rows = [];
        while (($line = fgetcsv($handle, escape: '')) !== false) {
            if ($line === [null]) {
                continue;
            }
            if (count($line) !== count(self::COLUMNS)) {
                fclose($handle);
                throw new LegacyRedirectMapInvalid(['Route inventory row '.(count($rows) + 2).' does not have '.count(self::COLUMNS).' columns.']);
            }
            $rows[] = array_combine(self::COLUMNS, $line);
        }
        fclose($handle);

        return new self(hash('sha256', $bytes), $rows);
    }
}
