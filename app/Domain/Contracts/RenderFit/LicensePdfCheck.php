<?php

namespace App\Domain\Contracts\RenderFit;

use App\Domain\Contracts\ContractIssuanceException;
use Closure;
use Throwable;

/**
 * Checks a finished license or kit original against the document it was rendered from, outside every profile's
 * implementation pin and without changing a byte of it. The shared license page layout never splits
 * a token at a hyphen or a slash; under the pinned tc-lib it hard-breaks an over-long token at the column (BL-72: 20,000
 * unbroken characters print in full over nine pages), but an earlier tc-lib let such a token run past the right margin
 * and the page edge cut it off. A profile pins its tc-lib version, so this check does not assume which behavior it is
 * given: it reads the finished PDF's own text runs and refuses `unrenderable_text` unless
 *
 * - every non-blank character of the document is drawn, exactly once (a dropped character cannot hide behind extra
 *   spaces: blank glyphs are told apart per face from the face's own glyph map);
 * - no run starts below the page or ends past the right margin (a glyph that is drawn but cannot be seen).
 *
 * The layout sets the four faces in the order the renderers first load them: heading, body, bold body, identifier
 * (`F1` to `F4`).
 */
final class LicensePdfCheck
{
    /** The layout's text column (millimetres) and its two fixed acceptance labels; a test holds them equal to the layout's own. */
    public const LEFT_MM = 18.0;

    public const WIDTH_MM = 174.0;

    public const LABELS = ['PRODUCER / LICENSOR', 'LICENSEE'];

    private const RESOURCE_ROLES = ['F1' => 'heading', 'F2' => 'body', 'F3' => 'body_bold', 'F4' => 'identifier'];

    /** The column's right edge in points, and what the layout may add to it (it sets text 0.5 pt right of the cell edge). */
    private const SLACK_PT = 0.6;

    /** @var array<string, GlyphTable> */
    private array $tables = [];

    /**
     * @param  array  $profile  The verified profile (`faces`).
     * @param  Closure  $assetBytes  `fn (string $asset): string`, the verified bytes of a pinned asset.
     */
    public function __construct(private readonly array $profile, private readonly Closure $assetBytes) {}

    /**
     * @param  object  $document  A license or sound-kit license document (`blocks`, `acceptance`).
     *
     * @throws RenderFitException
     */
    public function assertContained(string $pdf, object $document, string $version): void
    {
        try {
            $this->check($pdf, $document, $version);
        } catch (RenderFitException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new ContractIssuanceException('profile_changed');
        }
    }

    private function check(string $pdf, object $document, string $version): void
    {
        $right = (self::LEFT_MM + self::WIDTH_MM) * 72 / 25.4 + self::SLACK_PT;
        $acceptance = $document->acceptance;
        $strings = [...array_column($document->blocks, 'text'), ...self::LABELS, $acceptance['licensor'], $acceptance['licensee'],
            (string) ($acceptance['representative_line'] ?? ''), 'Date: '.$acceptance['date'], 'Date: '.$acceptance['date'],
            $version.'  urn:sha256:'.str_repeat('0', 64)];
        // Blank means a space or a line break. Another separator (a no-break space) may or may not share the space glyph,
        // so each one widens the accepted count by one instead of guessing.
        $expected = 0;
        $separators = 0;
        foreach ($strings as $string) {
            $expected += mb_strlen((string) preg_replace('/[\p{Z}\n]+/u', '', $string));
            $separators += mb_strlen((string) preg_replace('/[^\p{Z}]|\x20/u', '', $string));
        }

        $drawn = 0;
        foreach (PdfTextGlyphs::runs($pdf) as $run) {
            $table = $this->table(self::RESOURCE_ROLES[$run['font']] ?? throw new RenderFitException('text run', 'a font the layout does not set'));
            $space = $table->space();
            $advance = 0.0;
            $inked = 0.0;
            foreach ($run['glyphs'] as $index => $glyph) {
                $advance += ($run['shifts'][$index] ?? 0.0) + $table->advance($glyph);
                if ($glyph !== $space) {
                    $drawn++;
                    $inked = $advance;
                }
            }
            if ($run['y'] < 0) {
                throw new RenderFitException('text run', 'a run is drawn below the page');
            }
            if ($right < $run['x'] + $inked * $run['size'] / 1000) {
                throw new RenderFitException('text run', 'a run ends past the right margin');
            }
        }
        if ($drawn < $expected || $drawn > $expected + $separators) {
            throw new RenderFitException('text', 'the document has '.$expected.' inked characters and the PDF draws '.$drawn);
        }
    }

    private function table(string $role): GlyphTable
    {
        $face = $this->profile['faces'][$role] ?? null;
        if (! is_string($face) || preg_match('/\A[a-z]+\z/', $face) !== 1) {
            throw new ContractIssuanceException('profile_changed');
        }

        return $this->tables[$role] ??= GlyphTable::fromFiles(($this->assetBytes)('fonts/generated/'.$face.'.json'), ($this->assetBytes)('fonts/generated/'.$face.'.ctg.z'));
    }
}
