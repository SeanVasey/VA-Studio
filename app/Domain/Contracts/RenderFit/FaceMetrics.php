<?php

namespace App\Domain\Contracts\RenderFit;

/**
 * Advance widths of one pinned tc-lib face, from its generated metric file (`cw`: codepoint to 1/1000 em, `dw`: the
 * default for a codepoint the table lacks). Width is the plain sum of advances, which is what tc-lib's text cell sets
 * (no kerning, no letter spacing).
 */
final class FaceMetrics
{
    /** @param array<int, int|float> $widths */
    private function __construct(private readonly array $widths, private readonly float $default) {}

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
