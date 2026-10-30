<?php

namespace App\Domain\Reporting;

/** Bounded in-memory CSV for exports. Cells that a spreadsheet could execute as a formula are neutralised. */
final class ReportingCsv
{
    /** @param  list<string>  $header
     *  @param  list<list<int|string|null>>  $rows */
    public static function render(array $header, array $rows): string
    {
        $out = fopen('php://temp', 'w+');
        fputcsv($out, $header, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($out, array_map(self::cell(...), $row), ',', '"', '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    public static function cell(int|string|null $value): string
    {
        if ($value === null) {
            return '';
        }
        $text = (string) $value;
        // Numbers (including negative net amounts) are data; only text that starts like a formula is escaped.
        if (is_int($value) || $text === '') {
            return $text;
        }
        // Whitespace before the trigger ("\n=1+1", " =1+1") is skipped by some spreadsheets, so look past it.
        $first = ltrim($text, " \n")[0] ?? '';
        if (! in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! in_array($first, ['=', '+', '-', '@'], true)) {
            return $text;
        }

        return "'".$text;
    }
}
