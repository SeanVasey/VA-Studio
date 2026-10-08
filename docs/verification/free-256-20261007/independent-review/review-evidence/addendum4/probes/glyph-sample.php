<?php
// Review probe: compare ProductionFreeGrantRenderable's glyph test with the Tcpdf font API the pinned renderer uses
// (PdfRenderer: insert dejavusans 'B' and '', isCharDefined + getGidForOrd != 0), on a sample of Unicode scalars.
// Run from the worktree root: php <this> <sample-seed>
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
    $sample = [];
    mt_srand((int) ($argv[1] ?? 256));
    for ($c = 0; $c <= 0xFFFF; $c++) { if ($c < 0xD800 || $c > 0xDFFF) { $sample[$c] = true; } }
    for ($i = 0; $i < 50000; $i++) { $sample[mt_rand(0x10000, 0x10FFFF)] = true; }
    foreach ([0x10000, 0x1F600, 0x1D400, 0x10FFFF, 0xFFFD, 0x02EF, 0x0301, 0x0627] as $c) { $sample[$c] = true; }
    foreach (array_keys($sample) as $c) {
        $ok = $pdf->font->isCharDefined($c) && $pdf->font->getGidForOrd($c) !== 0;
        $api[$c] = ($api[$c] ?? true) && $ok;
    }
}
$differences = [];
$covered = 0;
foreach ($api as $c => $expected) {
    $actual = $glyph->invoke(null, $c);
    $covered += $actual ? 1 : 0;
    if ($actual !== $expected) { $differences[] = sprintf('U+%04X api=%s renderable=%s', $c, json_encode($expected), json_encode($actual)); }
}
echo json_encode(['sampled' => count($api), 'covered_by_both_styles' => $covered, 'differences' => count($differences), 'first' => array_slice($differences, 0, 10),
    'checks' => array_map(fn ($c) => sprintf('U+%04X=%s', $c, json_encode($api[$c])), [0x02EF, 0x0301, 0x0627, 0x00E9, 0x0416, 0x03A9])], JSON_PRETTY_PRINT), "\n";
