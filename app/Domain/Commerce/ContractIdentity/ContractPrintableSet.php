<?php

namespace App\Domain\Commerce\ContractIdentity;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Contracts\Production\VaseyLicenseV2Profile;
use InvalidArgumentException;

/**
 * D-30 step 5, decided 2026-11-08: the printable set both production checkouts accept, bound to the faces of the
 * `vasey-license-pdf-v2` profile (Bebas Neue, Reddit Sans regular and bold, JetBrains Mono, each with Latin Extended).
 * A buyer value is accepted only when every codepoint has a positive-width glyph in all four faces and belongs to one of
 * the profile's `allowed_scripts`, so whichever face sets it, a name never prints as a missing-glyph box; scripts not in
 * every face (Greek, Cyrillic, Vietnamese) are refused at checkout instead of printing wrongly.
 *
 * {@see ContractGlyphCoverage} and {@see ContractIdentityDeclarationV2} are pinned by the `vasey-license-pdf-v1` manifest,
 * so they keep their `test-v2` binding (`pinnedContractFaces()`, `verifyRetained()`); checkout calls this class instead.
 * This set is a subset of what the `test-v2` paid renderers accept, which a test pins.
 */
final class ContractPrintableSet
{
    private static ?ContractGlyphCoverage $checkout = null;

    /** The checkout printable set for the default project root, loaded once per process. */
    public static function checkout(): ContractGlyphCoverage
    {
        return self::$checkout ??= self::faces();
    }

    /**
     * The four v2 faces, intersected and restricted to the profile's `allowed_scripts`. The manifest is verified against
     * the profile's pinned hash and each metric file against the SHA-256 the manifest pins, so the set cannot drift from
     * the faces the profile prints with. Each metric file is read once: the bytes that are hashed are the bytes decoded.
     */
    public static function faces(?string $projectRoot = null): ContractGlyphCoverage
    {
        $profile = VaseyLicenseV2Profile::current($projectRoot);
        $common = null;
        foreach (VaseyLicenseV2Profile::FACES as $asset) {
            $pinned = $profile['assets']['assets'][$asset]['sha256'] ?? null;
            $path = VaseyLicenseV2Profile::assetPath($asset, $projectRoot);
            $bytes = is_file($path) && ! is_link($path) ? file_get_contents($path) : false;
            if (! is_string($pinned) || ! is_string($bytes) || ! hash_equals($pinned, hash('sha256', $bytes))) {
                throw new InvalidArgumentException('Pinned contract font metrics do not match the profile.');
            }
            $face = self::positiveWidths($bytes);
            $common = $common === null ? $face : array_intersect_key($common, $face);
        }

        return (new ContractGlyphCoverage(array_keys($common ?? [])))->restrictedToScripts($profile['allowed_scripts']);
    }

    /**
     * The codepoints of one tc-lib metric file's width table (`cw`) with a positive advance width, decoded with the same
     * rules as {@see ContractGlyphCoverage::fromTcpdfMetrics()}, which reads by path and so cannot take verified bytes.
     *
     * @return array<int, true>
     */
    private static function positiveWidths(string $bytes): array
    {
        $metrics = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($metrics) || ! is_array($metrics['cw'] ?? null) || $metrics['cw'] === []) {
            throw new InvalidArgumentException('Font metrics lack a width table.');
        }
        $face = [];
        foreach ($metrics['cw'] as $key => $width) {
            if (! is_int($key) || ! is_int($width) && ! is_float($width)) {
                throw new InvalidArgumentException('Font width table entries must map codepoints to widths.');
            }
            if ($width > 0) {
                $face[$key] = true;
            }
        }

        return $face;
    }

    /**
     * Re-verify a declaration read back from sealed checkout evidence against the checkout printable set. A refusal is
     * an integrity failure of stored evidence, not a buyer input error, so it surfaces as the evidence refusal (`changed`).
     */
    public static function verifyRetained(mixed $declaration, bool $exclusive): void
    {
        try {
            ContractIdentityDeclarationV2::verify($declaration, $exclusive, self::checkout());
        } catch (CheckoutException) {
            throw new CheckoutException;
        }
    }
}
