<?php

namespace App\Domain\Contracts\Production;

use App\Domain\Contracts\ContractIssuanceException;
use Com\Tecnick\Pdf\Tcpdf;

/**
 * The fixed page layout of `vasey-license-pdf-v1`, drawn into one tc-lib document by {@see VaseyLicensePdfRenderer}.
 * Every face is loaded from its pinned metric file by explicit path; nothing is looked up by name or from a font
 * directory, so another profile's `K_PATH_FONTS` in the same process cannot change which face prints.
 */
final class VaseyLicenseLayout
{
    /** Layout labels, set in the heading face. They frame the acceptance block and are not template wording. */
    public const LABELS = ['PRODUCER / LICENSOR', 'LICENSEE'];

    private float $y = 0.0;

    private readonly array $geometry;

    public function __construct(private readonly Tcpdf $pdf, private readonly array $profile, private readonly string $fonts)
    {
        $this->geometry = VaseyLicensePdfRenderer::geometry();
    }

    public function setY(float $y): void
    {
        $this->y = $y;
    }

    /**
     * Refuse before drawing anything when a face lacks a glyph for a character it will set. The profile's coverage
     * already refuses such buyer values at binding; this is the renderer's own check against the loaded face.
     *
     * @param  list<string>  $texts
     */
    public function assertDrawable(string $role, array $texts): void
    {
        $this->load($role, 10);
        foreach ($texts as $text) {
            foreach (array_unique(mb_str_split($text)) as $character) {
                if ($character === "\n") {
                    continue;
                }
                $codepoint = mb_ord($character);
                if (! $this->pdf->font->isCharDefined($codepoint) || $this->pdf->font->getGidForOrd($codepoint) === 0) {
                    throw new ContractIssuanceException('unsupported_input');
                }
            }
        }
    }

    /** The charcoal band across the top of the first page, with the unmodified lockup at its native aspect ratio. */
    public function band(string $logo, float $height, float $logoHeight, float $pageHeight): void
    {
        $colors = $this->profile['colors'];
        $this->pdf->page->addContent($this->pdf->graph->getRect(0, 0, 210, $height, 'F', ['all' => ['lineWidth' => 0, 'fillColor' => $colors['band'], 'lineColor' => $colors['band']]]));
        $this->pdf->page->addContent($this->pdf->graph->getRect(0, $height, 210, 1.2, 'F', ['all' => ['lineWidth' => 0, 'fillColor' => $colors['accent'], 'lineColor' => $colors['accent']]]));
        $info = getimagesizefromstring($logo);
        if (! is_array($info) || $info[2] !== IMAGETYPE_PNG) {
            throw new ContractIssuanceException('profile_changed');
        }
        $image = $this->pdf->image->add('@'.$logo);
        $width = $logoHeight * $info[0] / $info[1];
        $this->pdf->page->addContent($this->pdf->image->getSetImage($image, $this->geometry['left'], ($height - $logoHeight) / 2, $width, $logoHeight, $pageHeight));
    }

    /** One template block: the title, a section heading or a paragraph. */
    public function block(string $type, string $text): void
    {
        $sizes = $this->profile['sizes_pt'];
        [$role, $size, $before, $keep, $color, $align] = match ($type) {
            'title' => ['heading', $sizes['title'], 0.0, 30.0, 'band', 'L'],
            'heading' => ['heading', $sizes['heading'], 5.0, 24.0, 'band', 'L'],
            default => ['body', $sizes['body'], 3.0, 10.0, 'text', 'J'],
        };
        $this->text($role, $size, $color, $text, $this->geometry['left'], $this->geometry['width'], $before, $keep, $align);
    }

