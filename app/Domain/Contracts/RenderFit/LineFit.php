<?php

namespace App\Domain\Contracts\RenderFit;

/**
 * Whether text can be set inside a column of a given width without a word running past it. tc-lib's text cells and its
 * HTML engine break a line only at a space or a line break: a hyphen, a slash, a no-break space or a long unbroken token
 * is never split, so a token wider than the column runs past the right margin (and past the page edge, where the viewer
 * cuts it off). The check is therefore the width of the widest run between breaks, measured with the face's own advance
 * widths, which is the width the layout itself computes for it.
 */
final class LineFit
{
    /** Millimetres kept clear of the column edge, so a token that fits to within float noise is refused rather than guessed at. */
    public const TOLERANCE_MM = 0.01;

    /** The width in millimetres of the widest run of `$text` between spaces and line breaks. */
    public static function widest(FaceMetrics $face, float $sizePt, string $text): float
    {
        $widest = 0.0;
        foreach (preg_split('/[ \n\r]+/', $text) ?: [] as $token) {
            if ($token !== '') {
                $widest = max($widest, $face->width($token, $sizePt));
            }
        }

        return $widest;
    }

    /**
     * @param  float  $columnMm  The column the text is set in.
     * @param  float  $insetMm  Where in the column the first glyph starts (tc-lib sets text 0.5 pt right of the cell edge).
     *
     * @throws RenderFitException when the widest unbreakable run does not fit
     */
    public static function require(string $field, FaceMetrics $face, float $sizePt, string $text, float $columnMm, float $insetMm = 0.0): void
    {
        $limit = $columnMm - $insetMm - self::TOLERANCE_MM;
        if (self::widest($face, $sizePt, $text) > $limit) {
            throw new RenderFitException($field, 'an unbroken run is wider than its column');
        }
    }
}
