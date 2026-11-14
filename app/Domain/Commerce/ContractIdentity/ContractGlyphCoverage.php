<?php

namespace App\Domain\Commerce\ContractIdentity;

use App\Domain\Contracts\ContractRenderProfileRegistry;
use App\Domain\Contracts\Production\VaseyLicenseProfile;
use InvalidArgumentException;

/**
 * The codepoints every pinned contract font can draw. A buyer value is printable only when each of its codepoints is
 * covered by all of the faces a contract may set it in, so a name never falls back to a missing-glyph box.
 */
final class ContractGlyphCoverage
{
    private const PROFILE = 'test-buyer-pdf-v2';

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

    private static ?self $pinned = null;

    /**
     * The regular and bold DejaVu Sans faces the `test-buyer-pdf-v2` contract profile pins, which the paid and paid-tax
     * renderers set buyer text in, restricted to the scripts that profile allows. The profile manifest is verified
     * against the registry's pinned hash and each metric file against the SHA-256 the manifest pins, so the coverage can
     * never drift from the faces that actually print, and it accepts nothing the renderer's script rule would refuse
     * after payment. Loaded once per process.
     */
    public static function pinnedContractFaces(): self
    {
        if (self::$pinned !== null) {
            return self::$pinned;
        }
        $profile = ContractRenderProfileRegistry::metadata(self::PROFILE, self::projectRoot());
        $root = self::projectRoot().'/'.ContractRenderProfileRegistry::assetsDirectory(self::PROFILE);
        $paths = [];
        foreach (['fonts/generated/dejavusans.json', 'fonts/generated/dejavusansb.json'] as $asset) {
            $pinned = $profile['assets']['assets'][$asset]['sha256'] ?? null;
            $path = $root.'/'.$asset;
            if (! is_string($pinned) || ! is_file($path) || is_link($path)
                || ! hash_equals($pinned, (string) hash_file('sha256', $path))) {
                throw new InvalidArgumentException('Pinned contract font metrics do not match the profile.');
            }
            $paths[] = $path;
        }

        return self::$pinned = self::fromTcpdfMetrics($paths)->restrictedToScripts($profile['allowed_scripts'] ?? []);
    }

    /**
     * The four faces the `vasey-license-pdf-v1` profile pins (Bebas Neue, Reddit Sans regular and bold, JetBrains
     * Mono), intersected and restricted to that profile's `allowed_scripts`. Every buyer field and substituted value
     * of that profile must be drawable in each of them, whichever face sets it. The manifest is verified against the
     * profile's pinned hash and each metric file against the manifest. The `test-v2` binding above, which checkout
     * uses today, is unchanged. Loaded once per process for the default project root.
     */
    public static function vaseyLicenseFaces(?string $projectRoot = null): self
    {
        if ($projectRoot === null && self::$vaseyLicense !== null) {
            return self::$vaseyLicense;
        }
        $profile = VaseyLicenseProfile::current($projectRoot);
        $paths = [];
        foreach (VaseyLicenseProfile::FACES as $asset) {
            $pinned = $profile['assets']['assets'][$asset]['sha256'] ?? null;
            $path = VaseyLicenseProfile::assetPath($asset, $projectRoot);
            if (! is_string($pinned) || ! is_file($path) || is_link($path)
                || ! hash_equals($pinned, (string) hash_file('sha256', $path))) {
                throw new InvalidArgumentException('Pinned contract font metrics do not match the profile.');
            }
            $paths[] = $path;
        }
        $coverage = self::fromTcpdfMetrics($paths)->restrictedToScripts($profile['allowed_scripts']);
        if ($projectRoot === null) {
            self::$vaseyLicense = $coverage;
        }

        return $coverage;
    }

    private static ?self $vaseyLicense = null;

    /** @return list<int> The covered codepoints, ascending. */
    public function codepoints(): array
    {
        $codepoints = array_keys($this->codepoints);
        sort($codepoints);

        return $codepoints;
    }

    /**
     * Keep only the codepoints whose Unicode script is one of `$scripts` (PCRE script names such as `Latin`).
     *
     * @param  list<string>  $scripts
     */
    public function restrictedToScripts(array $scripts): self
    {
        if ($scripts === []) {
            throw new InvalidArgumentException('A script restriction needs at least one script.');
        }
        foreach ($scripts as $script) {
            if (! is_string($script) || preg_match('/\A[A-Z][A-Za-z_]*\z/', $script) !== 1) {
                throw new InvalidArgumentException('Script names must be plain PCRE script names.');
            }
        }
        $pattern = '/\A[\p{'.implode('}\p{', $scripts).'}]\z/u';
        $kept = [];
        foreach (array_keys($this->codepoints) as $codepoint) {
            $character = mb_chr($codepoint, 'UTF-8');
            if ($character !== false && preg_match($pattern, $character) === 1) {
                $kept[] = $codepoint;
            }
        }

        return new self($kept);
    }

    private static function projectRoot(): string
    {
        return dirname(__DIR__, 4);
    }

    /**
     * Intersect the width tables (`cw`, keyed by codepoint) of tc-lib-pdf-font generated metric files, counting only
     * glyphs with a positive advance width.
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
            foreach ($metrics['cw'] as $key => $width) {
                if (! is_int($key) || ! is_int($width) && ! is_float($width)) {
                    throw new InvalidArgumentException('Font width table entries must map codepoints to widths.');
                }
                // A zero-width glyph (U+FFFC in DejaVu) draws nothing a reader can see, so it is not coverage.
                if ($width > 0) {
                    $face[$key] = true;
                }
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
