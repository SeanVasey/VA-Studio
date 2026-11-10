<?php

namespace App\Domain\Contracts\RenderFit;

/**
 * The text runs a finished PDF actually draws, in drawing order, read from the operators of its page content streams:
 * where each run starts (`Td`), which font and size set it (`Tf`), and the glyph ids of its strings. The profiles embed
 * whole TrueType/Identity-H faces, so every string is a run of two-byte glyph ids. This is the check behind the width
 * measurement: whatever a layout did with a line, a glyph that is not in the file, or a run that ends past the column,
 * is found here. Only page content streams are read (a stream whose dictionary holds nothing but `/Length` and `/Filter`).
 *
 * Positions are those of tc-lib's output: one `Td` per run, absolute, in points from the page's lower-left corner.
 */
final class PdfTextGlyphs
{
    private const MAX_STREAM_BYTES = 33554432;

    private const MAX_RUNS = 400000;

    /** @return list<array{font: string, size: float, x: float, y: float, glyphs: list<int>, shifts: array<int, float>}> `shifts` maps a glyph index to the advance (thousandths of an em) a TJ number adds just before it; a number after the last glyph keys the glyph count */
    public static function runs(string $pdf): array
    {
        $runs = [];
        $offset = 0;
        while (($at = strpos($pdf, 'stream', $offset)) !== false) {
            $offset = $at + 6;
            if ($at >= 3 && substr($pdf, $at - 3, 3) === 'end') {
                continue;
            }
            if (! preg_match('/\Gstream\r?\n/', $pdf, $match, 0, $at)) {
                continue;
            }
            $start = $at + strlen($match[0]);
            $end = strpos($pdf, 'endstream', $start);
            if ($end === false) {
                break;
            }
            $offset = $end + 9;
            $open = strrpos(substr($pdf, max(0, $at - 512), 512), '<<');
            $dictionary = $open === false ? '' : substr($pdf, max(0, $at - 512) + $open, 512 - $open);
            $dictionary = substr($dictionary, 0, strpos($dictionary, 'stream') ?: 512);
            preg_match_all('/\/([A-Za-z0-9]+)/', $dictionary, $names);
            if (array_diff($names[1], ['Length', 'Filter', 'FlateDecode']) !== [] || substr_count($dictionary, '<<') !== 1) {
                continue;
            }
            $content = substr($pdf, $start, $end - $start);
            if (str_contains($dictionary, 'FlateDecode')) {
                $content = @gzuncompress(rtrim($content, "\r\n"), self::MAX_STREAM_BYTES);
                if (! is_string($content)) {
                    continue;
                }
            }
            array_push($runs, ...self::scan($content));
            if (count($runs) > self::MAX_RUNS) {
                break;
            }
        }

        return $runs;
    }

    /**
     * Every drawn glyph id with the font that sets it, in order.
     *
     * @return list<array{0: string, 1: int}>
     */
    public static function glyphs(string $pdf): array
    {
        $glyphs = [];
        foreach (self::runs($pdf) as $run) {
            foreach ($run['glyphs'] as $glyph) {
                $glyphs[] = [$run['font'], $glyph];
            }
        }

        return $glyphs;
    }

