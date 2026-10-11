<?php

declare(strict_types=1);

namespace App\Domain\Migration\BeatStars;

/** Text bounds shared by the mapping and the normalizer; they mirror NormalizedSourceSnapshot without importing its private rules. */
final class CellText
{
    /** A trimmed, non-empty single line of printable UTF-8 of at most $maximum characters. */
    public static function line(mixed $value, int $maximum): bool
    {
        return is_string($value) && $value !== '' && trim($value) === $value && mb_check_encoding($value, 'UTF-8')
            && mb_strlen($value, 'UTF-8') <= $maximum && preg_match('/[\x00-\x1f\x7f]/', $value) === 0;
    }

    /** Like line(), but tabs, line feeds and carriage returns are allowed inside the value. */
    public static function multiline(mixed $value, int $maximum): bool
    {
        return is_string($value) && $value !== '' && trim($value) === $value && mb_check_encoding($value, 'UTF-8')
            && mb_strlen($value, 'UTF-8') <= $maximum && preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value) === 0;
    }

    /** Operator identities (snapshot, source system, references) follow the snapshot's 190-character identity rule. */
    public static function identity(mixed $value): bool
    {
        return self::line($value, 190);
    }

    /** Finding details never carry control characters or unbounded source text. */
    public static function detail(string $value): string
    {
        $clean = preg_replace('/[\x00-\x1f\x7f]/', '?', $value) ?? '';
        if (! mb_check_encoding($clean, 'UTF-8')) {
            return '(invalid UTF-8)';
        }

        return mb_strlen($clean, 'UTF-8') > 120 ? mb_substr($clean, 0, 117, 'UTF-8').'...' : $clean;
    }
}
