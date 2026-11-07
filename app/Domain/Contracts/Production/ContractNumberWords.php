<?php

namespace App\Domain\Contracts\Production;

/**
 * A fixed, deterministic English number speller for the `_WORD` placeholders. No locale, `NumberFormatter` or
 * intl data is consulted, so the output depends only on the integer. US short scale, lowercase, hyphenated tens
 * ("twenty-one"), no "and" inside a number, scales up to billions.
 */
final class ContractNumberWords
{
    public const MAX = 999_999_999_999;

    private const ONES = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve',
        'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];

    private const TENS = [2 => 'twenty', 3 => 'thirty', 4 => 'forty', 5 => 'fifty', 6 => 'sixty', 7 => 'seventy', 8 => 'eighty', 9 => 'ninety'];

    private const SCALES = [1_000_000_000 => 'billion', 1_000_000 => 'million', 1_000 => 'thousand'];

    public static function spell(int $number): string
    {
        ContractBindingException::require($number >= 0 && $number <= self::MAX, 'number_out_of_range');
        if ($number === 0) {
            return self::ONES[0];
        }
        $words = [];
        foreach (self::SCALES as $scale => $name) {
            if ($number >= $scale) {
                $words[] = self::belowThousand(intdiv($number, $scale)).' '.$name;
                $number %= $scale;
            }
        }
        if ($number > 0) {
            $words[] = self::belowThousand($number);
        }

        return implode(' ', $words);
    }

    /** "one thousand five hundred dollars", "one dollar and five cents", "zero dollars and fifty cents". */
    public static function dollars(int $minor): string
    {
        ContractBindingException::require($minor >= 0 && intdiv($minor, 100) <= self::MAX, 'number_out_of_range');
        $dollars = intdiv($minor, 100);
        $cents = $minor % 100;
        $words = self::spell($dollars).($dollars === 1 ? ' dollar' : ' dollars');

        return $cents === 0 ? $words : $words.' and '.self::spell($cents).($cents === 1 ? ' cent' : ' cents');
    }

    /** "$1,500.00": integer arithmetic only, so no float rounding can reach a contract. */
    public static function usd(int $minor): string
    {
        ContractBindingException::require($minor >= 0, 'number_out_of_range');

        return '$'.number_format(intdiv($minor, 100), 0, '.', ',').'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function belowThousand(int $number): string
    {
        $words = [];
        if ($number >= 100) {
            $words[] = self::ONES[intdiv($number, 100)].' hundred';
            $number %= 100;
        }
        if ($number >= 20) {
            $words[] = self::TENS[intdiv($number, 10)].($number % 10 === 0 ? '' : '-'.self::ONES[$number % 10]);
        } elseif ($number > 0) {
            $words[] = self::ONES[$number];
        }

        return implode(' ', $words);
    }
}
