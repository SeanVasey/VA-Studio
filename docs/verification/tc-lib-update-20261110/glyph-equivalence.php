<?php

// Glyph equivalence for ProductionFree renderer revision r3 (tc-lib-pdf 8.81.1 / tc-lib-pdf-font 4.6.2).
// Compares ProductionFreeGrantRenderable::glyph with the Tcpdf font API the renderer uses (ProductionFreeGrantPdfRenderer:
// insert dejavusans 'B' and '', isCharDefined and a non-zero getGidForOrd) over U+0020..U+10FFFF excluding surrogates.
// Run from the worktree root: php docs/verification/tc-lib-update-20261110/glyph-equivalence.php
$GLOBALS['_composer_autoload_path'] = getcwd().'/vendor/autoload.php';
require getcwd().'/vendor/autoload.php';

use App\Domain\Grants\ProductionFree\ProductionFreeGrantRenderable;
use Com\Tecnick\Pdf\Tcpdf;

define('K_PATH_FONTS', getcwd().'/resources/contracts/test-v2/fonts/generated/');
$pdf = new Tcpdf(unit: 'mm', isunicode: true, subsetfont: false, compress: false, mode: '', fileOptions: [
    'allowedHosts' => [], 'allowedPaths' => [rtrim(K_PATH_FONTS, '/')], 'markupAllowedPaths' => []]);
$glyph = new ReflectionMethod(ProductionFreeGrantRenderable::class, 'glyph');
$api = [];
foreach (['B', ''] as $style) {
    $pdf->font->insert($pdf->pon, 'dejavusans', $style, 10);
    for ($c = 0x20; $c <= 0x10FFFF; $c++) {
        if ($c >= 0xD800 && $c <= 0xDFFF) {
            continue;
        }
        $api[$c] = ($api[$c] ?? true) && $pdf->font->isCharDefined($c) && $pdf->font->getGidForOrd($c) !== 0;
    }
}
$differences = 0;
$covered = 0;
$first = [];
foreach ($api as $c => $expected) {
    $actual = $glyph->invoke(null, $c);
    $covered += $actual ? 1 : 0;
    if ($actual !== $expected) {
        $differences++;
        if (count($first) < 10) {
            $first[] = sprintf('U+%04X api=%s renderable=%s', $c, json_encode($expected), json_encode($actual));
        }
    }
}
echo 'checked '.count($api).', differences '.$differences.', covered '.$covered."\n";
foreach ($first as $line) {
    echo $line."\n";
}
exit($differences === 0 ? 0 : 1);
