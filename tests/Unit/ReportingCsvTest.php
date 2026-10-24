<?php

namespace Tests\Unit;

use App\Domain\Reporting\ReportingCsv;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Exported text cannot be executed as a spreadsheet formula; numbers stay numbers. */
class ReportingCsvTest extends TestCase
{
    public static function cells(): iterable
    {
        foreach (['=HYPERLINK("http://example.invalid")', '+1+1', '-2+3', '@SUM(A1)', "\tcmd", "\rcmd"] as $text) {
            yield $text => [$text, "'".$text];
        }
        yield 'plain' => ['Standard lease', 'Standard lease'];
        yield 'inner equals' => ['a=b', 'a=b'];
        yield 'empty' => ['', ''];
        yield 'null' => [null, ''];
        yield 'negative integer net amount' => [-250, '-250'];
        yield 'integer' => [1999, '1999'];
    }

    #[DataProvider('cells')]
    public function test_cells_are_neutralised_only_when_text_starts_like_a_formula(int|string|null $value, string $expected): void
    {
        $this->assertSame($expected, ReportingCsv::cell($value));
    }

    public function test_rendering_quotes_delimiters_and_keeps_one_record_per_row(): void
    {
        $csv = ReportingCsv::render(['a', 'b'], [['x,y', 'say "hi"'], ['=1', 5]]);
        $this->assertSame("a,b\n\"x,y\",\"say \"\"hi\"\"\"\n'=1,5\n", $csv);
    }
}
