<?php

namespace Tests\Unit;

use App\Domain\Commerce\ContractIdentity\ContractGlyphCoverage;
use App\Domain\Contracts\Production\SoundKitLicenseDocument;
use App\Domain\Contracts\Production\VaseyKitLicensePdfRenderer;
use App\Domain\Contracts\Production\VaseyKitLicenseProfile;
use App\Domain\Contracts\Production\VaseyLicenseDocument;
use App\Domain\Contracts\Production\VaseyLicenseLayout;
use App\Domain\Contracts\Production\VaseyLicensePdfRenderer;
use App\Domain\Contracts\Production\VaseyLicenseProfile;
use App\Domain\Contracts\Production\VaseyLicenseSignature;
use App\Domain\Contracts\Production\VaseyLicenseV2PdfRenderer;
use App\Domain\Contracts\Production\VaseyLicenseV2Profile;
use App\Domain\Contracts\RenderFit\LicensePdfCheck;
use App\Domain\Contracts\RenderFit\PdfTextGlyphs;
use App\Domain\Contracts\RenderFit\RenderFitException;
use Closure;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * BL-72: the finished original is checked against the document it came from: every inked character drawn once, no run
 * below the page or past the right margin. The pinned tc-lib (8.84.0) hard-breaks an over-long unbroken token at its
 * column, so such documents render in full; the check is what keeps that true if a layout ever stops doing so, and what
 * refuses a PDF that lost or displaced text. SYNTHETIC, NONBINDING fixtures only.
 */
