<?php

namespace Tests\Unit;

use App\Domain\Commerce\ContractIdentity\ContractGlyphCoverage;
use App\Domain\Commerce\ContractIdentity\ContractIdentityDeclarationV2;
use App\Domain\Commerce\ContractIdentity\ContractPrintableSet;
use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Contracts\ContractRenderProfileRegistry;
use App\Domain\Contracts\Production\ContractBindingException;
use App\Domain\Contracts\Production\VaseyLicenseDocument;
use App\Domain\Contracts\Production\VaseyLicenseLayout;
use App\Domain\Contracts\Production\VaseyLicensePdfRenderer;
use App\Domain\Contracts\Production\VaseyLicenseProfile;
use App\Domain\Contracts\Production\VaseyLicenseSignature;
use App\Domain\Contracts\Production\VaseyLicenseV2Implementation;
use App\Domain\Contracts\Production\VaseyLicenseV2PdfRenderer;
use App\Domain\Contracts\Production\VaseyLicenseV2Profile;
use App\Domain\Contracts\RenderedContract;
use App\Domain\Contracts\VersionedContractRenderer;
use App\Support\CanonicalJson;
use Closure;
use Com\Tecnick\Pdf\Tcpdf;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Contract increment 5, part 2: the `vasey-license-pdf-v2` renderer. SYNTHETIC, NONBINDING fixtures only: an invented
 * template, buyers, prices and a generated stand-in signature. No real template wording, signature or seller value is used.
 */
class VaseyLicenseV2PdfRendererTest extends TestCase
{
    /** sha256 of the golden business render below, under the pinned v2 profile and the synthetic signature. */
    private const GOLDEN_SHA256 = '2b2a04e462c487eb472d21da0e499dbb0d7728c438b953410848bf08a2cc8403';

    private const TEMPLATE = "SYNTHETIC TEST TEMPLATE: {LICENSE_NAME}\n\nNONBINDING FIXTURE\n\n"
        .'This synthetic fixture is dated {CONTRACT_DATE}. It names {CUSTOMER_FULLNAME} of {CUSTOMER_ADDRESS} and {PRODUCER_ALIAS}, '
        ."{STATE_PROVINCE_COUNTRY}.\nA kept line break.\n\nSYNTHETIC TERMS\n\n"
        .'Track {PRODUCT_TITLE}; fee {PRODUCT_PRICE} ({PRODUCT_PRICE_WORD}); files {FILE_TYPE}; share {PUBLISHING_RIGHTS}. '
        .'Copies {DISTRIBUTE_COPIES}; stations {NUMBER_OF_RADIO_STATIONS}; videos {MONETIZED_MUSIC_VIDEOS} ({MONETIZED_MUSIC_VIDEOS_WORD}); '
        .'streams {AUDIO_STREAMS}, {NON_MONETIZED_VIDEO_STREAMS_ALLOWED} and {MONETIZED_VIDEO_STREAMS_ALLOWED}; downloads {FREE_DOWNLOADS}; '
        .'shows {PERFORMANCES_FOR_PROFIT} and {PERFORMANCES_NOT_FOR_PROFIT}.';

