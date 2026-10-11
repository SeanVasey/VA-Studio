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
        $this->quotes($bytes);
        // php://memory never spills to a temporary file, and every byte must land before parsing: a short
        // write would let fgetcsv read a prefix that may end on a record boundary and look complete.
        $handle = fopen('php://memory', 'r+b');
        $this->require($handle !== false, 'export_unreadable');
        try {
            $offset = 0;
            while ($offset < strlen($bytes)) {
                $count = fwrite($handle, substr($bytes, $offset));
                $this->require($count !== false && $count > 0, 'export_unreadable');
                $offset += $count;
            }
            $stat = fstat($handle);
            $this->require($stat !== false && $stat['size'] === strlen($bytes) && rewind($handle), 'export_unreadable');
            $headers = fgetcsv($handle, null, ',', '"', '');
            $this->require(is_array($headers) && $headers !== [null] && count($headers) <= self::MAX_COLUMNS, 'export_header');
            $headers = array_map(static fn (?string $header): string => (string) $header, $headers);
            foreach ($headers as $header) {
                // A padded header is refused, not trimmed: the mapping must declare the exact exported header.
                $this->require($header === trim($header) && CellText::line($header, 255), 'export_header');
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

    /**
     * fgetcsv accepts a quoted field that never closes (a truncated export), text after a closing quote, and a
     * quote inside an unquoted field, and returns cells or records that another reader could split differently.
     * Refuse all three before parsing (RFC 4180): a quote may only open a field, a quoted field must close, only
     * a delimiter, a line ending or the end of the file may follow its closing quote, and outside quotes a CR
     * may only begin a CRLF line ending.
     */
    private function quotes(string $bytes): void
    {
        $length = strlen($bytes);
        $offset = 0;
        while ($offset < $length) {
            if ($bytes[$offset] !== '"') {
                // An unquoted field runs to the next delimiter or line ending and holds no quote and no bare CR.
                // This also covers whitespace before a quote, which fgetcsv skips and then parses as an
                // enclosure, and a CR that fgetcsv strips from a cell while another reader ends a record there.
                $span = strcspn($bytes, "\",\n\r", $offset);
                $stop = $bytes[$offset + $span] ?? '';
                $this->require($stop !== '"' && ($stop !== "\r" || ($bytes[$offset + $span + 1] ?? '') === "\n"), 'export_csv_quotes');
                $offset += $span + ($stop === "\r" ? 2 : 1);

                continue;
            }
            $offset++;
            while (true) {
                $quote = strpos($bytes, '"', $offset);
                $this->require($quote !== false, 'export_csv_quotes');
                if (($bytes[$quote + 1] ?? '') !== '"') {
                    $offset = $quote + 1;
                    break;
                }
                $offset = $quote + 2;
            }
            $after = $bytes[$offset] ?? '';
            $this->require(in_array($after, ['', ',', "\n"], true) || ($after === "\r" && ($bytes[$offset + 1] ?? '') === "\n"),
                'export_csv_quotes');
            $offset += $after === "\r" ? 2 : 1;
        }
    }

    private function require(bool $condition, string $code): void
    {
        if (! $condition) {
            throw new InvalidArgumentException($code);
        }
    }
}
