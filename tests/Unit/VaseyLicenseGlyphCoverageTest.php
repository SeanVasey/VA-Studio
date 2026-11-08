<?php

namespace Tests\Unit;

use App\Domain\Commerce\ContractIdentity\ContractGlyphCoverage;
use App\Domain\Commerce\ContractIdentity\ContractIdentityDeclarationV2;
use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Contracts\Production\VaseyLicenseProfile;
use App\Domain\Rights\LicenseTerritories;
use Com\Tecnick\Pdf\Tcpdf;
use Illuminate\Support\Facades\File;
use Normalizer;
use Tests\TestCase;

/**
 * D-30 step 5 / increment 4: the printable set bound to the `vasey-license-pdf-v1` faces, and the unchanged `test-v2`
 * DejaVu set the paid renderers use. Checkout is bound to neither since 2026-11-08: it uses the Latin Extended successor
 * faces (`ContractPrintableSet`, VaseyLicenseV2ProfileTest). These stay pinned, so v1's repertoire is still reviewable.
 */
class VaseyLicenseGlyphCoverageTest extends TestCase
{
    /** @return list<int> codepoints a one-character suffix of which the D-30 rule accepts (`A` + character). */
    private static function accepted(ContractGlyphCoverage $coverage): array
    {
        return array_values(array_filter($coverage->codepoints(),
            static fn (int $codepoint): bool => ContractIdentityDeclarationV2::isPrintable('A'.mb_chr($codepoint, 'UTF-8'), $coverage)));
    }

    public function test_the_profile_faces_are_bound_from_their_pinned_metrics_and_restricted_to_latin_and_common(): void
    {
        $coverage = ContractGlyphCoverage::vaseyLicenseFaces();
        $this->assertSame($coverage, ContractGlyphCoverage::vaseyLicenseFaces());
        $this->assertSame(['Latin', 'Common'], VaseyLicenseProfile::current()['allowed_scripts']);
        // The four faces have 214 positive-width codepoints in common, all Latin or Common; 212 pass the D-30 rule.
        $this->assertCount(214, $coverage->codepoints());
        $this->assertCount(212, self::accepted($coverage));
        $this->assertSame([0x0D, 0xA0], array_values(array_diff($coverage->codepoints(), self::accepted($coverage))));
        foreach (self::accepted($coverage) as $codepoint) {
            $this->assertTrue(Normalizer::isNormalized(mb_chr($codepoint, 'UTF-8'), Normalizer::FORM_C), sprintf('U+%04X', $codepoint));
        }
    }

    public function test_the_test_v2_dejavu_set_is_unchanged_and_the_v1_brand_set_is_a_strict_subset_of_it(): void
    {
        $brand = self::accepted(ContractGlyphCoverage::vaseyLicenseFaces());
        $dejavu = self::accepted(ContractGlyphCoverage::pinnedContractFaces());
        $this->assertCount(3799, $dejavu);
        $this->assertSame([], array_values(array_diff($brand, $dejavu)));
        // What the brand faces would newly refuse, by example: Latin Extended-A/B and Additional, Greek and Cyrillic.
        foreach (['Łukasz Żółć', 'Đorđe Đorđević', 'Ștefan Țepeș', 'Nguyễn Văn An', 'Ἀλέξανδρος', 'Иван Петров', 'Gülşen Kılıç'] as $name) {
            $this->assertTrue(ContractIdentityDeclarationV2::isPrintable($name, ContractGlyphCoverage::pinnedContractFaces()), $name);
            $this->assertFalse(ContractIdentityDeclarationV2::isPrintable($name, ContractGlyphCoverage::vaseyLicenseFaces()), $name);
        }
        foreach (['Zoë Émile', "O'Brien-Smith Jr.", 'Søren Ærø', 'François Lefèvre', 'Íñigo Muñoz', 'Jürgen Groß', 'Example Records Ltd'] as $name) {
            $this->assertTrue(ContractIdentityDeclarationV2::isPrintable($name, ContractGlyphCoverage::vaseyLicenseFaces()), $name);
        }
    }

    public function test_every_pinned_country_name_prints_in_the_brand_faces(): void
    {
        $coverage = ContractGlyphCoverage::vaseyLicenseFaces();
        foreach (LicenseTerritories::COUNTRIES as $code => $name) {
            $this->assertTrue($coverage->covers($name), $code.' '.$name);
        }
    }

    public function test_every_covered_codepoint_is_drawable_by_every_loaded_face(): void
    {
        $profile = VaseyLicenseProfile::current();
        $fonts = VaseyLicenseProfile::assetPath('fonts/generated');
        $pdf = new Tcpdf(unit: 'mm', isunicode: true, subsetfont: false, compress: false, mode: '', fileOptions: [
            'allowedHosts' => [], 'allowedPaths' => [$fonts], 'markupAllowedPaths' => []]);
        foreach ($profile['faces'] as $face) {
            $pdf->font->insert($pdf->pon, $face, '', 10, ifile: $fonts.'/'.$face.'.json');
            foreach (ContractGlyphCoverage::vaseyLicenseFaces()->codepoints() as $codepoint) {
                $this->assertTrue($pdf->font->isCharDefined($codepoint), sprintf('%s U+%04X', $face, $codepoint));
                $this->assertNotSame(0, $pdf->font->getGidForOrd($codepoint), sprintf('%s U+%04X', $face, $codepoint));
            }
        }
    }

    public function test_a_tampered_metric_file_or_manifest_refuses_the_binding(): void
    {
        $root = sys_get_temp_dir().'/vasey-coverage-'.bin2hex(random_bytes(6));
        try {
            File::copyDirectory(base_path(VaseyLicenseProfile::ASSETS_DIRECTORY), $root.'/'.VaseyLicenseProfile::ASSETS_DIRECTORY);
            $this->assertSame(ContractGlyphCoverage::vaseyLicenseFaces()->codepoints(), ContractGlyphCoverage::vaseyLicenseFaces($root)->codepoints());
            $metrics = VaseyLicenseProfile::assetPath(VaseyLicenseProfile::FACES['body'], $root);
            file_put_contents($metrics, str_replace('"cw":{', '"cw":{"321":500,', file_get_contents($metrics)));
            try {
                ContractGlyphCoverage::vaseyLicenseFaces($root);
                $this->fail('A tampered metric file must refuse.');
            } catch (\InvalidArgumentException) {
            }
            file_put_contents(VaseyLicenseProfile::assetPath('profile-assets.json', $root), "{}\n");
            $this->expectException(ContractIssuanceException::class);
            ContractGlyphCoverage::vaseyLicenseFaces($root);
        } finally {
            File::deleteDirectory($root);
        }
    }
}
