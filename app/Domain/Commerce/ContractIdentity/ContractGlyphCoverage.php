<?php

namespace App\Domain\Commerce\ContractIdentity;

use InvalidArgumentException;

/**
 * The codepoints every pinned contract font can draw. A buyer value is printable only when each of its codepoints is
 * covered by all of the faces a contract may set it in, so a name never falls back to a missing-glyph box.
 */
final class ContractGlyphCoverage
{
    /** @var array<int, true> */
    private readonly array $codepoints;

    /** @param list<int> $codepoints */
    public function __construct(array $codepoints)
    {
        $set = [];
        foreach ($codepoints as $codepoint) {
            if (! is_int($codepoint) || $codepoint < 0 || $codepoint > 0x10FFFF) {
                throw new InvalidArgumentException('Glyph coverage requires Unicode scalar values.');
            }
            $set[$codepoint] = true;
        }
        if ($set === []) {
            throw new InvalidArgumentException('Glyph coverage must not be empty.');
        }
        $this->codepoints = $set;
    }

    /**
     * Intersect the width tables (`cw`, keyed by codepoint) of tc-lib-pdf-font generated metric files.
     *
     * @param  list<string>  $paths
     */
    public static function fromTcpdfMetrics(array $paths): self
    {
        if ($paths === []) {
            throw new InvalidArgumentException('Glyph coverage requires at least one font.');
        }
        $common = null;
        foreach ($paths as $path) {
            $bytes = is_file($path) ? file_get_contents($path) : false;
            $metrics = $bytes === false ? null : json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($metrics) || ! is_array($metrics['cw'] ?? null) || $metrics['cw'] === []) {
                throw new InvalidArgumentException('Font metrics lack a width table.');
            }
            $face = [];
            foreach (array_keys($metrics['cw']) as $key) {
                if (! is_int($key)) {
                    throw new InvalidArgumentException('Font width table keys must be codepoints.');
                }
                $face[$key] = true;
            }
            $common = $common === null ? $face : array_intersect_key($common, $face);
        }

        return new self(array_keys($common));
    }

    public function covers(string $value): bool
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            return false;
        }
        foreach (mb_str_split($value, 1, 'UTF-8') as $character) {
            if (! isset($this->codepoints[mb_ord($character, 'UTF-8')])) {
                return false;
            }
        }

        return true;
    }
}
