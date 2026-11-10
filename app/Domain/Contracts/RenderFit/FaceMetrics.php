<?php

namespace App\Domain\Contracts\RenderFit;

use UnexpectedValueException;

/**
 * Advance widths of one pinned tc-lib face, from its generated metric file (`cw`: codepoint to 1/1000 em, `dw`: the
 * default for a codepoint the table lacks). Width is the plain sum of advances, which is what tc-lib's text cell sets
 * (no kerning, no letter spacing), so a measured width equals the layout's own bounding box.
 */
final class FaceMetrics
{
    /** @var array<string, self> decoded faces by SHA-256 of their metric bytes */
    private static array $cache = [];

    /** @param array<int, int|float> $widths */
    private function __construct(private readonly array $widths, private readonly float $default) {}

    /** @param  string  $bytes  A tc-lib generated `<face>.json`; the caller has already verified it against its pin. */
    public static function fromJson(string $bytes): self
    {
        $key = hash('sha256', $bytes);
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }
        $metrics = json_decode($bytes, true, 64);
        if (! is_array($metrics) || ! is_array($metrics['cw'] ?? null) || $metrics['cw'] === [] || ! is_numeric($metrics['dw'] ?? null)) {
            throw new UnexpectedValueException('Font metrics lack a width table.');
        }
        if (count(self::$cache) >= 16) {
            self::$cache = [];
        }

        return self::$cache[$key] = new self($metrics['cw'], (float) $metrics['dw']);
    }

    /** @param  array<int, int|float>  $widths  Codepoint to advance in 1/1000 em, as the generated metric file's `cw`. */
    public static function fromTable(array $widths, float $default): self
    {
        return new self($widths, $default);
    }

    /** Width of `$text` in millimetres at `$sizePt` points. */
    public function width(string $text, float $sizePt): float
    {
        $units = 0.0;
        foreach (mb_str_split($text) as $character) {
            $units += $this->widths[mb_ord($character)] ?? $this->default;
        }

        return $units / 1000 * $sizePt * 25.4 / 72;
    }
}
