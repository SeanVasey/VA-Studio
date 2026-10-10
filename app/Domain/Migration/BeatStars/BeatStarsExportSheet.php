<?php

declare(strict_types=1);

namespace App\Domain\Migration\BeatStars;

use InvalidArgumentException;

/**
 * Minimal RFC 4180 CSV admission for an export sheet: UTF-8 only, one header line, bounded rows.
 *
 * Structural problems that make the sheet uninterpretable (not UTF-8, no header, duplicate
 * headers, too many rows) are refusals with a `export_*` reason code. Everything that can be
 * attributed to one record (column-count mismatch, blank record) is left to the normalizer
 * so it becomes a finding in the report instead of stopping the run.
 */
final class BeatStarsExportSheet
{
    public const MAX_ROWS = 1000;

    public const MAX_COLUMNS = 200;

    /**
     * @return array{headers: list<string>, records: list<?list<string>>} records are in sheet order; a blank line is null
     */
    public function read(string $bytes): array
    {
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $bytes = substr($bytes, 3);
        }
        $this->require(mb_check_encoding($bytes, 'UTF-8') && ! str_contains($bytes, "\0"), 'export_encoding');
        $handle = fopen('php://temp', 'r+b');
        $this->require($handle !== false, 'export_unreadable');
        try {
            fwrite($handle, $bytes);
            rewind($handle);
            $headers = fgetcsv($handle, null, ',', '"', '');
            $this->require(is_array($headers) && $headers !== [null] && count($headers) <= self::MAX_COLUMNS, 'export_header');
            $headers = array_map(static fn (?string $header): string => trim((string) $header), $headers);
            foreach ($headers as $header) {
                $this->require(CellText::line($header, 255), 'export_header');
            }
            $this->require(count(array_unique($headers, SORT_STRING)) === count($headers), 'export_header');
            $records = [];
            while (($record = fgetcsv($handle, null, ',', '"', '')) !== false) {
                $this->require(count($records) < self::MAX_ROWS, 'export_too_many_rows');
                $records[] = $record === [null] ? null : array_map(static fn (?string $cell): string => (string) $cell, $record);
            }
        } finally {
            fclose($handle);
        }
        $this->require(array_any($records, static fn (?array $record): bool => $record !== null), 'export_empty');

        return ['headers' => array_values($headers), 'records' => $records];
    }

    private function require(bool $condition, string $code): void
    {
        if (! $condition) {
            throw new InvalidArgumentException($code);
        }
    }
}
