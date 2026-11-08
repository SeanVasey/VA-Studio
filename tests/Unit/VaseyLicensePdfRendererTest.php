<?php

namespace Tests\Unit;

use App\Domain\Commerce\ContractIdentity\ContractGlyphCoverage;
use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Contracts\ContractRenderProfileRegistry;
use App\Domain\Contracts\Production\ContractBindingException;
use App\Domain\Contracts\Production\ContractTemplateCensus;
use App\Domain\Contracts\Production\ContractVariableBinder;
use App\Domain\Contracts\Production\VaseyLicenseDocument;
use App\Domain\Contracts\Production\VaseyLicenseImplementation;
use App\Domain\Contracts\Production\VaseyLicenseLayout;
use App\Domain\Contracts\Production\VaseyLicensePdfRenderer;
use App\Domain\Contracts\Production\VaseyLicenseProfile;
use App\Domain\Contracts\Production\VaseyLicenseSignature;
use App\Domain\Contracts\RenderedContract;
use App\Domain\Contracts\VersionedContractRenderer;
use Closure;
use Com\Tecnick\Pdf\Tcpdf;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Increment 4: the `vasey-license-pdf-v1` renderer. SYNTHETIC, NONBINDING fixtures only: an invented template, buyers,
 * prices and a generated stand-in signature. No real template wording, signature or seller value is used.
 */
class VaseyLicensePdfRendererTest extends TestCase
{
    /** sha256 of the golden business render below, under the pinned profile and the synthetic signature. */
    private const GOLDEN_SHA256 = '4491ac3bf697f3147c27be8ea2c55c3d8d02b99d5f131cc601974302c2b0d31b';

    private const TEMPLATE = "SYNTHETIC TEST TEMPLATE: {LICENSE_NAME}\n\nNONBINDING FIXTURE\n\n"
        .'This synthetic fixture is dated {CONTRACT_DATE}. It names {CUSTOMER_FULLNAME} of {CUSTOMER_ADDRESS} and {PRODUCER_ALIAS}, '
        ."{STATE_PROVINCE_COUNTRY}.\nA kept line break.\n\nSYNTHETIC TERMS\n\n"
        .'Track {PRODUCT_TITLE}; fee {PRODUCT_PRICE} ({PRODUCT_PRICE_WORD}); files {FILE_TYPE}; share {PUBLISHING_RIGHTS}. '
        .'Copies {DISTRIBUTE_COPIES}; stations {NUMBER_OF_RADIO_STATIONS}; videos {MONETIZED_MUSIC_VIDEOS} ({MONETIZED_MUSIC_VIDEOS_WORD}); '
        .'streams {AUDIO_STREAMS}, {NON_MONETIZED_VIDEO_STREAMS_ALLOWED} and {MONETIZED_VIDEO_STREAMS_ALLOWED}; downloads {FREE_DOWNLOADS}; '
        .'shows {PERFORMANCES_FOR_PROFIT} and {PERFORMANCES_NOT_FOR_PROFIT}.';

    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scratch = sys_get_temp_dir().'/vasey-license-test-'.bin2hex(random_bytes(6));
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
    private static function bound(string $buyer = 'business', string $type = 'non-exclusive'): array
    {
        $declaration = self::fixture($buyer);
        $input = self::fixture('taxInput', $declaration, self::fixture('license', self::fixture('terms'), $type));

        return [self::fixture('bind', $input), $declaration];
    }

    private static function document(string $buyer = 'business', string $type = 'non-exclusive', string $template = self::TEMPLATE): VaseyLicenseDocument
    {
        [$values, $declaration] = self::bound($buyer, $type);

        return VaseyLicenseDocument::build($template, $values, $declaration, ContractGlyphCoverage::vaseyLicenseFaces());
    }