    private const FACES = ['heading', 'body', 'body_bold', 'identifier'];

    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scratch = sys_get_temp_dir().'/vasey-license-v2-render-test-'.bin2hex(random_bytes(6));
        mkdir($this->scratch, 0700, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->scratch);
        parent::tearDown();
    }

    /** Call one of the binder test's private synthetic fixtures, so this test binds exactly what increment 3 binds. */
    private static function fixture(string $method, mixed ...$arguments): mixed
    {
        return Closure::bind(static fn (): mixed => ContractVariableBinderTest::{$method}(...$arguments), null, ContractVariableBinderTest::class)();
    }

    /** @return array{0: array<string, string>, 1: array} binder output and the frozen declaration */
    private static function bound(string $buyer = 'business', string $type = 'non-exclusive', array $replace = []): array
    {
        $declaration = array_replace(self::fixture($buyer), $replace);
        $input = self::fixture('taxInput', $declaration, self::fixture('license', self::fixture('terms'), $type));

        return [self::fixture('bind', $input), $declaration];
    }

    private static function document(string $buyer = 'business', string $type = 'non-exclusive', string $template = self::TEMPLATE, array $replace = []): VaseyLicenseDocument
    {
        [$values, $declaration] = self::bound($buyer, $type, $replace);

        return (new VaseyLicenseV2PdfRenderer)->document($template, $values, $declaration);
    }

    /**
     * A generated stand-in signature: a zig-zag stroke on a transparent background, encoded in plain PHP with stored
     * (uncompressed) deflate blocks, so its bytes depend on nothing but this code. Never a real signature. The same
     * generator as the v1 renderer test.
     */
    private function signature(string $name = 'signature.png', int $width = 600, int $height = 180, int $alpha = 0): VaseyLicenseSignature
    {
        $rows = '';
        for ($y = 0; $y < $height; $y++) {
            $rows .= "\0";
            for ($x = 0; $x < $width; $x++) {
                $phase = intdiv($x, 60) % 2 === 0 ? $x % 60 : 60 - $x % 60;
                $ink = $x >= 40 && $x < $width - 40 && abs($y - (60 + $phase)) <= 2;
                $rows .= $ink ? "\x14\x1e\x3c\xff" : "\xff\xff\xff".chr($alpha);
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
        $path = $this->scratch.'/'.$name;
        file_put_contents($path, $png);

        return VaseyLicenseSignature::forTesting($path, hash('sha256', $png));
    }

    private function render(VaseyLicenseDocument $document, ?VaseyLicenseSignature $signature = null, ?string $root = null): RenderedContract
    {
        return (new VaseyLicenseV2PdfRenderer($root))->render($document, VaseyLicenseV2Profile::current($root), $signature ?? $this->signature());
    }

    private function refusesIssuance(string $reason, callable $call): void
    {
        try {
            $call();
            $this->fail('Expected refusal '.$reason);
        } catch (ContractIssuanceException $exception) {
            $this->assertSame($reason, $exception->reason);
        }
    }

    private function refusesBinding(string $reason, ?string $placeholder, callable $call): void
    {
        try {
            $call();
            $this->fail('Expected refusal '.$reason);
        } catch (ContractBindingException $exception) {
            $this->assertSame([$reason, $placeholder], [$exception->reason, $exception->placeholder]);
        }
    }

    /** A copy of everything the v2 profile pins, at the same relative paths under a temporary project root. */
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

    private static function flipLastByte(string $path): void
    {
        $bytes = file_get_contents($path);
        $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) ^ 0x01);
        file_put_contents($path, $bytes);
    }

    /** The text a reader gets from the PDF (poppler `pdftotext`). */
    private function pdfText(string $pdf): string
    {
        $path = $this->scratch.'/extract-'.hash('sha256', $pdf).'.pdf';
        file_put_contents($path, $pdf);
        $extract = new Process(['pdftotext', '-enc', 'UTF-8', $path, '-'], timeout: 30);
        $extract->mustRun();

        return $extract->getOutput();
    }

    /** A layout over the pinned v2 metric files, as the renderer builds it. */
    private static function layout(): VaseyLicenseLayout
    {
        $fonts = VaseyLicenseV2Profile::assetPath('fonts/generated');

        return new VaseyLicenseLayout(new Tcpdf(unit: 'mm', isunicode: true, subsetfont: false, compress: false, mode: '',
            fileOptions: ['allowedHosts' => [], 'allowedPaths' => [$fonts], 'markupAllowedPaths' => []]), VaseyLicenseV2Profile::current(), $fonts);
    }

    /** @return list<int> codepoints checkout accepts under the D-30 rule (`A` + character, as the profile test counts them). */
    private static function checkoutAccepted(): array
    {
        $coverage = ContractPrintableSet::checkout();

        return array_values(array_filter($coverage->codepoints(),
            static fn (int $codepoint): bool => ContractIdentityDeclarationV2::isPrintable('A'.mb_chr($codepoint, 'UTF-8'), $coverage)));
    }

    public function test_the_same_input_renders_identical_bytes_and_matches_the_golden_hash(): void
    {
        $signature = $this->signature();
        $first = $this->render(self::document(), $signature);
        $second = $this->render(self::document(), $signature);

        $this->assertSame($first->pdfBytes, $second->pdfBytes);
        $this->assertSame(hash('sha256', $first->pdfBytes), $first->sha256);
        $this->assertStringStartsWith('%PDF-', $first->pdfBytes);
        $this->assertSame(1, $first->pageCount);
        // No clock reaches the document: its dates are the contract date at UTC midnight.
        $this->assertStringContainsString('/CreationDate (D:20261107000000Z)', $first->pdfBytes);
        $this->assertStringContainsString('/ModDate (D:20261107000000Z)', $first->pdfBytes);
        $this->assertStringContainsString('<xmp:CreateDate>2026-11-07T00:00:00Z', $first->pdfBytes);
        $this->assertSame(1, preg_match_all('/<xmp:\w*Date>([^<]+)</', $first->pdfBytes, $dates) > 0 ? count(array_unique($dates[1])) : 0);
        foreach (['BebasNeue-Regular', 'RedditSans-Regular', 'RedditSans-Bold', 'JetBrainsMono-Regular'] as $face) {
            $this->assertStringContainsString('/BaseFont /'.$face, $first->pdfBytes);
        }
        // The recorded identity is the v2 profile hash bound to the signature pin, and the keywords carry it.
        $effective = CanonicalJson::hash(['profile_sha256' => VaseyLicenseV2Profile::hash(VaseyLicenseV2Profile::current()), 'signature_sha256' => $signature->sha256]);
        $this->assertSame($effective, $first->profileHash);
        $this->assertStringContainsString('vasey-license-pdf-v2,'.$effective, $first->pdfBytes);
        $this->assertStringContainsString('VASEY.AUDIO license profile vasey-license-pdf-v2', $first->pdfBytes);
        $this->assertStringNotContainsString('vasey-license-pdf-v1', $first->pdfBytes);
        $this->assertSame(self::GOLDEN_SHA256, $first->sha256);
    }

    public function test_latin_extended_names_render_and_extract_with_their_own_glyphs(): void
    {
        $individual = self::document('individual', 'non-exclusive', self::TEMPLATE, ['legal_name' => 'Łukasz Żółć']);
        $text = $this->pdfText($this->render($individual)->pdfBytes);
        // Once in the template paragraph and once as the licensee in the acceptance block.
        $this->assertSame(2, substr_count($text, 'Łukasz Żółć'), $text);

        $business = self::document('business', 'non-exclusive', self::TEMPLATE, ['legal_name' => 'Đorđe Kılıç SRL', 'representative_name' => 'Ștefan Țepeș']);
        $text = $this->pdfText($this->render($business)->pdfBytes);
        $this->assertSame(3, substr_count($text, 'Đorđe Kılıç SRL'), $text);
        $this->assertStringContainsString('Accepted for Đorđe Kılıç SRL by Ștefan Țepeș,', $text);

        $exclusive = self::document('exclusiveBuyer', 'exclusive', self::TEMPLATE."\n\nPerforming as {CUSTOMER_ALIAS}.",
            ['legal_name' => 'Dvořák Őrsi', 'artist_name' => 'MC Ęwa Mąkowska']);
        $text = $this->pdfText($this->render($exclusive)->pdfBytes);
        $this->assertStringContainsString('Performing as MC Ęwa Mąkowska.', $text);
        $this->assertStringContainsString('Dvořák Őrsi', $text);

        // v1 cannot print them: its renderer refuses the same document before drawing.
        $this->refusesIssuance('unsupported_input', fn () => (new VaseyLicensePdfRenderer)->render($individual, VaseyLicenseProfile::current(), $this->signature('v1.png')));
    }

    public function test_every_codepoint_checkout_accepts_is_accepted_by_the_renderers_input_rule_and_drawn_by_each_face(): void
    {
        $accepted = self::checkoutAccepted();
        $this->assertCount(357, $accepted);
        $renderer = new VaseyLicenseV2PdfRenderer;
        $coverage = $renderer->coverage();
        $characters = array_map(static fn (int $codepoint): string => mb_chr($codepoint, 'UTF-8'), $accepted);
        foreach ($characters as $character) {
            $this->assertTrue($coverage->covers($character), sprintf('U+%04X', mb_ord($character)));
        }
        // The renderer's own rule is exactly the faces' rule, not merely a superset of checkout's.
        $this->assertSame(ContractPrintableSet::checkout()->codepoints(), $coverage->codepoints());

        // The layout's own per-face check, against the loaded metric files, accepts all of them in every role.
        $layout = self::layout();
        foreach (self::FACES as $role) {
            $layout->assertDrawable($role, [implode('', $characters)]);
        }

        // And a real render sets every one of them in the heading and body faces (braces and controls cannot be template text).
        $printable = implode('', array_filter($characters, static fn (string $c): bool => preg_match('/[{}\p{Cc}]/u', $c) !== 1));
        $rendered = $this->render(self::document('individual', 'non-exclusive', 'SYNTHETIC '.$printable."\n\nBody ".$printable));
        $this->assertSame(1, $rendered->pageCount);
    }

    public function test_a_refused_character_never_reaches_the_renderer(): void
    {
        // Buyer values outside the input rule refuse while the document is built, before any render.
        foreach (['Иван Петров', 'Ελένη Example', 'Nguyễn Văn An', 'Əliyev Example', 'ǈubica Example'] as $name) {
            $this->refusesBinding('unsupported_characters', 'CUSTOMER_FULLNAME', fn () => self::document('individual', 'non-exclusive', self::TEMPLATE, ['legal_name' => $name]));
        }
        $this->refusesBinding('unsupported_characters', null, function (): void {
            [$values, $declaration] = self::bound('business');
            (new VaseyLicenseV2PdfRenderer)->document(self::TEMPLATE, $values, [...$declaration, 'representative_name' => 'Ada Ελένη']);
        });

        // The renderer's own guard refuses anything else a face cannot draw (here template wording) before drawing.
        foreach (self::FACES as $role) {
            foreach (['Иван', 'ặ', '王'] as $text) {
                $this->refusesIssuance('unsupported_input', fn () => self::layout()->assertDrawable($role, [$text]));
            }
        }
        $before = glob(sys_get_temp_dir().'/vasey-license-v2-fonts-*') ?: [];
        $this->refusesIssuance('unsupported_input', fn () => $this->render(self::document('individual', 'non-exclusive', "SYNTHETIC\n\nИван.")));
        $this->refusesIssuance('unsupported_input', fn () => $this->render(self::document('individual', 'non-exclusive', "SYNTHETIC Иван\n\nBody.")));
        // The private font copy is removed on refusal too.
        $this->assertSame($before, glob(sys_get_temp_dir().'/vasey-license-v2-fonts-*') ?: []);
    }

    public function test_rendering_does_not_depend_on_the_project_root_or_another_profiles_font_path(): void
    {
        $signature = $this->signature();
        $here = $this->render(self::document(), $signature);
        $elsewhere = $this->render(self::document(), $signature, $this->copiedRoot());

        $this->assertSame($here->sha256, $elsewhere->sha256);
        if (! defined('K_PATH_FONTS')) {
            define('K_PATH_FONTS', base_path('resources/contracts/test-v2/fonts/generated/'));
        }
        $this->assertSame($here->sha256, $this->render(self::document(), $signature)->sha256);
        // The v1 renderer in the same process does not change what v2 prints.
        (new VaseyLicensePdfRenderer)->render(VaseyLicenseDocument::build(self::TEMPLATE, ...[...self::bound(), ContractGlyphCoverage::vaseyLicenseFaces()]),
            VaseyLicenseProfile::current(), $signature);
        $this->assertSame($here->sha256, $this->render(self::document(), $signature)->sha256);
    }

    public function test_a_changed_missing_or_invalid_signature_refuses_and_a_different_pin_is_a_different_identity(): void
    {
        $document = self::document();
        $signature = $this->signature();
        $first = $this->render($document, $signature);

        $other = $this->render($document, $this->signature('second.png', 300, 90, 255));
        $this->assertNotSame($first->profileHash, $other->profileHash);
        $this->assertNotSame($first->sha256, $other->sha256);
        $this->assertSame($first->textDigest, $other->textDigest);

        self::flipLastByte($signature->path);
        $this->refusesIssuance('signature_changed', fn () => $this->render($document, $signature));
        unlink($signature->path);
        $this->refusesIssuance('signature_unavailable', fn () => $this->render($document, $signature));
        file_put_contents($this->scratch.'/not-a-png.png', 'GIF89a synthetic');
        $notPng = VaseyLicenseSignature::forTesting($this->scratch.'/not-a-png.png', hash_file('sha256', $this->scratch.'/not-a-png.png'));
        $this->refusesIssuance('signature_invalid', fn () => $this->render($document, $notPng));
        $linked = $this->signature('linked-target.png');
        symlink($linked->path, $this->scratch.'/linked.png');
        $this->refusesIssuance('signature_unavailable', fn () => $this->render($document, VaseyLicenseSignature::forTesting($this->scratch.'/linked.png', $linked->sha256)));
    }

    public static function pinnedAssets(): array
    {
        $manifest = json_decode(file_get_contents(dirname(__DIR__, 2).'/'.VaseyLicenseV2Profile::ASSETS_DIRECTORY.'/profile-assets.json'), true, 32, JSON_THROW_ON_ERROR);

        return array_combine(array_keys($manifest['assets']), array_map(static fn (string $path): array => [$path], array_keys($manifest['assets'])));
    }

    #[DataProvider('pinnedAssets')]
    public function test_changing_any_pinned_font_logo_or_source_by_one_byte_refuses(string $asset): void
    {
        $root = $this->copiedRoot();
        $signature = $this->signature();
        $this->render(self::document(), $signature, $root);

        self::flipLastByte($root.'/'.VaseyLicenseV2Profile::ASSETS_DIRECTORY.'/'.$asset);
        $this->refusesIssuance('profile_changed', fn () => $this->render(self::document(), $signature, $root));
        $this->refusesIssuance('profile_changed', fn () => (new VaseyLicenseV2PdfRenderer($root))->render(self::document(), VaseyLicenseV2Profile::current(), $signature));
    }

    public function test_a_changed_manifest_implementation_file_or_retained_profile_refuses(): void
    {
        $signature = $this->signature();
        $root = $this->copiedRoot();
        $manifest = $root.'/'.VaseyLicenseV2Profile::ASSETS_DIRECTORY.'/profile-assets.json';
        file_put_contents($manifest, str_replace('"schema_version": 1', '"schema_version":  1', file_get_contents($manifest)));
        $this->refusesIssuance('profile_changed', fn () => VaseyLicenseV2Profile::current($root));
        $this->refusesIssuance('profile_changed', fn () => (new VaseyLicenseV2PdfRenderer($root))->render(self::document(), VaseyLicenseV2Profile::current(), $signature));

        // The reused v1 classes are in v2's pin too: changing any of them, or the renderer itself, refuses.
        foreach (['VaseyLicenseV2PdfRenderer', 'VaseyLicenseLayout', 'VaseyLicenseDocument', 'VaseyLicenseSignature', 'VaseyLicensePdfRenderer'] as $class) {
            $root = $this->copiedRoot();
            file_put_contents($root.'/app/Domain/Contracts/Production/'.$class.'.php', "\n", FILE_APPEND);
            $this->refusesIssuance('profile_changed', fn () => $this->render(self::document(), $signature, $root));
            File::deleteDirectory($root);
        }

        // A retained profile that differs from the trusted one refuses, as does another version's profile, both ways.
        $profile = VaseyLicenseV2Profile::current();
        $profile['colors']['band'] = '#000000';
        $this->refusesIssuance('profile_changed', fn () => (new VaseyLicenseV2PdfRenderer)->render(self::document(), $profile, $signature));
        $relabelled = [...VaseyLicenseProfile::current(), 'version' => VaseyLicenseV2Profile::VERSION];
        $this->refusesIssuance('profile_changed', fn () => (new VaseyLicenseV2PdfRenderer)->render(self::document(), $relabelled, $signature));
        $this->refusesIssuance('profile_changed', fn () => (new VaseyLicenseV2PdfRenderer)->render(self::document(), VaseyLicenseProfile::current(), $signature));
        $this->refusesIssuance('profile_changed', fn () => (new VaseyLicenseV2PdfRenderer)->render(self::document(), ContractRenderProfileRegistry::metadata('test-buyer-pdf-v2'), $signature));
        $this->refusesIssuance('profile_changed', fn () => (new VaseyLicensePdfRenderer)->render(self::document(), VaseyLicenseV2Profile::current(), $signature));
    }

    /** Wiring condition 1: a document built anywhere but this renderer's document() refuses, even one with the same values. */
    public function test_render_accepts_only_documents_its_own_document_method_built(): void
    {
        [$values, $declaration] = self::bound();
        $signature = $this->signature();
        $this->render(self::document(), $signature);
        // Built directly under the same v2 coverage, under the wider test-v2 DejaVu set, or under v1's faces.
        foreach ([ContractPrintableSet::faces(), ContractGlyphCoverage::pinnedContractFaces(), ContractGlyphCoverage::vaseyLicenseFaces()] as $coverage) {
            $elsewhere = VaseyLicenseDocument::build(self::TEMPLATE, $values, $declaration, $coverage);
            $this->refusesIssuance('unsupported_input', fn () => $this->render($elsewhere, $signature));
        }
        // A test-v2 document can carry what checkout refuses (Greek here); it never reaches the v2 renderer.
        $wide = VaseyLicenseDocument::build(self::TEMPLATE.' Ελένη.', $values, $declaration, ContractGlyphCoverage::pinnedContractFaces());
        $this->refusesIssuance('unsupported_input', fn () => $this->render($wide, $signature));
        // Another instance (or project root) of this renderer is still this renderer's document() under the same pinned set.
        $this->assertStringStartsWith('%PDF-', $this->render((new VaseyLicenseV2PdfRenderer($this->copiedRoot()))->document(self::TEMPLATE, $values, $declaration), $signature)->pdfBytes);
    }

    /** Wiring condition 3: tampered metric files refuse as a changed profile, not as an unexpected exception. */
    public function test_tampered_metric_files_refuse_profile_changed(): void
    {
        [$values, $declaration] = self::bound();
        foreach (VaseyLicenseV2Profile::FACES as $metrics) {
            $root = $this->copiedRoot();
            self::flipLastByte($root.'/'.VaseyLicenseV2Profile::ASSETS_DIRECTORY.'/'.$metrics);
            $renderer = new VaseyLicenseV2PdfRenderer($root);
            $this->refusesIssuance('profile_changed', fn () => $renderer->coverage());
            $this->refusesIssuance('profile_changed', fn () => $renderer->document(self::TEMPLATE, $values, $declaration));
            File::deleteDirectory($root);
        }
    }

    /** Wiring condition 4: a font file swapped after runtime verification refuses; tc-lib only reads a copy of hashed bytes. */
    public function test_a_font_swapped_after_runtime_verification_refuses(): void
    {
        $signature = $this->signature();
        $document = self::document();
        foreach (['fonts/generated/redditsans.json', 'fonts/generated/redditsans.z', 'fonts/generated/bebasneue.z'] as $asset) {
            $root = $this->copiedRoot();
            $path = $root.'/'.VaseyLicenseV2Profile::ASSETS_DIRECTORY.'/'.$asset;
            $swapped = false;
            $renderer = new VaseyLicenseV2PdfRenderer($root, function () use ($path, &$swapped): void {
                // Same size, different bytes, after every pinned file was verified.
                self::flipLastByte($path);
                $swapped = true;
            });
            $this->refusesIssuance('profile_changed', fn () => $renderer->render($document, VaseyLicenseV2Profile::current(), $signature));
            $this->assertTrue($swapped, $asset);
            File::deleteDirectory($root);
        }
        // The hook is a test seam only.
        $this->app['env'] = 'local';
        try {
            $this->refusesIssuance('unsupported_input', fn () => new VaseyLicenseV2PdfRenderer(null, static function (): void {}));
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_the_renderer_is_in_the_v2_pin_and_selected_only_by_the_gated_issuance_dispatch(): void
    {
        $pinned = array_keys(VaseyLicenseV2Profile::current()['assets']['implementation']);
        $this->assertSame(VaseyLicenseV2Implementation::closure(base_path()), $pinned);
        foreach (['VaseyLicenseV2PdfRenderer', 'VaseyLicenseLayout', 'VaseyLicenseDocument', 'VaseyLicenseSignature', 'VaseyLicensePdfRenderer',
            'ContractPrintableSet', 'ContractVariableBinder', 'ContractTemplateCensus', 'RenderedContract'] as $class) {
            $this->assertCount(1, array_filter($pinned, static fn (string $path): bool => str_ends_with($path, '/'.$class.'.php')), $class);
        }
        $this->assertContains('app/Domain/Contracts/Production/VaseyLicenseV2PdfRenderer.php', VaseyLicenseV2Implementation::ROOTS);
        $this->assertNotContains(VaseyLicenseV2Implementation::ANCHOR, $pinned);

        // Not selected outside the gated dispatch: the profile says so for production, the test registry and the versioned
        // dispatcher refuse it, and no code outside the profile's own files and VaseyLicenseIssuance names the renderer.
        $profile = VaseyLicenseV2Profile::current();
        $this->assertSame([false, 'not_selected'], [$profile['test_only'], $profile['issuance']]);
        $this->refusesIssuance('profile_changed', fn () => ContractRenderProfileRegistry::metadata(VaseyLicenseV2Profile::VERSION));
        $this->refusesIssuance('profile_changed', fn () => (new VersionedContractRenderer)->render([], $profile));
        foreach (['app', 'routes', 'config', 'database', 'bootstrap'] as $directory) {
            foreach (File::allFiles(base_path($directory)) as $file) {
                if (str_starts_with($file->getPathname(), base_path('app/Domain/Contracts/Production/VaseyLicenseV2'))
                    || $file->getPathname() === base_path('app/Domain/Grants/VaseyLicenseIssuance.php')) {
                    continue;
                }
                $this->assertStringNotContainsString('VaseyLicenseV2PdfRenderer', $file->getContents(), $file->getPathname());
            }
        }
    }
}