    /**
     * The acceptance block, outside the template text: Sean's signature over the licensor's name on the left; the
     * licensee and, for a business, the representative line on the right; the contract date under both.
     *
     * @param  array{licensor: string, licensee: string, representative_line: ?string, date: string}  $acceptance
     * @param  array{bytes: string, width: int, height: int}  $signature
     * @param  array{width: float, height: float}  $box
     */
    public function acceptance(array $acceptance, array $signature, array $box): void
    {
        $sizes = $this->profile['sizes_pt'];
        $left = $this->geometry['left'];
        $column = ($this->geometry['width'] - 10) / 2;
        $right = $left + $column + 10;
        $this->ensure(8 + 6 + $box['height'] + 30);
        $this->y += 8;
        $this->rule($this->y, 'accent', 0.6);
        $top = $this->y + 4;

        $this->setY($top);
        $this->text('heading', $sizes['label'] + 3, 'muted', self::LABELS[0], $left, $column, 0.0, 0.0, 'L');
        $scale = min($box['width'] / $signature['width'], $box['height'] / $signature['height']);
        $page = $this->pdf->page->getPage();
        $image = $this->pdf->image->add('@'.$signature['bytes']);
        $this->pdf->page->addContent($this->pdf->image->getSetImage($image, $left, $this->y + 2, $signature['width'] * $scale,
            $signature['height'] * $scale, (float) $page['height']));
        $line = $this->y + 2 + $box['height'] + 1;
        $this->pdf->page->addContent($this->pdf->graph->getLine($left, $line, $left + $column, $line, ['lineWidth' => 0.2, 'lineColor' => $this->profile['colors']['muted']]));
        $this->setY($line + 1);
        $this->text('body_bold', $sizes['body'], 'text', $acceptance['licensor'], $left, $column, 0.0, 0.0, 'L');
        $this->text('body', $sizes['label'], 'muted', 'Date: '.$acceptance['date'], $left, $column, 1.0, 0.0, 'L');
        $leftBottom = $this->y;

        $this->setY($top);
        $this->text('heading', $sizes['label'] + 3, 'muted', self::LABELS[1], $right, $column, 0.0, 0.0, 'L');
        $this->setY($line + 1);
        $this->text('body_bold', $sizes['body'], 'text', $acceptance['licensee'], $right, $column, 0.0, 0.0, 'L');
        if ($acceptance['representative_line'] !== null) {
            $this->text('body', $sizes['body'], 'text', $acceptance['representative_line'], $right, $column, 1.0, 0.0, 'L');
        }
        $this->text('body', $sizes['label'], 'muted', 'Date: '.$acceptance['date'], $right, $column, 1.0, 0.0, 'L');
        $this->setY(max($leftBottom, $this->y));
    }

    /** The document identifier, in the identifier face, after the acceptance block. */
    public function identifier(string $identifier): void
    {
        $this->text('identifier', $this->profile['sizes_pt']['identifier'], 'muted', $identifier, $this->geometry['left'], $this->geometry['width'], 8.0, 6.0, 'L');
    }

    private function text(string $role, float $size, string $color, string $text, float $x, float $width, float $before, float $keep, string $align): void
    {
        $this->ensure($before + $keep);
        $this->y += $before;
        $this->face($role, $size);
        $this->pdf->page->addContent($this->pdf->color->getPdfColor($this->profile['colors'][$color], false, 1, false));
        $this->pdf->addTextCellXY($text, -1, $x, $this->y, $width, 0, linespace: $role === 'body' ? 0.6 : 0.0, valign: 'T', halign: $align, drawcell: false);
        $box = $this->pdf->getLastBBox();
        $this->y = $box['y'] + $box['h'];
    }

    private function rule(float $y, string $color, float $height): void
    {
        $fill = $this->profile['colors'][$color];
        $this->pdf->page->addContent($this->pdf->graph->getRect($this->geometry['left'], $y, $this->geometry['width'], $height, 'F',
            ['all' => ['lineWidth' => 0, 'fillColor' => $fill, 'lineColor' => $fill]]));
        $this->y = $y + $height;
    }

    /** Start a new page when the next element would not keep its minimum height on this one. */
    private function ensure(float $height): void
    {
        if ($this->y + $height > $this->geometry['bottom']) {
            $this->pdf->addPage($this->geometry['page']);
            $this->y = $this->geometry['top'];
        }
    }

    private function face(string $role, float $size): void
    {
        $this->pdf->page->addContent($this->load($role, $size)['out']);
    }

    /** Select a face by its pinned metric file; the returned `out` switches the page to it. */
    private function load(string $role, float $size): array
    {
        $face = $this->profile['faces'][$role] ?? throw new ContractIssuanceException('profile_changed');

        return $this->pdf->font->insert($this->pdf->pon, $face, '', $size, ifile: $this->fonts.'/'.$face.'.json');
    }
}