    /** @return list<array{font: string, size: float, x: float, y: float, glyphs: list<int>, shifts: array<int, float>}> */
    private static function scan(string $content): array
    {
        $runs = [];
        $length = strlen($content);
        $text = false;
        $font = '';
        $size = 0.0;
        $x = 0.0;
        $y = 0.0;
        $operands = [];
        $marks = [];
        $i = 0;
        while ($i < $length) {
            $c = $content[$i];
            if ($c === '(') {
                [$string, $i] = self::literal($content, $i);
                $operands[] = ['s', $string];
            } elseif ($c === '<' && ($content[$i + 1] ?? '') !== '<') {
                $close = strpos($content, '>', $i);
                $close = $close === false ? $length : $close;
                $hex = preg_replace('/[^0-9A-Fa-f]/', '', substr($content, $i + 1, $close - $i - 1));
                $operands[] = ['s', (string) hex2bin(strlen($hex) % 2 === 1 ? $hex.'0' : $hex)];
                $i = $close + 1;
            } elseif ($c === '[') {
                $marks[] = count($operands);
                $i++;
            } elseif ($c === ']') {
                $operands[] = ['a', array_splice($operands, (int) array_pop($marks))];
                $i++;
            } elseif ($c === '%') {
                $i += strcspn($content, "\r\n", $i) + 1;
            } elseif ($c === '/') {
                $name = '';
                for ($i++; $i < $length && ! ctype_space($content[$i]) && ! str_contains('/[]()<>%', $content[$i]); $i++) {
                    $name .= $content[$i];
                }
                $operands[] = ['n', $name];
            } elseif (ctype_digit($c) || $c === '-' || $c === '+' || $c === '.') {
                $number = $c;
                for ($i++; $i < $length && (ctype_digit($content[$i]) || $content[$i] === '.'); $i++) {
                    $number .= $content[$i];
                }
                $operands[] = ['d', (float) $number];
            } elseif (ctype_alpha($c) || $c === '*' || $c === "'" || $c === '"') {
                $word = $c;
                for ($i++; $i < $length && (ctype_alnum($content[$i]) || $content[$i] === '*'); $i++) {
                    $word .= $content[$i];
                }
                if ($word === 'BT') {
                    $text = true;
                } elseif ($word === 'ET') {
                    $text = false;
                } elseif ($text && $word === 'Tf' && count($operands) >= 2) {
                    $font = (string) $operands[count($operands) - 2][1];
                    $size = (float) $operands[count($operands) - 1][1];
                } elseif ($text && $word === 'Td' && count($operands) >= 2) {
                    $x = (float) $operands[count($operands) - 2][1];
                    $y = (float) $operands[count($operands) - 1][1];
                } elseif ($text && $word === 'Tj' && $operands !== [] && $operands[count($operands) - 1][0] === 's') {
                    $runs[] = ['font' => $font, 'size' => $size, 'x' => $x, 'y' => $y, 'glyphs' => self::glyphIds($operands[count($operands) - 1][1]), 'shifts' => []];
                } elseif ($text && $word === 'TJ' && $operands !== [] && $operands[count($operands) - 1][0] === 'a') {
                    $glyphs = [];
                    $shifts = [];
                    foreach ($operands[count($operands) - 1][1] as [$kind, $value]) {
                        if ($kind === 's') {
                            array_push($glyphs, ...self::glyphIds($value));
                        } elseif ($kind === 'd') {
                            $shifts[count($glyphs)] = ($shifts[count($glyphs)] ?? 0.0) - $value;
                        }
                    }
                    $runs[] = ['font' => $font, 'size' => $size, 'x' => $x, 'y' => $y, 'glyphs' => $glyphs, 'shifts' => $shifts];
                }
                $operands = [];
            } else {
                $i++;
            }
        }

        return $runs;
    }

    /** @return list<int> */
    private static function glyphIds(string $bytes): array
    {
        $glyphs = [];
        foreach (str_split($bytes, 2) as $pair) {
            if (strlen($pair) === 2) {
                $glyphs[] = (ord($pair[0]) << 8) | ord($pair[1]);
            }
        }

        return $glyphs;
    }

    /** @return array{0: string, 1: int} the decoded literal string and the offset after it */
    private static function literal(string $content, int $i): array
    {
        $depth = 0;
        $out = '';
        $length = strlen($content);
        for (; $i < $length; $i++) {
            $c = $content[$i];
            if ($c === '\\') {
                $n = $content[++$i] ?? '';
                if ($n >= '0' && $n <= '7') {
                    $octal = $n;
                    while (strlen($octal) < 3 && ($content[$i + 1] ?? '') >= '0' && ($content[$i + 1] ?? '') <= '7') {
                        $octal .= $content[++$i];
                    }
                    $out .= chr(octdec($octal) & 0xFF);
                } elseif ($n === "\r") {
                    // A backslash before a line break continues the string on the next line.
                    if (($content[$i + 1] ?? '') === "\n") {
                        $i++;
                    }
                } else {
                    $out .= match ($n) {
                        'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0C", "\n" => '',
                        default => $n,
                    };
                }
            } elseif ($c === '(') {
                if ($depth++ > 0) {
                    $out .= $c;
                }
            } elseif ($c === ')') {
                if (--$depth === 0) {
                    return [$out, $i + 1];
                }
                $out .= $c;
            } else {
                $out .= $c;
            }
        }

        return [$out, $length];
    }
}
