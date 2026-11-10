<?php

namespace App\Domain\Contracts\RenderFit;

/**
 * The width of the widest unbreakable token of a text. tc-lib's text cells and its HTML engine break a line only at a
 * space or a line break: a hyphen, a slash or a no-break space is never a break point. A token wider than its column is
 * the case the renderers handle badly (an earlier tc-lib ran it past the margin; the pinned one hard-breaks it, and the HTML
 * cell then does not paginate the lines), so it is the case the checks single out.
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
}