    /**
     * A generated stand-in signature: a zig-zag stroke on a transparent background, encoded in plain PHP with stored
     * (uncompressed) deflate blocks, so its bytes depend on nothing but this code. Never a real signature.
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
        return (new VaseyLicensePdfRenderer($root))->render($document, VaseyLicenseProfile::current($root), $signature ?? $this->signature());
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
            $this->assertContains($exception->reason, ContractBindingException::REASONS);
        }
    }

    /** A copy of everything the profile pins, at the same relative paths under a temporary project root. */
    private function copiedRoot(): string
    {
        $root = $this->scratch.'/root';
        File::copyDirectory(base_path(VaseyLicenseProfile::ASSETS_DIRECTORY), $root.'/'.VaseyLicenseProfile::ASSETS_DIRECTORY);
        foreach (array_keys(VaseyLicenseProfile::current()['assets']['implementation']) as $path) {
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

    /** The text a reader gets from the PDF (poppler `pdftotext`, as the paid-tax journey test uses). */
    private function pdfText(string $pdf): string
    {
        $path = $this->scratch.'/extract-'.hash('sha256', $pdf).'.pdf';
        file_put_contents($path, $pdf);
        $extract = new Process(['pdftotext', '-enc', 'UTF-8', $path, '-'], timeout: 30);
        $extract->mustRun();

        return $extract->getOutput();
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
        $this->assertSame(self::GOLDEN_SHA256, $first->sha256);
    }

    public function test_the_renderer_checks_every_character_against_the_face_that_sets_it(): void
    {
        $profile = VaseyLicenseProfile::current();
        $fonts = VaseyLicenseProfile::assetPath('fonts/generated');
        $layout = new VaseyLicenseLayout(new Tcpdf(unit: 'mm', isunicode: true, subsetfont: false, compress: false, mode: '',
            fileOptions: ['allowedHosts' => [], 'allowedPaths' => [$fonts], 'markupAllowedPaths' => []]), $profile, $fonts);
        $layout->assertDrawable('body', ["Zoë Example\nÆrø €1"]);
        foreach (['heading', 'body', 'body_bold', 'identifier'] as $role) {
            $this->refusesIssuance('unsupported_input', fn () => $layout->assertDrawable($role, ['Łukasz']));
        }
        $this->refusesIssuance('profile_changed', fn () => $layout->assertDrawable('script', ['A']));
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
    }

    public function test_the_business_representative_line_is_in_the_acceptance_block_and_absent_for_individuals(): void
    {
        $business = self::document('business');
        $this->assertSame('Accepted for Example Records Ltd by Ada Example, authorized representative.', $business->acceptance['representative_line']);
        $this->assertSame(['Synthetic Producer Alias', 'Example Records Ltd'], [$business->acceptance['licensor'], $business->acceptance['licensee']]);
        // Outside the template text: no template block carries it.
        $this->assertStringNotContainsString('authorized representative', implode("\n", array_column($business->blocks, 'text')));
        $text = $this->pdfText($this->render($business)->pdfBytes);
        $this->assertStringContainsString('Accepted for Example Records Ltd by Ada', $text);
        $this->assertStringContainsString('authorized representative.', $text);

        $individual = self::document('individual');
        $this->assertNull($individual->acceptance['representative_line']);
        $this->assertSame('Zoë Example Buyer', $individual->acceptance['licensee']);
        $text = $this->pdfText($this->render($individual)->pdfBytes);
        $this->assertStringNotContainsString('authorized representative', $text);
        $this->assertStringNotContainsString('Accepted for', $text);
        $this->assertStringContainsString('Zoë Example Buyer', $text);
    }

    public function test_the_template_is_substituted_once_and_laid_out_by_the_pinned_grammar(): void
    {
        $document = self::document('individual');
        $this->assertSame(['title', 'heading', 'paragraph', 'heading', 'paragraph'], array_column($document->blocks, 'type'));
        $this->assertSame('SYNTHETIC TEST TEMPLATE: Synthetic Premium Lease', $document->blocks[0]['text']);
        $this->assertStringContainsString("Synthetic State, Synthetic Country.\nA kept line break.", $document->blocks[2]['text']);
        $this->assertStringContainsString('fee $1,500.50 (one thousand five hundred dollars and fifty cents)', $document->blocks[4]['text']);
        $this->assertSame('Synthetic Premium Lease: Synthetic Track Title', $document->title);
        $this->assertSame(strtotime('2026-11-07T00:00:00Z'), $document->timestamp);

        $exclusive = self::document('exclusiveBuyer', 'exclusive', self::TEMPLATE."\n\nPerforming as {CUSTOMER_ALIAS}.");
        $this->assertSame('Performing as MC Example.', $exclusive->blocks[5]['text']);
        $this->assertStringContainsString('Performing as MC Example.', $this->pdfText($this->render($exclusive)->pdfBytes));
    }

    public static function doubledTemplates(): array
    {
        return [
            'dollar before price' => ['Fee ${PRODUCT_PRICE}.', 'PRODUCT_PRICE'],
            'dollar and space before price' => ['Fee $ {PRODUCT_PRICE}.', 'PRODUCT_PRICE'],
            'percent after share' => ['Share {PUBLISHING_RIGHTS}%.', 'PUBLISHING_RIGHTS'],
            'percent word after share' => ['Share {PUBLISHING_RIGHTS} percent.', 'PUBLISHING_RIGHTS'],
            'unit word after price words' => ['Fee {PRODUCT_PRICE_WORD} dollars.', 'PRODUCT_PRICE_WORD'],
            'dollar word after price' => ['Fee {PRODUCT_PRICE} dollars.', 'PRODUCT_PRICE'],
            'US Dollars after price' => ['Fee {PRODUCT_PRICE} US Dollars.', 'PRODUCT_PRICE'],
            'euro sign before price' => ['Fee €{PRODUCT_PRICE}.', 'PRODUCT_PRICE'],
            'currency code before price' => ['Fee USD {PRODUCT_PRICE}.', 'PRODUCT_PRICE'],
            'dollar before price words' => ['Fee ${PRODUCT_PRICE_WORD}.', 'PRODUCT_PRICE_WORD'],
            'pct after share' => ['Share {PUBLISHING_RIGHTS} pct.', 'PUBLISHING_RIGHTS'],
            'per-cent after share' => ['Share {PUBLISHING_RIGHTS} per-cent.', 'PUBLISHING_RIGHTS'],
            'per cent after share' => ['Share {PUBLISHING_RIGHTS} per cent.', 'PUBLISHING_RIGHTS'],
        ];
    }

    #[DataProvider('doubledTemplates')]
    public function test_a_symbol_next_to_a_placeholder_that_already_prints_it_refuses(string $template, string $placeholder): void
    {
        $this->refusesBinding('doubled_symbol', $placeholder, fn () => self::document('individual', 'non-exclusive', "SYNTHETIC\n\n".$template));
    }

    public function test_doubled_symbols_are_also_caught_on_the_substituted_boundaries(): void
    {
        $values = ['PRODUCT_PRICE' => '$1.00', 'PUBLISHING_RIGHTS' => '5.00%', 'PRODUCT_TITLE' => '$Track%'];
        $this->assertSame([['placeholder' => 'PRODUCT_TITLE', 'symbol' => '$', 'line' => 1], ['placeholder' => 'PRODUCT_TITLE', 'symbol' => '%', 'line' => 1]],
            ContractTemplateCensus::doubledAtBoundaries("Title \$ {PRODUCT_TITLE} %\nand {PRODUCT_PRICE}.", $values));
        $this->assertSame([], ContractTemplateCensus::doubledAtBoundaries('Fee {PRODUCT_PRICE} at {PUBLISHING_RIGHTS} share; {PRODUCT_TITLE}.', $values));
        // A buyer value that merely contains a symbol is not doubled; only a repeated symbol at the boundary is.
        $this->assertSame([], ContractTemplateCensus::doubledAtBoundaries('Title: {PRODUCT_TITLE}!', $values));
        // Accepted forms: the symbol comes only from the value, and a currency code may follow the figure.
        $this->assertSame('SYNTHETIC', self::document('individual', 'non-exclusive', "SYNTHETIC\n\nFee {PRODUCT_PRICE} USD at {PUBLISHING_RIGHTS} share.")->blocks[0]['text']);
    }

    public function test_blocks_are_classified_on_the_template_so_buyer_text_cannot_make_a_heading(): void
    {
        $declaration = array_replace(self::fixture('individual'), ['legal_name' => 'ZOË EXAMPLE BUYER']);
        $values = self::fixture('bind', self::fixture('taxInput', $declaration, self::fixture('license', self::fixture('terms'))));
        $document = VaseyLicenseDocument::build("SYNTHETIC TITLE\n\n{CUSTOMER_FULLNAME}\n\nSECTION {PRODUCT_TITLE}\n\nBody text.",
            $values, $declaration, ContractGlyphCoverage::vaseyLicenseFaces());

        $this->assertSame([['type' => 'title', 'text' => 'SYNTHETIC TITLE'], ['type' => 'paragraph', 'text' => 'ZOË EXAMPLE BUYER'],
            ['type' => 'heading', 'text' => 'SECTION Synthetic Track Title'], ['type' => 'paragraph', 'text' => 'Body text.']], $document->blocks);
    }

    public function test_the_stand_in_signature_factory_is_refused_outside_testing(): void
    {
        $path = $this->signature()->path;
        $this->app['env'] = 'production';
        try {
            $this->refusesIssuance('signature_unavailable', fn () => VaseyLicenseSignature::forTesting($path, hash_file('sha256', $path)));
        } finally {
            $this->app['env'] = 'testing';
        }
        $this->assertFalse((new \ReflectionMethod(VaseyLicenseSignature::class, '__construct'))->isPublic());
    }

    public function test_the_implementation_pin_is_the_derived_closure_of_the_render_graph(): void
    {
        $pinned = array_keys(VaseyLicenseProfile::current()['assets']['implementation']);
        $this->assertSame(VaseyLicenseImplementation::closure(base_path()), $pinned);
        foreach (['ContractNumberWords', 'TypedLicenseTerms', 'TieredLicenseTerms', 'LicenseTerritories', 'CanonicalJson',
            'VaseyLicenseProfileDefinition', 'ContractIdentityDeclarationV2', 'ContractGlyphCoverage', 'VaseyLicenseLayout'] as $class) {
            $this->assertCount(1, array_filter($pinned, static fn (string $path): bool => str_ends_with($path, '/'.$class.'.php')), $class);
        }
        // The anchor holds the manifest hash and cannot pin itself; its definition is pinned instead.
        $this->assertNotContains(VaseyLicenseImplementation::ANCHOR, $pinned);
    }

    public function test_the_binders_map_and_the_declaration_must_agree(): void
    {
        [$values, $declaration] = self::bound('business');
        $coverage = ContractGlyphCoverage::vaseyLicenseFaces();
        $this->refusesBinding('unsupported_input', null, fn () => VaseyLicenseDocument::build(self::TEMPLATE, array_reverse($values, true), $declaration, $coverage));
        $this->refusesBinding('unsupported_input', null, fn () => VaseyLicenseDocument::build(self::TEMPLATE, [...$values, 'CUSTOMER_FULLNAME' => 'Other Records Ltd'], $declaration, $coverage));
        $this->refusesBinding('unsupported_input', null, fn () => VaseyLicenseDocument::build(self::TEMPLATE, [...$values, 'CUSTOMER_ADDRESS' => 'Elsewhere, United Kingdom'], $declaration, $coverage));
        $this->refusesBinding('contract_identity_invalid', null, fn () => VaseyLicenseDocument::build(self::TEMPLATE, $values, [...$declaration, 'representative_name' => null], $coverage));
        $this->refusesBinding('unsupported_input', 'CONTRACT_DATE', fn () => VaseyLicenseDocument::build(self::TEMPLATE, [...$values, 'CONTRACT_DATE' => 'Nov 7, 2026'], $declaration, $coverage));
        $this->refusesBinding('malformed_template', null, fn () => VaseyLicenseDocument::build("SYNTHETIC\r\n\r\nText", $values, $declaration, $coverage));
        $this->refusesBinding('malformed_template', null, fn () => VaseyLicenseDocument::build("SYNTHETIC\tText", $values, $declaration, $coverage));
        $this->refusesBinding('unknown_placeholder', 'CUSTOMER_EMAIL', fn () => VaseyLicenseDocument::build('SYNTHETIC {CUSTOMER_EMAIL}', $values, $declaration, $coverage));
        // A non-exclusive map never carries the alias; an exclusive declaration needs it.
        $this->refusesBinding('missing_value', 'CUSTOMER_ALIAS', fn () => VaseyLicenseDocument::build('SYNTHETIC {CUSTOMER_ALIAS}', $values, $declaration, $coverage));
    }

    public function test_buyer_fields_and_values_must_be_printable_in_the_profiles_own_faces(): void
    {
        // Ł (Latin Extended-A) and Cyrillic are drawn by DejaVu, which checkout uses today, but not by the brand faces.
        foreach (['Łukasz Example', 'Иван Example'] as $name) {
            $declaration = array_replace(self::fixture('individual'), ['legal_name' => $name]);
            $input = self::fixture('taxInput', $declaration, self::fixture('license', self::fixture('terms')));
            $values = self::fixture('bind', $input);
            $this->assertSame($name, $values['CUSTOMER_FULLNAME']);
            $this->refusesBinding('unsupported_characters', 'CUSTOMER_FULLNAME',
                fn () => VaseyLicenseDocument::build(self::TEMPLATE, $values, $declaration, ContractGlyphCoverage::vaseyLicenseFaces()));
        }
        // A representative name outside the faces refuses through the declaration's own re-verification.
        [$values, $declaration] = self::bound('business');
        $this->refusesBinding('unsupported_characters', null, fn () => VaseyLicenseDocument::build(self::TEMPLATE, $values,
            [...$declaration, 'representative_name' => 'Ada Łxample'], ContractGlyphCoverage::vaseyLicenseFaces()));
    }

    public function test_a_changed_missing_or_invalid_signature_refuses_issuance(): void
    {
        $document = self::document();
        $signature = $this->signature();
        $this->render($document, $signature);

        self::flipLastByte($signature->path);
        $this->refusesIssuance('signature_changed', fn () => $this->render($document, $signature));

        unlink($signature->path);
        $this->refusesIssuance('signature_unavailable', fn () => $this->render($document, $signature));

        file_put_contents($this->scratch.'/not-a-png.png', 'GIF89a synthetic');
        $notPng = VaseyLicenseSignature::forTesting($this->scratch.'/not-a-png.png', hash_file('sha256', $this->scratch.'/not-a-png.png'));
        $this->refusesIssuance('signature_invalid', fn () => $this->render($document, $notPng));

        $other = $this->signature('other.png');
        symlink($other->path, $this->scratch.'/linked.png');
        $this->refusesIssuance('signature_unavailable', fn () => $this->render($document, VaseyLicenseSignature::forTesting($this->scratch.'/linked.png', $other->sha256)));
        $this->refusesIssuance('signature_unavailable', fn () => VaseyLicenseSignature::forTesting('relative.png', $other->sha256));
        $this->refusesIssuance('signature_unavailable', fn () => VaseyLicenseSignature::forTesting($other->path, 'not-a-hash'));
    }

    public function test_a_different_signature_pin_is_a_different_recorded_profile_identity(): void
    {
        $document = self::document();
        $first = $this->render($document, $this->signature('first.png'));
        $second = $this->render($document, $this->signature('second.png', 300, 90, 255));
        $this->assertNotSame($first->profileHash, $second->profileHash);
        $this->assertNotSame($first->sha256, $second->sha256);
        $this->assertSame($first->textDigest, $second->textDigest);
    }

    public function test_the_production_signature_comes_only_from_the_private_disk_against_its_configured_pin(): void
    {
        Storage::fake('local');
        $this->refusesIssuance('signature_unavailable', fn () => VaseyLicenseSignature::fromConfig());

        $synthetic = $this->signature();
        Storage::disk('local')->put('contracts/signature.png', file_get_contents($synthetic->path));
        config(['contracts.vasey_license_v1.signature_path' => 'contracts/signature.png', 'contracts.vasey_license_v1.signature_sha256' => $synthetic->sha256]);
        $configured = VaseyLicenseSignature::fromConfig();
        $this->assertSame(realpath(Storage::disk('local')->path('contracts/signature.png')), $configured->path);
        $this->assertSame($synthetic->sha256, $configured->sha256);
        $this->assertSame($this->render(self::document(), $synthetic)->sha256, $this->render(self::document(), $configured)->sha256);

        foreach (['/etc/signature.png', '../signature.png', 'contracts/../signature.png', '.hidden/signature.png', 'contracts/signature.jpg', 'contracts/missing.png'] as $path) {
            config(['contracts.vasey_license_v1.signature_path' => $path]);
            $this->refusesIssuance('signature_unavailable', fn () => VaseyLicenseSignature::fromConfig());
        }
        symlink($synthetic->path, Storage::disk('local')->path('contracts/linked.png'));
        config(['contracts.vasey_license_v1.signature_path' => 'contracts/linked.png']);
        $this->refusesIssuance('signature_unavailable', fn () => VaseyLicenseSignature::fromConfig());

        config(['contracts.vasey_license_v1.signature_path' => 'contracts/signature.png', 'contracts.vasey_license_v1.signature_sha256' => strtoupper($synthetic->sha256)]);
        $this->refusesIssuance('signature_unavailable', fn () => VaseyLicenseSignature::fromConfig());
    }

    public static function pinnedAssets(): array
    {
        $manifest = json_decode(file_get_contents(dirname(__DIR__, 2).'/'.VaseyLicenseProfile::ASSETS_DIRECTORY.'/profile-assets.json'), true, 32, JSON_THROW_ON_ERROR);

        return array_combine(array_keys($manifest['assets']), array_map(static fn (string $path): array => [$path], array_keys($manifest['assets'])));
    }

    #[DataProvider('pinnedAssets')]
    public function test_changing_any_pinned_font_logo_or_source_by_one_byte_refuses_issuance(string $asset): void
    {
        $root = $this->copiedRoot();
        $signature = $this->signature();
        $this->render(self::document(), $signature, $root);

        self::flipLastByte($root.'/'.VaseyLicenseProfile::ASSETS_DIRECTORY.'/'.$asset);
        $this->refusesIssuance('profile_changed', fn () => $this->render(self::document(), $signature, $root));
        $this->refusesIssuance('profile_changed', fn () => (new VaseyLicensePdfRenderer($root))->render(self::document(), VaseyLicenseProfile::current(), $signature));
    }

    public function test_a_changed_manifest_or_implementation_file_refuses_issuance(): void
    {
        $signature = $this->signature();
        $root = $this->copiedRoot();
        $manifest = $root.'/'.VaseyLicenseProfile::ASSETS_DIRECTORY.'/profile-assets.json';
        file_put_contents($manifest, str_replace('"schema_version": 1', '"schema_version":  1', file_get_contents($manifest)));
        $this->refusesIssuance('profile_changed', fn () => VaseyLicenseProfile::current($root));
        $this->refusesIssuance('profile_changed', fn () => (new VaseyLicensePdfRenderer($root))->render(self::document(), VaseyLicenseProfile::current(), $signature));

        $root = $this->copiedRoot();
        file_put_contents($root.'/app/Domain/Contracts/Production/VaseyLicenseLayout.php', "\n", FILE_APPEND);
        $this->refusesIssuance('profile_changed', fn () => $this->render(self::document(), $signature, $root));

        // A retained profile that differs from the trusted one refuses, as does another version's profile.
        $profile = VaseyLicenseProfile::current();
        $profile['colors']['band'] = '#000000';
        $this->refusesIssuance('profile_changed', fn () => (new VaseyLicensePdfRenderer)->render(self::document(), $profile, $signature));
        $this->refusesIssuance('profile_changed', fn () => (new VaseyLicensePdfRenderer)->render(self::document(), ContractRenderProfileRegistry::metadata('test-buyer-pdf-v2'), $signature));
    }

    public function test_the_profile_is_registered_and_pinned_but_never_selected_for_issuance(): void
    {
        $profile = VaseyLicenseProfile::current();
        $this->assertSame(VaseyLicenseProfile::MANIFEST_SHA256, hash_file('sha256', base_path(VaseyLicenseProfile::ASSETS_DIRECTORY.'/profile-assets.json')));
        $this->assertSame(['vasey-license-pdf-v1', false, 'not_selected'], [$profile['version'], $profile['test_only'], $profile['issuance']]);
        $this->assertFalse($profile['font_subset']);
        $this->assertFalse($profile['compression']);
        $this->assertSame(['in_manifest' => false, 'source' => 'private_storage', 'pin' => 'config:contracts.vasey_license_v1.signature_sha256'], $profile['assets']['signature']);

        // The test issuance registry, its current version and the versioned dispatcher do not know this profile.
        $this->assertSame('test-buyer-pdf-v2', ContractRenderProfileRegistry::CURRENT_VERSION);
        $this->refusesIssuance('profile_changed', fn () => ContractRenderProfileRegistry::metadata('vasey-license-pdf-v1'));
        $this->refusesIssuance('profile_changed', fn () => (new VersionedContractRenderer)->render([], $profile));
        foreach (['app/Domain/Grants', 'app/Domain/Commerce', 'app/Domain/Contracts', 'app/Http', 'app/Jobs', 'app/Console', 'app/Providers', 'routes', 'config'] as $directory) {
            foreach (File::allFiles(base_path($directory)) as $file) {
                if (str_contains($file->getPathname(), '/app/Domain/Contracts/Production/')) {
                    continue;
                }
                foreach (['VaseyLicensePdfRenderer', 'VaseyLicenseDocument', 'VaseyLicenseSignature', 'VaseyLicenseLayout'] as $class) {
                    $this->assertStringNotContainsString($class, $file->getContents(), $file->getPathname());
                }
            }
        }
    }

    public function test_the_logo_and_font_sources_are_the_unmodified_brand_files(): void
    {
        $profile = VaseyLicenseProfile::current();
        $this->assertSame(hash_file('sha256', public_path('brand/vasey-audio-logo.png')), $profile['assets']['assets'][VaseyLicenseProfile::LOGO]['sha256']);
        $this->assertSame($profile['assets']['logo']['sha256'], $profile['assets']['assets'][VaseyLicenseProfile::LOGO]['sha256']);
        $brand = json_decode(file_get_contents(public_path('brand/fonts/source-manifest.json')), true, 16, JSON_THROW_ON_ERROR);
        $copied = 0;
        foreach ($brand['files'] as $file) {
            foreach (['font', 'license'] as $kind) {
                $asset = 'fonts/source/'.basename($file[$kind]);
                if (isset($profile['assets']['assets'][$asset])) {
                    $this->assertSame($file[$kind.'_sha256'], $profile['assets']['assets'][$asset]['sha256'], $asset);
                    $copied++;
                }
            }
        }
        $this->assertSame(6, $copied);
        foreach ($profile['assets']['assets'] as $path => $identity) {
            $this->assertDoesNotMatchRegularExpression('/signature/i', $path);
        }
    }

    public function test_the_profile_directory_holds_only_its_pinned_assets_and_no_signature(): void
    {
        $files = array_map(static fn ($file): string => $file->getRelativePathname(), File::allFiles(base_path(VaseyLicenseProfile::ASSETS_DIRECTORY)));
        $expected = [...array_keys(VaseyLicenseProfile::current()['assets']['assets']), 'PROVENANCE.md', 'profile-assets.json'];
        sort($files);
        sort($expected);
        $this->assertSame($expected, $files);
    }

    public function test_the_binder_closed_set_is_what_the_renderer_accepts(): void
    {
        [$values] = self::bound('exclusiveBuyer', 'exclusive');
        $this->assertSame(ContractVariableBinder::PLACEHOLDERS, array_keys($values));
    }
}