class LicensePdfCheckTest extends TestCase
{
    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scratch = sys_get_temp_dir().'/license-pdf-check-'.bin2hex(random_bytes(6));
        mkdir($this->scratch, 0700, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->scratch);
        parent::tearDown();
    }

    private static function fixture(string $method, mixed ...$arguments): mixed
    {
        return Closure::bind(static fn (): mixed => ContractVariableBinderTest::{$method}(...$arguments), null, ContractVariableBinderTest::class)();
    }

    /** @return array{0: array<string, string>, 1: array} */
    private static function bound(array $declaration = []): array
    {
        $declaration = array_replace(self::fixture('business'), $declaration);
        $input = self::fixture('taxInput', $declaration, self::fixture('license', self::fixture('terms')));

        return [self::fixture('bind', $input), $declaration];
    }

    private static function template(string $paragraph, string $title = 'Synthetic title', string $heading = 'SYNTHETIC HEADING'): string
    {
        return $title."\n\n".$heading."\n\n".$paragraph;
    }

    private static function v2Document(string $paragraph = 'Plain words.', array $declaration = [], string $title = 'Synthetic title'): VaseyLicenseDocument
    {
        [$values, $declaration] = self::bound($declaration);

        return (new VaseyLicenseV2PdfRenderer)->document(self::template($paragraph, $title), $values, $declaration);
    }

    private function signature(): VaseyLicenseSignature
    {
        $width = 600;
        $height = 180;
        $rows = '';
        for ($y = 0; $y < $height; $y++) {
            $rows .= "\0";
            for ($x = 0; $x < $width; $x++) {
                $phase = intdiv($x, 60) % 2 === 0 ? $x % 60 : 60 - $x % 60;
                $ink = $x >= 40 && $x < $width - 40 && abs($y - (60 + $phase)) <= 2;
                $rows .= $ink ? "\x14\x1e\x3c\xff" : "\xff\xff\xff\x00";
            }
        }
        $deflate = "\x78\x01";
        foreach (str_split($rows, 65535) as $index => $block) {
            $last = ($index + 1) * 65535 >= strlen($rows);
            $deflate .= chr($last ? 1 : 0).pack('v', strlen($block)).pack('v', strlen($block) ^ 0xFFFF).$block;
        }
        $deflate .= hash('adler32', $rows, true);
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $png = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0)).$chunk('IDAT', $deflate).$chunk('IEND', '');
        $path = $this->scratch.'/signature.png';
        file_put_contents($path, $png);

        return VaseyLicenseSignature::forTesting($path, hash('sha256', $png));
    }

    private function v2Check(): LicensePdfCheck
    {
        $profile = VaseyLicenseV2Profile::current();

        return new LicensePdfCheck($profile, static fn (string $asset): string => VaseyLicenseV2Profile::assetBytes($profile, $asset));
    }

    private function v2Pdf(VaseyLicenseDocument $document): string
    {
        return (new VaseyLicenseV2PdfRenderer)->render($document, VaseyLicenseV2Profile::current(), $this->signature())->pdfBytes;
    }

    private function refused(callable $call, string $containing): void
    {
        try {
            $call();
            $this->fail('Expected the check to refuse.');
        } catch (RenderFitException $exception) {
            $this->assertSame('unrenderable_text', $exception->reason());
            $this->assertStringContainsString($containing, $exception->getMessage());
        }
    }

    public static function fullyContained(): array
    {
        return [
            'ordinary words' => ['Plain words.', []],
            'a 254-character unbroken licensee name' => ['Plain', ['legal_name' => str_repeat('Wonderful', 28).'Wo']],
            'a 60-character unbroken representative' => ['Plain', ['representative_name' => str_repeat('M', 60)]],
            'one 20,000-character token' => [str_repeat('@', 20000), []],
            'many medium tokens' => [implode(' ', array_fill(0, 300, str_repeat('abcdefghij', 4))), []],
            'wide glyphs, hyphens and slashes with no space' => [str_repeat("\u{2014}-/\u{0150}", 400), []],
            'tokens at the column width' => [implode(' ', array_fill(0, 60, str_repeat('W', 52))), []],
        ];
    }

    #[DataProvider('fullyContained')]
    public function test_v2_documents_render_in_full_and_pass(string $paragraph, array $declaration): void
    {
        $document = self::v2Document($paragraph, $declaration);
        $this->v2Check()->assertContained($this->v2Pdf($document), $document, VaseyLicenseV2Profile::VERSION);
        $this->assertTrue(true);
    }

    public function test_a_long_title_and_a_heading_set_in_the_large_face_pass_when_drawn(): void
    {
        $document = self::v2Document(title: str_repeat('W', 90));
        $this->v2Check()->assertContained($this->v2Pdf($document), $document, VaseyLicenseV2Profile::VERSION);
        $this->assertTrue(true);
    }

    public function test_the_check_assumes_the_layouts_own_column_and_labels(): void
    {
        $geometry = VaseyLicensePdfRenderer::geometry();
        $this->assertSame([$geometry['left'], $geometry['width']], [LicensePdfCheck::LEFT_MM, LicensePdfCheck::WIDTH_MM]);
        $this->assertSame(VaseyLicenseLayout::LABELS, LicensePdfCheck::LABELS);
    }

    public function test_the_faces_are_the_ones_the_check_assumes(): void
    {
        $profile = VaseyLicenseV2Profile::current();
        $pdf = $this->v2Pdf(self::v2Document('Plain words.'));
        $fonts = array_values(array_unique(array_column(PdfTextGlyphs::runs($pdf), 'font')));
        sort($fonts);
        $this->assertSame(['F1', 'F2', 'F3', 'F4'], $fonts);
        $this->assertSame(['heading', 'body', 'body_bold', 'identifier'], array_keys(array_intersect_key($profile['faces'], array_flip(['heading', 'body', 'body_bold', 'identifier']))));
    }

    public function test_a_pdf_that_lost_text_is_refused(): void
    {
        $document = self::v2Document('Some words that must all be drawn.');
        $pdf = $this->v2Pdf($document);
        // Drop the last glyph pair of the longest body run.
        $runs = PdfTextGlyphs::runs($pdf);
        $this->assertNotEmpty($runs);
        $other = self::v2Document('Some words that must all be drawn, and one more sentence that the PDF never had.');
        $this->refused(fn () => $this->v2Check()->assertContained($pdf, $other, VaseyLicenseV2Profile::VERSION), 'inked characters');
        $shorter = self::v2Document('Some words.');
        $this->refused(fn () => $this->v2Check()->assertContained($pdf, $shorter, VaseyLicenseV2Profile::VERSION), 'inked characters');
    }

    public function test_a_run_drawn_below_the_page_or_past_the_margin_is_refused(): void
    {
        $document = self::v2Document('Plain words.');
        $pdf = $this->v2Pdf($document);
        $first = PdfTextGlyphs::runs($pdf)[0];
        $td = sprintf('%.6F %.6F Td', $first['x'], $first['y']);
        $this->assertSame(1, substr_count($pdf, $td));

        $this->refused(fn () => $this->v2Check()->assertContained(str_replace($td, sprintf('%.6F %.6F Td', $first['x'], -20), $pdf), $document, VaseyLicenseV2Profile::VERSION), 'below the page');
        $this->refused(fn () => $this->v2Check()->assertContained(str_replace($td, sprintf('%.6F %.6F Td', 540.0, $first['y']), $pdf), $document, VaseyLicenseV2Profile::VERSION), 'right margin');
    }

    public function test_the_v1_renderer_output_passes_under_its_own_profile(): void
    {
        [$values, $declaration] = self::bound(['legal_name' => str_repeat('Wonderful', 20)]);
        $document = VaseyLicenseDocument::build(self::template('Plain words.'), $values, $declaration, ContractGlyphCoverage::vaseyLicenseFaces());
        $profile = VaseyLicenseProfile::current();
        $pdf = (new VaseyLicensePdfRenderer)->render($document, $profile, $this->signature())->pdfBytes;
        (new LicensePdfCheck($profile, static fn (string $asset): string => VaseyLicenseProfile::assetBytes($profile, $asset)))
            ->assertContained($pdf, $document, VaseyLicenseProfile::VERSION);
        $this->assertTrue(true);
    }

    public function test_the_kit_renderer_output_passes_under_its_own_profile_including_an_unbroken_kit_name(): void
    {
        $document = Closure::bind(static fn (): SoundKitLicenseDocument => VaseyKitLicensePdfRendererTest::document(VaseyKitLicensePdfRendererTest::TEMPLATE,
            ['kit_name' => str_repeat('Kit', 30), 'licensee_name' => str_repeat('Licensee', 14)]), null, VaseyKitLicensePdfRendererTest::class)();
        $profile = VaseyKitLicenseProfile::current();
        $pdf = (new VaseyKitLicensePdfRenderer)->render($document, $profile, $this->signature())->pdfBytes;
        $check = new LicensePdfCheck($profile, static fn (string $asset): string => VaseyKitLicenseProfile::assetBytes($profile, $asset));
        $check->assertContained($pdf, $document, VaseyKitLicenseProfile::VERSION);

        $runs = PdfTextGlyphs::runs($pdf);
        $first = $runs[0];
        $td = sprintf('%.6F %.6F Td', $first['x'], $first['y']);
        $this->refused(fn () => $check->assertContained(str_replace($td, sprintf('%.6F %.6F Td', 560.0, $first['y']), $pdf), $document, VaseyKitLicenseProfile::VERSION), 'right margin');
    }
}
