<?php
// Review probe: widest glyphs the 256 admission accepts (repertoire of ProductionFreeGrantText::supportedText plus the
// Renderable glyph test), from the retained DejaVu Sans font metrics. Run from the worktree root.
require getcwd().'/vendor/autoload.php';
use App\Domain\Grants\ProductionFree\ProductionFreeGrantRenderable;
$glyph = new ReflectionMethod(ProductionFreeGrantRenderable::class, 'glyph');
$cw = json_decode(file_get_contents(getcwd().'/resources/contracts/test-v2/fonts/generated/dejavusans.json'), true)['cw'];
$rows = [];
foreach ($cw as $code => $width) {
    $code = (int) $code;
    if ($code < 32 || ($code >= 0xD800 && $code <= 0xDFFF) || $code > 0x10FFFF) { continue; }
    $char = mb_chr($code, 'UTF-8');
    if ($char === false || preg_match('/[^\p{Latin}\p{Greek}\p{Cyrillic}\p{Common}]/u', $char) || preg_match('/[\p{M}\p{Cf}\p{Co}\p{Cs}\p{Cn}]/u', $char)
        || preg_match('/[\x00-\x08\x0B-\x1F\x7F-\x9F]/u', $char) || ! $glyph->invoke(null, $code)) { continue; }
    $rows[] = ['cp' => sprintf('U+%04X', $code), 'width' => (int) $width, 'bytes' => strlen($char), 'per_byte' => round($width / strlen($char), 1)];
}
usort($rows, fn ($a, $b) => $b['per_byte'] <=> $a['per_byte']);
$byWidth = $rows;
usort($byWidth, fn ($a, $b) => $b['width'] <=> $a['width']);
echo json_encode(['admissible' => count($rows), 'widest_per_byte' => array_slice($rows, 0, 8), 'widest' => array_slice($byWidth, 0, 8),
    'W' => $cw[ord('W')], '@' => $cw[ord('@')], 'space' => $cw[32]], JSON_PRETTY_PRINT), "\n";
