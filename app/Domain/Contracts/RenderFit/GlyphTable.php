<?php

namespace App\Domain\Contracts\RenderFit;

use UnexpectedValueException;

/**
 * One pinned tc-lib face as the finished PDF uses it: codepoint to glyph id (the face's `.ctg.z` CIDToGIDMap, plus `ctgu`
 * for codepoints above U+FFFF) and glyph id to advance (the metric file's `cw`, 1/1000 em). Lets a check read a PDF's
 * two-byte glyph ids back as widths and tell blank glyphs from inked ones.
 */
final class GlyphTable
{
    /** @var array<int, int|float>|null */
    private ?array $advances = null;

    /**
     * @param  array<int, int|float>  $widths  `cw`
     * @param  array<int, int>  $high  `ctgu`
     * @param  string  $table  The inflated CIDToGIDMap: 65,536 big-endian glyph ids.
     */
    public function __construct(private readonly array $widths, private readonly float $default, private readonly array $high, private readonly string $table)
    {
        if (strlen($table) !== 131072) {
            throw new UnexpectedValueException('A glyph map covers the basic multilingual plane.');
        }
    }

    /** @param  string  $json  the face's metric file; @param  string  $compressed  its `.ctg.z` */
    public static function fromFiles(string $json, string $compressed): self
    {
        $metrics = json_decode($json, true, 64);
        $table = @gzuncompress($compressed, 131072);
        if (! is_array($metrics) || ! is_array($metrics['cw'] ?? null) || ! is_numeric($metrics['dw'] ?? null) || ! is_string($table)) {
            throw new UnexpectedValueException('Font metrics are unreadable.');
        }

        return new self($metrics['cw'], (float) $metrics['dw'], is_array($metrics['ctgu'] ?? null) ? $metrics['ctgu'] : [], $table);
    }

    public function gid(int $codepoint): int
    {
        if ($codepoint > 0xFFFF) {
            return (int) ($this->high[$codepoint] ?? 0);
        }

        return (ord($this->table[$codepoint * 2]) << 8) | ord($this->table[$codepoint * 2 + 1]);
    }

    /** The glyph the face draws for U+0020. */
    public function space(): int
    {
        return $this->gid(32);
    }

    /** Advance in 1/1000 em: the first codepoint of the width table that maps to the glyph. */
    public function advance(int $gid): int|float
    {
        if ($this->advances === null) {
            $this->advances = [];
            foreach ($this->widths as $codepoint => $width) {
                $this->advances += [$this->gid((int) $codepoint) => $width];
            }
        }

        return $this->advances[$gid] ?? $this->default;
    }
}
