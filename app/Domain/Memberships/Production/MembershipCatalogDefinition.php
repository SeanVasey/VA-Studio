<?php

namespace App\Domain\Memberships\Production;

/** Pure opened-table comparison: only MySQL's table allocation counter is volatile. */
final class MembershipCatalogDefinition
{
    public static function mysql(string $shown, string $table): string
    {
        MembershipException::require(preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/D', $table) === 1
            && ! str_contains($shown, "\0"), 'catalog_shadow_or_engine');
        // SHOW may quote strings differently under NO_BACKSLASH_ESCAPES. Never guess a
        // structural boundary when two complete lexical readings disagree about it.
        $escaped = self::structure($shown, $table, true);
        $literal = self::structure($shown, $table, false);
        MembershipException::require(($escaped !== null || $literal !== null)
            && ($escaped === null || $literal === null || $escaped['body_end'] === $literal['body_end']), 'catalog_shadow_or_engine');
        $projected = null;
        foreach ([$escaped, $literal] as $structure) {
            if ($structure === null) {
                continue;
            }
            // A complete reading which fails the engine/option gate is still a
            // competing reading. It cannot be discarded to admit another mode.
            $candidate = self::projection($shown, $structure);
            MembershipException::require($candidate !== null && ($projected === null || $projected === $candidate), 'catalog_shadow_or_engine');
            $projected = $candidate;
        }

        return $projected;
    }

    private static function structure(string $shown, string $table, bool $backslashEscapes): ?array
    {
        $header = 'CREATE TABLE `'.$table.'` (';
        if (! str_starts_with($shown, $header)) {
            return null;
        }
        $offset = strlen($header) - 1;
        $length = strlen($shown);
        $depth = 0;
        $bodyEnd = null;
        $suffix = [];
        while ($offset < $length) {
            $token = self::token($shown, $offset, $backslashEscapes);
            if ($token === null || $token['value'] === ';') {
                return null;
            }
            if ($bodyEnd !== null) {
                $suffix[] = $token + ['depth' => $depth];
            }
            if ($token['kind'] !== 'symbol') {
                continue;
            }
            if ($token['value'] === '(') {
                $depth++;
            } elseif ($token['value'] === ')') {
                if (--$depth < 0) {
                    return null;
                }
                if ($depth === 0 && $bodyEnd === null) {
                    $bodyEnd = $token['start'];
                }
            }
        }
        if ($bodyEnd === null || $depth !== 0) {
            return null;
        }

        return ['body_end' => $bodyEnd, 'suffix' => $suffix];
    }

    private static function projection(string $shown, array $structure): ?string
    {
        $bodyEnd = $structure['body_end'];
        $length = strlen($shown);
        $engine = ' ENGINE=InnoDB';
        $afterEngine = $bodyEnd + 1 + strlen($engine);
        if (substr($shown, $bodyEnd + 1, strlen($engine)) !== $engine
            || ($afterEngine < $length && ! self::space($shown[$afterEngine]))) {
            return null;
        }
        $counterStart = $counterEnd = null;
        $counter = ' AUTO_INCREMENT=';
        if (substr($shown, $afterEngine, strlen($counter)) === $counter) {
            $digits = $afterEngine + strlen($counter);
            if (preg_match('/[0-9]+/A', $shown, $match, 0, $digits) !== 1) {
                return null;
            }
            $counterStart = $afterEngine;
            $counterEnd = $digits + strlen($match[0]);
            if ($counterEnd < $length && ! self::space($shown[$counterEnd])) {
                return null;
            }
        }
        // Retain every suffix byte, but require closed lexical structure and no second
        // or differently placed unquoted allocation/engine option. Partition bodies
        // and executable comments remain exact, not another normalization surface.
        foreach ($structure['suffix'] as $token) {
            if ($token['kind'] === 'word' && $token['depth'] === 0) {
                if (strcasecmp($token['value'], 'ENGINE') === 0 && $token['start'] !== $bodyEnd + 2) {
                    return null;
                }
                if (strcasecmp($token['value'], 'AUTO_INCREMENT') === 0
                    && ($counterStart === null || $token['start'] !== $counterStart + 1)) {
                    return null;
                }
            }
        }

        return $counterStart === null ? $shown : substr($shown, 0, $counterStart).substr($shown, $counterEnd);
    }

    /** Returns byte offsets without interpreting or rewriting a quoted/comment token. */
    private static function token(string $sql, int &$offset, bool $backslashEscapes): ?array
    {
        $start = $offset;
        $length = strlen($sql);
        $character = $sql[$offset++];
        $kind = 'symbol';
        if (in_array($character, ["'", '"', '`'], true)) {
            $kind = 'quoted';
            $closed = false;
            while ($offset < $length) {
                $next = $sql[$offset++];
                if ($next === '\\' && $character !== '`' && $backslashEscapes) {
                    if ($offset === $length) {
                        return null;
                    }
                    $offset++;
                } elseif ($next === $character) {
                    if ($offset < $length && $sql[$offset] === $character) {
                        $offset++;
                    } else {
                        $closed = true;
                        break;
                    }
                }
            }
            if (! $closed) {
                return null;
            }
        } elseif ($character === '#' || ($character === '-' && substr($sql, $start, 2) === '--'
            && $start + 2 < $length && self::space($sql[$start + 2]))) {
            $kind = 'comment';
            $newline = strpos($sql, "\n", $offset);
            $offset = $newline === false ? $length : $newline + 1;
        } elseif ($character === '/' && substr($sql, $start, 2) === '/*') {
            $kind = 'comment';
            $close = strpos($sql, '*/', $offset + 1);
            if ($close === false) {
                return null;
            }
            $offset = $close + 2;
        } elseif (self::space($character)) {
            $kind = 'space';
            while ($offset < $length && self::space($sql[$offset])) {
                $offset++;
            }
        } elseif (preg_match('/[a-zA-Z_][a-zA-Z0-9_$]*/A', $sql, $match, 0, $start) === 1) {
            $kind = 'word';
            $offset = $start + strlen($match[0]);
        }

        return ['kind' => $kind, 'value' => substr($sql, $start, $offset - $start), 'start' => $start];
    }

    private static function space(string $character): bool
    {
        return str_contains(" \t\r\n\f\v", $character);
    }
}
