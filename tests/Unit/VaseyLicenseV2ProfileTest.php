<?php

namespace Tests\Unit;

use App\Domain\Commerce\ContractIdentity\ContractGlyphCoverage;
use App\Domain\Commerce\ContractIdentity\ContractIdentityDeclarationV2;
use App\Domain\Commerce\ContractIdentity\ContractPrintableSet;
use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Contracts\ContractRenderProfileRegistry;
use App\Domain\Contracts\Production\VaseyLicenseProfile;
use App\Domain\Contracts\Production\VaseyLicenseV2Implementation;
use App\Domain\Contracts\Production\VaseyLicenseV2Profile;
use App\Domain\Rights\LicenseTerritories;
use Com\Tecnick\Pdf\Tcpdf;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Normalizer;
use Tests\TestCase;

/**
 * Contract increment 5, part 1 (decision register, 2026-11-08): the `vasey-license-pdf-v2` successor profile, whose
 * faces add Latin Extended, and the checkout printable set rebound to those faces (D-30 step 5). v1 is unchanged.
 */
class VaseyLicenseV2ProfileTest extends TestCase
{
    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scratch = sys_get_temp_dir().'/vasey-license-v2-test-'.bin2hex(random_bytes(6));
        mkdir($this->scratch, 0700, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->scratch);
        parent::tearDown();
    }

    /** @return list<int> codepoints a one-character suffix of which the D-30 rule accepts (`A` + character). */
    private static function accepted(ContractGlyphCoverage $coverage): array
    {
        return array_values(array_filter($coverage->codepoints(),
            static fn (int $codepoint): bool => ContractIdentityDeclarationV2::isPrintable('A'.mb_chr($codepoint, 'UTF-8'), $coverage)));
    }

    private function copiedRoot(): string
    {
        $root = $this->scratch.'/root';
        File::copyDirectory(base_path(VaseyLicenseV2Profile::ASSETS_DIRECTORY), $root.'/'.VaseyLicenseV2Profile::ASSETS_DIRECTORY);
        foreach (array_keys(VaseyLicenseV2Profile::current()['assets']['implementation']) as $path) {
            File::ensureDirectoryExists(dirname($root.'/'.$path));
            copy(base_path($path), $root.'/'.$path);
        }

        return $root;
    }

    private static function refusesIssuance(callable $call): void
    {
        try {
            $call();
            self::fail('Expected profile_changed.');
        } catch (ContractIssuanceException $e) {
            self::assertSame('profile_changed', $e->reason);
        }
    }

    public function test_the_successor_is_pinned_beside_an_unchanged_v1_and_never_selected_for_issuance(): void
    {
        $profile = VaseyLicenseV2Profile::current();
        $this->assertSame(VaseyLicenseV2Profile::MANIFEST_SHA256, hash_file('sha256', base_path(VaseyLicenseV2Profile::ASSETS_DIRECTORY.'/profile-assets.json')));
        $this->assertSame(['vasey-license-pdf-v2', 'vasey-license-pdf-v1', false, 'not_selected'],
            [$profile['version'], $profile['supersedes'], $profile['test_only'], $profile['issuance']]);
        $this->assertSame(['vasey-license-pdf-v2', 'vasey-license-pdf-v1'], [$profile['assets']['profile_version'], $profile['assets']['supersedes']]);
        $this->assertSame(['in_manifest' => false, 'source' => 'private_storage', 'pin' => 'config:contracts.vasey_license_v1.signature_sha256'], $profile['assets']['signature']);
        VaseyLicenseV2Profile::verifyRuntime($profile);

        // v1 keeps its own directory and manifest pin; v2 differs from it only where the decision says it should.
        $this->assertSame(VaseyLicenseProfile::MANIFEST_SHA256, hash_file('sha256', base_path(VaseyLicenseProfile::ASSETS_DIRECTORY.'/profile-assets.json')));
        $this->assertNotSame(VaseyLicenseProfile::ASSETS_DIRECTORY, VaseyLicenseV2Profile::ASSETS_DIRECTORY);
        $v1 = VaseyLicenseProfile::current();
        $this->assertSame(array_diff_key($v1, ['version' => true, 'assets' => true]),
            array_diff_key($profile, ['version' => true, 'supersedes' => true, 'font_repertoire' => true, 'assets' => true]));
        $this->assertSame(['latin', 'latin-ext'], $profile['font_repertoire']);

        // The test registry refuses it, and outside its own files only the printable set and the gated issuance dispatch
        // name it; only the four paid and paid-tax issuer classes call that dispatch (the rest name it in comments).
        self::refusesIssuance(fn () => ContractRenderProfileRegistry::metadata('vasey-license-pdf-v2'));
        $dispatchers = [];
        foreach (['app/Domain/Grants', 'app/Domain/Commerce', 'app/Domain/Contracts', 'app/Http', 'app/Jobs', 'app/Console', 'app/Providers', 'routes', 'config'] as $directory) {
            foreach (File::allFiles(base_path($directory)) as $file) {
                if (str_contains($file->getContents(), 'VaseyLicenseIssuance') && ! str_ends_with($file->getPathname(), '/app/Domain/Grants/VaseyLicenseIssuance.php')) {
                    $dispatchers[] = substr($file->getPathname(), strlen(base_path()) + 1);
                }
                if (str_contains($file->getPathname(), '/app/Domain/Contracts/Production/VaseyLicenseV2')
                    || str_ends_with($file->getPathname(), '/app/Domain/Commerce/ContractIdentity/ContractPrintableSet.php')
                    || str_ends_with($file->getPathname(), '/app/Domain/Grants/VaseyLicenseIssuance.php')
                    // The container gives the isolated v2 child process the PHP-FPM CLI-binary factory, as it does the pinned renderers.
                    || str_ends_with($file->getPathname(), '/app/Providers/AppServiceProvider.php')) {
                    continue;
                }
                $this->assertStringNotContainsString('VaseyLicenseV2', $file->getContents(), $file->getPathname());
            }
        }
        sort($dispatchers);
        $this->assertSame(['app/Domain/Contracts/Production/VaseyLicenseV2PdfRenderer.php', 'app/Domain/Contracts/Production/VaseyLicenseV2Render.php',
            'app/Domain/Grants/Paid/PaidGrantDocuments.php', 'app/Domain/Grants/Paid/PaidGrants.php', 'app/Domain/Grants/PaidTax/PaidTaxDocuments.php',
            'app/Domain/Grants/PaidTax/PaidTaxGrants.php', 'app/Domain/Grants/VaseyLicenseContractSource.php',
            'app/Domain/Grants/VaseyLicensePreparedSelection.php', 'config/contracts.php'], $dispatchers);
    }

    public function test_the_implementation_pin_is_the_derived_closure_of_the_profile_and_the_printable_set(): void
    {
        $pinned = array_keys(VaseyLicenseV2Profile::current()['assets']['implementation']);
        $this->assertSame(VaseyLicenseV2Implementation::closure(base_path()), $pinned);
        foreach (['VaseyLicenseV2ProfileDefinition', 'VaseyLicenseV2Implementation', 'ContractPrintableSet', 'ContractGlyphCoverage',
            'ContractIdentityDeclarationV2', 'LicenseTerritories', 'CanonicalJson'] as $class) {
            $this->assertCount(1, array_filter($pinned, static fn (string $path): bool => str_ends_with($path, '/'.$class.'.php')), $class);
        }
        $this->assertNotContains(VaseyLicenseV2Implementation::ANCHOR, $pinned);
    }

    public function test_the_sources_are_the_brand_latin_faces_and_the_latin_extended_faces_of_the_same_tarballs(): void
    {
        $assets = VaseyLicenseV2Profile::current()['assets'];
        $brand = json_decode(file_get_contents(public_path('brand/fonts/source-manifest.json')), true, 16, JSON_THROW_ON_ERROR);
        $byPackage = [];
        foreach ($brand['files'] as $file) {
            $byPackage[$file['package']] = $file;
        }
        $this->assertCount(6, $assets['font_source']['faces']);
        $subsets = [];
        foreach ($assets['font_source']['faces'] as $name => $face) {
            $origin = $byPackage[$face['package']];
            // Every face comes from the tarball the brand manifest already pins, by SHA-256.
            $this->assertSame([$origin['version'], $origin['archive_sha256']], [$face['version'], $face['archive_sha256']], $name);
            $this->assertSame('package/files/'.$name, $face['archive_member']);
            $this->assertSame($assets['font_source']['tarballs'][$face['package']]['archive_sha256'], $face['archive_sha256']);
            $subsets[$face['subset']][] = $name;
            if ($face['subset'] === 'latin') {
                $this->assertSame($origin['font_sha256'], $assets['assets']['fonts/source/'.$name]['sha256'], $name);
                $this->assertSame($origin['font'], $face['origin']);
            } else {
                $this->assertSame('vendored', $face['origin']);
                $this->assertStringContainsString('-latin-ext-', $name);
            }
            $license = 'fonts/source/'.basename($origin['license']);
            $this->assertSame($origin['license_sha256'], $assets['assets'][$license]['sha256'], $license);
        }
        $this->assertSame([3, 3], [count($subsets['latin']), count($subsets['latin-ext'])]);
        $this->assertSame(hash_file('sha256', public_path('brand/vasey-audio-logo.png')), $assets['assets'][VaseyLicenseV2Profile::LOGO]['sha256']);
    }

    public function test_the_profile_directory_holds_only_its_pinned_assets_and_no_signature(): void
    {
        $files = array_map(static fn ($file): string => $file->getRelativePathname(), File::allFiles(base_path(VaseyLicenseV2Profile::ASSETS_DIRECTORY)));
        $expected = [...array_keys(VaseyLicenseV2Profile::current()['assets']['assets']), 'PROVENANCE.md', 'profile-assets.json'];
        sort($files);
        sort($expected);
        $this->assertSame($expected, $files);
        foreach ($files as $path) {
            $this->assertDoesNotMatchRegularExpression('/signature/i', $path);
        }
    }

    public function test_a_changed_asset_manifest_or_implementation_file_refuses(): void
    {
        $root = $this->copiedRoot();
        $profile = VaseyLicenseV2Profile::current($root);
        VaseyLicenseV2Profile::verifyRuntime($profile, $root);
        file_put_contents($root.'/app/Domain/Commerce/ContractIdentity/ContractPrintableSet.php', "\n", FILE_APPEND);
        self::refusesIssuance(fn () => VaseyLicenseV2Profile::verifyRuntime($profile, $root));

        $root = $this->copiedRoot();
        file_put_contents(VaseyLicenseV2Profile::assetPath('fonts/ttf/bebasneue.ttf', $root), "\0", FILE_APPEND);
        self::refusesIssuance(fn () => VaseyLicenseV2Profile::verifyRuntime(VaseyLicenseV2Profile::current($root), $root));

        $manifest = VaseyLicenseV2Profile::assetPath('profile-assets.json', $root);
        file_put_contents($manifest, str_replace('"schema_version": 1', '"schema_version":  1', file_get_contents($manifest)));
        self::refusesIssuance(fn () => VaseyLicenseV2Profile::current($root));
        self::refusesIssuance(fn () => VaseyLicenseV2Profile::validate(VaseyLicenseProfile::current()));
    }

    public function test_the_checkout_set_is_the_four_successor_faces_restricted_to_latin_and_common(): void
    {
        $coverage = ContractPrintableSet::checkout();
        $this->assertSame($coverage, ContractPrintableSet::checkout());
        $this->assertSame(['Latin', 'Common'], VaseyLicenseV2Profile::current()['allowed_scripts']);
        // 359 positive-width codepoints are common to the four faces, all Latin or Common; 357 pass the D-30 rule.
        $this->assertCount(359, $coverage->codepoints());
        $this->assertCount(357, self::accepted($coverage));
        $this->assertSame([0x0D, 0xA0], array_values(array_diff($coverage->codepoints(), self::accepted($coverage))));
        $this->assertSame(0, preg_match('/[^\p{Latin}\p{Common}]/u', implode('', array_map(
            static fn (int $codepoint): string => mb_chr($codepoint, 'UTF-8'), $coverage->codepoints()))));
        foreach (self::accepted($coverage) as $codepoint) {
            $this->assertTrue(Normalizer::isNormalized(mb_chr($codepoint, 'UTF-8'), Normalizer::FORM_C), sprintf('U+%04X', $codepoint));
        }
        // The successor only adds to v1's repertoire.
        $this->assertSame([], array_values(array_diff(ContractGlyphCoverage::vaseyLicenseFaces()->codepoints(), $coverage->codepoints())));
    }

    public function test_the_accepted_set_is_a_subset_of_the_input_rule_of_the_renderer_that_prints_today(): void
    {
        // The paid and paid-tax renderers set buyer text in the test-v2 DejaVu faces under their own script rule
        // (pinnedContractFaces); checkout must accept nothing they would refuse after payment.
        $accepted = self::accepted(ContractPrintableSet::checkout());
        $dejavu = self::accepted(ContractGlyphCoverage::pinnedContractFaces());
        $this->assertSame([], array_values(array_diff($accepted, $dejavu)));
        // And nothing the successor's own faces cannot draw, whichever face sets the value.
        $this->assertSame($accepted, self::accepted(ContractPrintableSet::faces(base_path())));
    }

    public function test_latin_extended_names_print_and_scripts_missing_from_any_face_are_refused(): void
    {
        $coverage = ContractPrintableSet::checkout();
        foreach (['Łukasz Żółć', 'Ștefan Țepeș', 'Đorđe Đorđević', 'Gülşen Kılıç', 'Dvořák Ťažký', 'Mąkowska Ęwa', 'Őrsi Űrge',
            'Zoë Émile', "O'Brien-Smith Jr.", 'Søren Ærø', 'Jürgen Groß', 'Example Records Ltd'] as $name) {
            $this->assertTrue(ContractIdentityDeclarationV2::isPrintable($name, $coverage), $name);
        }
        // Cyrillic, Greek and Vietnamese print in DejaVu but not in every successor face (Bebas Neue has no Vietnamese).
        foreach (['Иван Петров', 'Ἀλέξανδρος', 'Ελένη Παπαδοπούλου', 'Nguyễn Văn An', 'Đặng Thị'] as $name) {
            $this->assertTrue(ContractIdentityDeclarationV2::isPrintable($name, ContractGlyphCoverage::pinnedContractFaces()), $name);
            $this->assertFalse(ContractIdentityDeclarationV2::isPrintable($name, $coverage), $name);
        }
        foreach (['Արամ Պետրոսյան', 'ნინო ბერიძე', 'Əliyev', '王小明', 'ǈubica'] as $name) {
            $this->assertFalse(ContractIdentityDeclarationV2::isPrintable($name, $coverage), $name);
        }
    }

    public function test_every_pinned_country_name_prints_in_the_successor_faces(): void
    {
        $coverage = ContractPrintableSet::checkout();
        foreach (LicenseTerritories::COUNTRIES as $code => $name) {
            $this->assertTrue($coverage->covers($name), $code.' '.$name);
        }
    }

    public function test_every_covered_codepoint_is_drawable_by_every_loaded_face(): void
    {
        $profile = VaseyLicenseV2Profile::current();
        $fonts = VaseyLicenseV2Profile::assetPath('fonts/generated');
        $pdf = new Tcpdf(unit: 'mm', isunicode: true, subsetfont: false, compress: false, mode: '', fileOptions: [
            'allowedHosts' => [], 'allowedPaths' => [$fonts], 'markupAllowedPaths' => []]);
        foreach ($profile['faces'] as $face) {
            $pdf->font->insert($pdf->pon, $face, '', 10, ifile: $fonts.'/'.$face.'.json');
            foreach (ContractPrintableSet::checkout()->codepoints() as $codepoint) {
                $this->assertTrue($pdf->font->isCharDefined($codepoint), sprintf('%s U+%04X', $face, $codepoint));
                $this->assertNotSame(0, $pdf->font->getGidForOrd($codepoint), sprintf('%s U+%04X', $face, $codepoint));
            }
        }
    }

    public function test_a_tampered_metric_file_or_manifest_refuses_the_binding(): void
    {
        $root = $this->copiedRoot();
        $this->assertSame(ContractPrintableSet::checkout()->codepoints(), ContractPrintableSet::faces($root)->codepoints());
        $metrics = VaseyLicenseV2Profile::assetPath(VaseyLicenseV2Profile::FACES['heading'], $root);
        file_put_contents($metrics, str_replace('"cw":{', '"cw":{"1046":500,', file_get_contents($metrics)));
        try {
            ContractPrintableSet::faces($root);
            $this->fail('A tampered metric file must refuse.');
        } catch (InvalidArgumentException) {
        }
        file_put_contents(VaseyLicenseV2Profile::assetPath('profile-assets.json', $root), "{}\n");
        $this->expectException(ContractIssuanceException::class);
        ContractPrintableSet::faces($root);
    }
}
