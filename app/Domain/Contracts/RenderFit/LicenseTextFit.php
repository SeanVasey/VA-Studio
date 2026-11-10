<?php

namespace App\Domain\Contracts\RenderFit;

use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Contracts\Production\VaseyLicenseLayout;
use App\Domain\Contracts\Production\VaseyLicensePdfRenderer;
use Closure;
use Throwable;

/**
 * Refuses, before any render, a license or kit document whose text the shared page layout
 * ({@see VaseyLicenseLayout}, used by the `vasey-license-pdf-v1`, `-v2` and `vasey-kit-license-pdf-v1` profiles) would
 * set past its column. The layout never splits an unbroken token, so a value wider than the column (a long licensee,
 * artist or product name with no space in it, or a template token) runs past the right margin and is cut off at the page
 * edge. This class lives outside every profile's implementation pin: it measures with the profile's own pinned metric
 * files and the layout's own geometry, role and size rules, and changes no rendered byte.
 *
 * Every string the layout sets is measured in the face and size that sets it, in the column it sets it in: title and
 * heading blocks in the heading face, paragraphs in the body face, the licensor and licensee names in the bold body face
 * and the representative line, date and fixed labels in the acceptance column, and the identifier line in the
 * identifier face.
 */
final class LicenseTextFit
{
    /** tc-lib sets a cell's text 0.5 pt right of the cell edge. */
    private const INSET_MM = 0.5 * 25.4 / 72;

    /** The identifier's shape: profile version, two spaces, `urn:sha256:` and 64 hex digits (all one width in the monospace face). */
    private const DIGEST = '0000000000000000000000000000000000000000000000000000000000000000';

    /** @var array<string, FaceMetrics> */
    private array $faces = [];

    /**
     * @param  array  $profile  The verified profile (`faces`, `sizes_pt`, `assets`).
     * @param  Closure  $metricBytes  `fn (string $asset): string` returning the verified bytes of a pinned asset, for example
     *                                `fn ($asset) => VaseyLicenseV2Profile::assetBytes($profile, $asset, $root)`.
     */
    public function __construct(private readonly array $profile, private readonly Closure $metricBytes) {}

    /** Read and verify every face now, so a later {@see assertFits()} reads no file (it may run inside a database transaction). */
    public function warm(): self
    {
        foreach (['heading', 'body', 'body_bold', 'identifier'] as $role) {
            $this->face($role);
        }

        return $this;
    }

    /**
     * @param  object  $document  A {@see \App\Domain\Contracts\Production\VaseyLicenseDocument} or
     *                            {@see \App\Domain\Contracts\Production\SoundKitLicenseDocument}: `blocks` and `acceptance`.
     * @param  string  $version  The profile version printed in the identifier line.
     *
     * @throws RenderFitException when any string would not print inside its column
     */
    public function assertFits(object $document, string $version): void
    {
        try {
            $this->check($document->blocks, $document->acceptance, $version);
        } catch (RenderFitException $exception) {
            throw $exception;
        } catch (Throwable) {
            // Metrics that cannot be read or decoded are a changed profile, never a pass.
            throw new ContractIssuanceException('profile_changed');
        }
    }

    private function check(array $blocks, array $acceptance, string $version): void
    {
        $geometry = VaseyLicensePdfRenderer::geometry();
        $sizes = $this->profile['sizes_pt'];
        $width = (float) $geometry['width'];
        $column = ($width - 10) / 2;
        foreach ($blocks as $index => $block) {
            [$role, $size] = match ($block['type']) {
                'title' => ['heading', $sizes['title']],
                'heading' => ['heading', $sizes['heading']],
                default => ['body', $sizes['body']],
            };
            LineFit::require('block '.$index.' ('.$block['type'].')', $this->face($role), (float) $size, $block['text'], $width, self::INSET_MM);
        }
        foreach (VaseyLicenseLayout::LABELS as $label) {
            LineFit::require('acceptance label', $this->face('heading'), (float) ($sizes['label'] + 3), $label, $column, self::INSET_MM);
        }
        LineFit::require('licensor', $this->face('body_bold'), (float) $sizes['body'], $acceptance['licensor'], $column, self::INSET_MM);
        LineFit::require('licensee', $this->face('body_bold'), (float) $sizes['body'], $acceptance['licensee'], $column, self::INSET_MM);
        if (($acceptance['representative_line'] ?? null) !== null) {
            LineFit::require('representative line', $this->face('body'), (float) $sizes['body'], $acceptance['representative_line'], $column, self::INSET_MM);
        }
        LineFit::require('date', $this->face('body'), (float) $sizes['label'], 'Date: '.$acceptance['date'], $column, self::INSET_MM);
        LineFit::require('identifier', $this->face('identifier'), (float) $sizes['identifier'], $version.'  urn:sha256:'.self::DIGEST, $width, self::INSET_MM);
    }

    private function face(string $role): FaceMetrics
    {
        $name = $this->profile['faces'][$role] ?? null;
        if (! is_string($name) || preg_match('/\A[a-z]+\z/', $name) !== 1) {
            throw new ContractIssuanceException('profile_changed');
        }

        return $this->faces[$role] ??= FaceMetrics::fromJson(($this->metricBytes)('fonts/generated/'.$name.'.json'));
    }
}
