<?php

declare(strict_types=1);

use Com\Tecnick\Pdf\Tcpdf;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';

$root = dirname(__DIR__);
$fonts = $root.'/resources/contracts/test-v1/fonts/generated';
define('K_PATH_FONTS', $fonts.'/');
$render = static function () use ($fonts): string {
    $pdf = new Tcpdf(unit: 'mm', isunicode: true, subsetfont: false, compress: false, mode: '', fileOptions: [
        'allowedHosts' => [], 'allowedPaths' => [$fonts], 'markupAllowedPaths' => [],
    ]);
    $pdf->enableDefaultPageContent(false);
    $pdf->setCreator('VASEY.AUDIO test contract profile v1');
    $pdf->setAuthor('VASEY.AUDIO test fixture');
    $pdf->setTitle('Synthetic offline contract');
    $pdf->setSubject('Test profile only');
    $pdf->setDocCreationDate(1767225600);
    $pdf->setDocModificationDate(1767225600);
    $pdf->setFileId(str_repeat('a', 32));
    $pdf->setDocumentId('urn:uuid:00000000-0000-4000-8000-000000000001');
    $font = $pdf->font->insert($pdf->pon, 'dejavusans', '', 10);
    foreach (mb_str_split('Zoë Émile Ελληνικά Кириллица') as $char) {
        if (! $pdf->font->isCharDefined(mb_ord($char)) || $pdf->font->getGidForOrd(mb_ord($char)) === 0) {
            throw new RuntimeException('Synthetic Unicode glyph missing.');
        }
    }
    $pdf->addPage();
    $pdf->page->addContent($font['out']);
    $pdf->addHTMLCell(html: '<div style="font-family:dejavusans;font-size:10pt"><p>Zoë Émile Ελληνικά Кириллица</p>'.str_repeat('<p>Complete frozen synthetic terms. &lt;img src=&quot;file:///etc/passwd&quot;&gt; is plain text.</p>', 150).'<p>FINAL TERMS SENTINEL</p></div>', posx: 15, posy: 15, width: 180);
    $bytes = $pdf->getOutPDFString();
    $pages = count($pdf->page->getPages());
    if ($pages < 2 || $pages > 100 || $pdf->getWarnings() !== [] || ! str_starts_with($bytes, '%PDF-')) {
        throw new RuntimeException('Synthetic PDF output failed profile checks.');
    }
    fwrite(STDOUT, json_encode(['pages' => $pages, 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)], JSON_THROW_ON_ERROR)."\n");
    return $bytes;
};
date_default_timezone_set('UTC');
$first = $render();
$second = $render();
if (! hash_equals($first, $second)) {
    throw new RuntimeException('The fixed renderer profile did not reproduce identical bytes.');
}
$directory = $root.'/storage/app/contract-profile-smoke';
if (! is_dir($directory)) {
    mkdir($directory, 0700, true);
}
file_put_contents($directory.'/synthetic-contract.pdf', $first);
$check = new Process(['qpdf', '--check', $directory.'/synthetic-contract.pdf']);
$check->mustRun();
$text = new Process(['pdftotext', '-enc', 'UTF-8', $directory.'/synthetic-contract.pdf', '-']);
$text->mustRun();
$extracted = $text->getOutput();
foreach (['Zoë Émile Ελληνικά Кириллица', 'FINAL TERMS SENTINEL', 'file:///etc/passwd'] as $required) {
    if (! str_contains($extracted, $required)) {
        throw new RuntimeException('Independent extraction omitted synthetic contract text.');
    }
}
if (substr_count($extracted, 'Complete frozen synthetic terms.') !== 150) {
    throw new RuntimeException('Independent extraction did not retain every full-terms paragraph.');
}
file_put_contents($directory.'/synthetic-contract.txt', $extracted);
file_put_contents($directory.'/qpdf-check.txt', $check->getOutput());
fwrite(STDOUT, "Offline multipage Unicode PDF reproduced identical bytes.\n");
