<?php

namespace Tests\Unit;

use App\Domain\Commerce\ContractIdentity\ContractPrintableSet;
use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Contracts\Production\ContractBindingException;
use App\Domain\Contracts\Production\SoundKitLicenseBinder;
use App\Domain\Contracts\Production\SoundKitLicenseDocument;
use App\Domain\Contracts\Production\VaseyKitLicenseImplementation;
use App\Domain\Contracts\Production\VaseyKitLicensePdfRenderer;
use App\Domain\Contracts\Production\VaseyKitLicenseProfile;
use App\Domain\Contracts\Production\VaseyLicenseSignature;
use App\Domain\Contracts\Production\VaseyLicenseV2Profile;
use App\Domain\Contracts\RenderedContract;
use App\Domain\Rights\SoundKitLicenseKind;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * BL-66: the sound-kit license kind, its binder and the `vasey-kit-license-pdf-v1` renderer. SYNTHETIC, NONBINDING fixtures
 * only: the license text below is written for this test and carries none of Sean's wording; the kit, licensee, fee, order
 * reference and licensor are invented, and the signature is a generated stand-in. Nothing here is a real name, price or image.
 *
 * Re-pin after a tc-lib update or a change to a pinned file: scripts/repin-vasey-kit-license-v1.sh (rebuilds the manifest and
 * rewrites the golden hash in tests/Fixtures/vasey-kit-license/golden.json).
 */
class VaseyKitLicensePdfRendererTest extends TestCase
{
    private const TEMPLATE = "[SYNTHETIC] TEST KIT LICENSE\n\nNONBINDING FIXTURE: {KIT_NAME}\n\n"
        .'This synthetic agreement is made on {PURCHASE_DATE} between {LICENSOR_NAME} (the licensor) and {LICENSEE_NAME} (the licensee), '
        ."order {ORDER_REFERENCE}.\nA kept line break.\n\nSYNTHETIC GRANT\n\n"
        .'The licensee paid {LICENSE_FEE} for a non-binding example grant of {KIT_NAME}. No real rights are granted.';

    private const PURCHASE = ['kit_name' => 'Synthetic Test Kit Vol. 1', 'purchased_at' => '2026-11-07T23:59:58Z', 'fee_minor' => 1234,
        'currency' => 'USD', 'licensee_name' => 'Ada Synthetic', 'order_reference' => 'SYN-ORDER-0001'];

    private const LICENSOR = 'Synthetic Licensor';

    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scratch = sys_get_temp_dir().'/vasey-kit-license-test-'.bin2hex(random_bytes(6));
        mkdir($this->scratch, 0700, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->scratch);
        parent::tearDown();
    }

    /** @return array<string, string> */
    private static function bound(array $replace = [], string $licensor = self::LICENSOR): array
    {
        return SoundKitLicenseBinder::bind(array_replace(self::PURCHASE, $replace), $licensor, (new VaseyKitLicensePdfRenderer)->coverage());
    }

    private static function document(string $template = self::TEMPLATE, array $replace = []): SoundKitLicenseDocument
    {
        return (new VaseyKitLicensePdfRenderer)->document($template, self::bound($replace));
    }

    /** A generated stand-in signature (a zig-zag stroke, plain-PHP PNG with stored deflate blocks). Never a real signature. */
    private function signature(string $name = 'signature.png', int $width = 600, int $height = 180): VaseyLicenseSignature
    {
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
        file_put_contents($this->scratch.'/'.$name, $png);

        return VaseyLicenseSignature::forTesting($this->scratch.'/'.$name, hash('sha256', $png));
    }

    private function render(SoundKitLicenseDocument $document, ?VaseyLicenseSignature $signature = null, ?string $root = null): RenderedContract
    {
        return (new VaseyKitLicensePdfRenderer($root))->render($document, VaseyKitLicenseProfile::current($root), $signature ?? $this->signature());
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

    private function refusesIssuance(string $reason, callable $call): void
    {
        try {
            $call();
            $this->fail('Expected refusal '.$reason);
        } catch (ContractIssuanceException $exception) {
            $this->assertSame($reason, $exception->reason);
        }
    }

    private function pdfText(string $pdf): string
    {
        $path = $this->scratch.'/extract-'.hash('sha256', $pdf).'.pdf';
        file_put_contents($path, $pdf);
        $extract = new Process(['pdftotext', '-enc', 'UTF-8', $path, '-'], timeout: 30);
        $extract->mustRun();

        return $extract->getOutput();
    }

    /** A copy of everything the kit and v2 profiles pin, at the same relative paths under a temporary project root. */
    private function copiedRoot(): string
    {
        $root = $this->scratch.'/root';
        foreach ([VaseyKitLicenseProfile::ASSETS_DIRECTORY, VaseyLicenseV2Profile::ASSETS_DIRECTORY] as $directory) {
            File::copyDirectory(base_path($directory), $root.'/'.$directory);
        }
        foreach ([...array_keys(VaseyKitLicenseProfile::current()['assets']['implementation']), ...array_keys(VaseyLicenseV2Profile::current()['assets']['implementation'])] as $path) {
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

    public function test_the_profile_is_pinned_shares_the_v2_files_by_hash_and_is_never_selected_for_issuance(): void
    {
        $profile = VaseyKitLicenseProfile::current();
        $v2 = VaseyLicenseV2Profile::current();
        $this->assertSame(VaseyKitLicenseProfile::MANIFEST_SHA256, hash_file('sha256', base_path(VaseyKitLicenseProfile::ASSETS_DIRECTORY.'/profile-assets.json')));
        $this->assertSame(['vasey-kit-license-pdf-v1', false, 'not_selected', 'sound-kit-license'],
            [$profile['version'], $profile['test_only'], $profile['issuance'], $profile['purpose']]);
        // The visual system is the v2 profile's: same faces, geometry, colors, sizes and limits, and the same signature pin.
        foreach (['page', 'faces', 'sizes_pt', 'colors', 'limits', 'signature', 'allowed_scripts', 'font_subset', 'compression'] as $key) {
            $this->assertSame($v2[$key], $profile[$key], $key);
        }
        $this->assertSame($v2['assets']['assets'], $profile['assets']['assets']);
        $this->assertSame($v2['assets']['packages'], $profile['assets']['packages']);
        $this->assertSame(['vasey-license-pdf-v1', 'vasey-license-pdf-v2'], [VaseyLicenseV2Profile::current()['supersedes'], $v2['version']]);
        VaseyKitLicenseProfile::verifyRuntime($profile);

        // The pin is the derived closure, and the profile directory holds a manifest and its provenance, never a font or signature.
        $this->assertSame(VaseyKitLicenseImplementation::closure(base_path()), array_keys($profile['assets']['implementation']));
        $this->assertSame(['PROVENANCE.md', 'profile-assets.json'], array_values(array_diff(scandir(base_path(VaseyKitLicenseProfile::ASSETS_DIRECTORY)), ['.', '..'])));
        // The kit profile pins no license model, and no issuer, dispatch or policy names it.
        $this->assertSame([], array_values(array_filter(array_keys($profile['assets']['implementation']),
            static fn (string $path): bool => str_contains($path, 'Models/') || str_contains($path, 'Grants/'))));
        $named = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app'), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php' && ! str_contains($file->getFilename(), 'KitLicense') && ! str_contains($file->getFilename(), 'SoundKitLicense')
                && preg_match('/VaseyKitLicense|vasey-kit-license/', (string) file_get_contents($file->getPathname())) === 1) {
                $named[] = substr($file->getPathname(), strlen(base_path()) + 1);
            }
        }
        $this->assertSame([], $named);
    }

    public function test_the_synthetic_license_renders_in_the_shared_visual_system_with_every_value_bound_and_matches_the_golden_hash(): void
    {
        $signature = $this->signature();
        $first = $this->render(self::document(), $signature);
        $second = $this->render(self::document(), $signature);

        $this->assertSame($first->pdfBytes, $second->pdfBytes);
        $this->assertSame(hash('sha256', $first->pdfBytes), $first->sha256);
        $this->assertStringStartsWith('%PDF-', $first->pdfBytes);
        $this->assertSame(1, $first->pageCount);
        // No clock reaches the document: its dates are the purchase date at UTC midnight.
        $this->assertStringContainsString('/CreationDate (D:20261107000000Z)', $first->pdfBytes);
        $this->assertStringContainsString('/ModDate (D:20261107000000Z)', $first->pdfBytes);
        // Same faces and the unmodified lockup plus the signature image as the generated license contracts.
        foreach (['BebasNeue-Regular', 'RedditSans-Regular', 'RedditSans-Bold', 'JetBrainsMono-Regular'] as $face) {
            $this->assertStringContainsString('/BaseFont /'.$face, $first->pdfBytes);
        }
        // The lockup and the signature, each with its alpha mask.
        $this->assertSame(4, preg_match_all('#/Subtype /Image#', $first->pdfBytes));

        // Line wrapping is layout: compare on single-spaced text.
        $text = preg_replace('/\s+/', ' ', $this->pdfText($first->pdfBytes));
        foreach (['[SYNTHETIC] TEST KIT LICENSE', 'NONBINDING FIXTURE: Synthetic Test Kit Vol. 1', 'November 7, 2026', 'Synthetic Licensor (the licensor)',
            'Ada Synthetic (the licensee)', 'SYN-ORDER-0001', '$12.34', 'SYNTHETIC GRANT'] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }
        // The shared acceptance block and footer: both labels, the licensee, the licensor and the date under both.
        foreach (['PRODUCER / LICENSOR', 'LICENSEE'] as $label) {
            $this->assertStringContainsString($label, $text);
        }
        $this->assertSame(2, substr_count($text, 'Date: November 7, 2026'));
        $this->assertStringNotContainsString('Accepted for', $text);
        $this->assertStringContainsString('vasey-kit-license-pdf-v1 urn:sha256:', $text);
        $this->assertStringNotContainsString('{', $text);

        $effective = CanonicalJson::hash(['profile_sha256' => VaseyKitLicenseProfile::hash(VaseyKitLicenseProfile::current()), 'signature_sha256' => $signature->sha256]);
        $this->assertSame($effective, $first->profileHash);
        $this->assertStringContainsString('vasey-kit-license-pdf-v1,'.$effective, $first->pdfBytes);
        $this->assertStringNotContainsString('vasey-license-pdf-v', $first->pdfBytes);

        // Golden: VASEY_KIT_REPIN=1 rewrites it (scripts/repin-vasey-kit-license-v1.sh does so after rebuilding the manifest).
        $golden = base_path('tests/Fixtures/vasey-kit-license/golden.json');
        if (getenv('VASEY_KIT_REPIN') === '1') {
            file_put_contents($golden, json_encode(['golden_sha256' => $first->sha256], JSON_UNESCAPED_SLASHES)."\n");
        }
        $this->assertSame(json_decode((string) file_get_contents($golden), true)['golden_sha256'], $first->sha256,
            'Golden render changed. Re-pin with scripts/repin-vasey-kit-license-v1.sh after a reviewed renderer, tc-lib or pinned-file change.');
    }

    public function test_the_binder_formats_each_placeholder_from_integer_minor_units_and_an_explicit_currency(): void
    {
        $this->assertSame(['KIT_NAME' => 'Synthetic Test Kit Vol. 1', 'PURCHASE_DATE' => 'November 7, 2026', 'LICENSE_FEE' => '$12.34',
            'LICENSEE_NAME' => 'Ada Synthetic', 'ORDER_REFERENCE' => 'SYN-ORDER-0001', 'LICENSOR_NAME' => 'Synthetic Licensor'], self::bound());
        $this->assertSame(SoundKitLicenseKind::PLACEHOLDERS, array_keys(self::bound()));
        $this->assertSame('$0.05', self::bound(['fee_minor' => 5])['LICENSE_FEE']);
        $this->assertSame('$1,234,567.89', self::bound(['fee_minor' => 123456789])['LICENSE_FEE']);
        // A buyer value is data: braces cannot reach the text, so a name can never look like a placeholder.
        $this->refusesBinding('value_invalid', 'LICENSEE_NAME', fn () => self::bound(['licensee_name' => '{KIT_NAME}']));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string, 2: ?string}> */
    public static function bindingRefusals(): array
    {
        return [
            'float fee' => [['fee_minor' => 12.34], 'amount_invalid', 'LICENSE_FEE'],
            'string fee' => [['fee_minor' => '1234'], 'amount_invalid', 'LICENSE_FEE'],
            'negative fee' => [['fee_minor' => -1], 'amount_invalid', 'LICENSE_FEE'],
            'fee over the integer bound' => [['fee_minor' => 2147483648], 'amount_invalid', 'LICENSE_FEE'],
            'other currency' => [['currency' => 'EUR'], 'currency_unsupported', 'LICENSE_FEE'],
            'lowercase currency' => [['currency' => 'usd'], 'currency_unsupported', 'LICENSE_FEE'],
            'impossible date' => [['purchased_at' => '2026-02-30T00:00:00Z'], 'unsupported_input', 'PURCHASE_DATE'],
            'offset date' => [['purchased_at' => '2026-11-07T00:00:00+00:00'], 'unsupported_input', 'PURCHASE_DATE'],
            'date only' => [['purchased_at' => '2026-11-07'], 'unsupported_input', 'PURCHASE_DATE'],
            'empty kit name' => [['kit_name' => ''], 'value_invalid', 'KIT_NAME'],
            'padded kit name' => [['kit_name' => ' Kit'], 'value_invalid', 'KIT_NAME'],
            'control in kit name' => [['kit_name' => "Kit\nTwo"], 'value_invalid', 'KIT_NAME'],
            'brace in kit name' => [['kit_name' => 'Kit {X}'], 'value_invalid', 'KIT_NAME'],
            'long kit name' => [['kit_name' => str_repeat('K', 161)], 'value_invalid', 'KIT_NAME'],
            'unprintable licensee' => [['licensee_name' => 'Иван Петров'], 'unsupported_characters', 'LICENSEE_NAME'],
            'blank order reference' => [['order_reference' => ''], 'value_invalid', 'ORDER_REFERENCE'],
            'spaced order reference' => [['order_reference' => 'SYN ORDER'], 'value_invalid', 'ORDER_REFERENCE'],
        ];
    }

    #[DataProvider('bindingRefusals')]
    public function test_the_binder_refuses_bad_values_naming_the_placeholder(array $replace, string $reason, ?string $placeholder): void
    {
        $this->refusesBinding($reason, $placeholder, fn () => self::bound($replace));
    }

    public function test_the_binder_refuses_missing_or_extra_inputs_and_an_unusable_licensor(): void
    {
        $coverage = (new VaseyKitLicensePdfRenderer)->coverage();
        $missing = self::PURCHASE;
        unset($missing['order_reference']);
        $this->refusesBinding('unsupported_input', null, fn () => SoundKitLicenseBinder::bind($missing, self::LICENSOR, $coverage));
        $this->refusesBinding('unsupported_input', null, fn () => SoundKitLicenseBinder::bind(self::PURCHASE + ['discount' => 1], self::LICENSOR, $coverage));
        foreach (['', ' Licensor', "Li\tcensor", 'Li{c}ensor', 'Лицензиар'] as $licensor) {
            $this->assertContains($this->bindingReason(fn () => self::bound([], $licensor)), ['value_invalid', 'unsupported_characters']);
        }
        // The licensor comes from configuration, and a blank or missing name refuses; nothing is defaulted.
        config(['contracts.vasey_kit_license.licensor_name' => null]);
        $this->refusesBinding('value_invalid', 'LICENSOR_NAME', fn () => SoundKitLicenseBinder::licensorFromConfig());
        config(['contracts.vasey_kit_license.licensor_name' => 'Synthetic Licensor']);
        $this->assertSame('Synthetic Licensor', SoundKitLicenseBinder::licensorFromConfig());
        config(['contracts.vasey_kit_license.licensor_name' => '']);
        $this->refusesBinding('value_invalid', 'LICENSOR_NAME', fn () => self::bound([], SoundKitLicenseBinder::licensorFromConfig()));
    }

    private function bindingReason(callable $call): string
    {
        try {
            $call();
        } catch (ContractBindingException $exception) {
            return $exception->reason;
        }

        return 'none';
    }

    public function test_a_license_text_must_declare_exactly_the_kit_placeholders(): void
    {
        $this->assertSame([], SoundKitLicenseKind::templateProblems(self::TEMPLATE));
        $this->assertSame(['KIT_NAME', 'PURCHASE_DATE', 'LICENSOR_NAME', 'LICENSEE_NAME', 'ORDER_REFERENCE', 'LICENSE_FEE'], SoundKitLicenseKind::declared(self::TEMPLATE));

        $without = str_replace('{ORDER_REFERENCE}', 'the order', self::TEMPLATE);
        $this->assertSame(['missing_placeholder:ORDER_REFERENCE'], SoundKitLicenseKind::templateProblems($without));
        $this->assertSame(['unknown_placeholder:PRODUCT_TITLE'], SoundKitLicenseKind::templateProblems(self::TEMPLATE.' {PRODUCT_TITLE}'));
        // A beat placeholder is unknown to a kit, and a lowercase or spaced name is redacted rather than echoed.
        $this->assertSame(['unknown_placeholder:CONTRACT_DATE'], SoundKitLicenseKind::templateProblems(self::TEMPLATE.' {CONTRACT_DATE}'));
        $this->assertSame(['unknown_placeholder:redacted'], SoundKitLicenseKind::templateProblems(self::TEMPLATE.' {some legal words}'));
        $this->assertSame(['malformed_template'], SoundKitLicenseKind::templateProblems(self::TEMPLATE.' } stray'));
        $this->assertSame(['malformed_template'], SoundKitLicenseKind::templateProblems(self::TEMPLATE.' {{KIT_NAME}}'));

        // The document refuses the same texts (with the placeholder named where it is safe to name one).
        $this->refusesBinding('missing_value', 'ORDER_REFERENCE', fn () => self::document($without));
        $this->refusesBinding('unknown_placeholder', 'PRODUCT_TITLE', fn () => self::document(self::TEMPLATE.' {PRODUCT_TITLE}'));
        $this->refusesBinding('unknown_placeholder', null, fn () => self::document(self::TEMPLATE.' {some legal words}'));
        $this->refusesBinding('malformed_template', null, fn () => self::document(self::TEMPLATE.' {{KIT_NAME}}'));
        $this->refusesBinding('malformed_template', null, fn () => self::document(''));
        $this->refusesBinding('malformed_template', null, fn () => self::document("Title\tTab {KIT_NAME}"));
        // Direct builds also refuse a map that is not exactly the kit's six values.
        $coverage = (new VaseyKitLicensePdfRenderer)->coverage();
        $this->refusesBinding('unsupported_input', null, fn () => SoundKitLicenseDocument::build(self::TEMPLATE, array_slice(self::bound(), 0, 5, true), $coverage));
        $this->refusesBinding('unsupported_input', null, fn () => SoundKitLicenseDocument::build(self::TEMPLATE, array_reverse(self::bound(), true), $coverage));
        $this->refusesBinding('missing_value', 'KIT_NAME', fn () => SoundKitLicenseDocument::build(self::TEMPLATE, ['KIT_NAME' => ''] + self::bound(), $coverage));
    }

    public function test_the_fee_placeholder_refuses_a_repeated_currency_symbol_or_unit(): void
    {
        $this->refusesBinding('doubled_symbol', 'LICENSE_FEE', fn () => self::document(str_replace('paid {LICENSE_FEE}', 'paid ${LICENSE_FEE}', self::TEMPLATE)));
        $this->refusesBinding('doubled_symbol', 'LICENSE_FEE', fn () => self::document(str_replace('paid {LICENSE_FEE}', 'paid USD {LICENSE_FEE}', self::TEMPLATE)));
        $this->refusesBinding('doubled_symbol', 'LICENSE_FEE', fn () => self::document(str_replace('{LICENSE_FEE} for', '{LICENSE_FEE} dollars for', self::TEMPLATE)));
        // A currency code after the figure names its currency and is accepted, as for the beat contracts.
        $this->assertNotNull(self::document(str_replace('{LICENSE_FEE} for', '{LICENSE_FEE} USD for', self::TEMPLATE)));
    }

    public function test_a_purchase_value_cannot_become_a_heading_or_a_placeholder(): void
    {
        // Blocks are classified on the text before substitution: an all-caps kit name leaves a paragraph a paragraph.
        $document = self::document(str_replace('NONBINDING FIXTURE: {KIT_NAME}', 'Kit name: {KIT_NAME}', self::TEMPLATE), ['kit_name' => 'ALL CAPS KIT']);
        $this->assertSame(['title', 'paragraph', 'paragraph', 'heading', 'paragraph'], array_column($document->blocks, 'type'));
        $this->assertSame('Kit name: ALL CAPS KIT', $document->blocks[1]['text']);
    }

    public function test_the_signature_is_read_only_from_the_configured_private_path_and_its_pin(): void
    {
        Storage::fake('local');
        $png = file_get_contents($this->signature('probe.png')->path);
        Storage::disk('local')->put('contracts/kit-signature.png', $png);
        config(['contracts.vasey_license_v1.signature_path' => 'contracts/kit-signature.png', 'contracts.vasey_license_v1.signature_sha256' => hash('sha256', $png)]);

        $rendered = $this->render(self::document(), VaseyLicenseSignature::fromConfig());
        $this->assertSame(hash('sha256', $png), json_decode(json_encode(['s' => VaseyLicenseSignature::fromConfig()->sha256]), true)['s']);
        $this->assertStringContainsString('vasey-kit-license-pdf-v1,', $rendered->pdfBytes);
        // The signature pin is part of the recorded profile identity: another image is another identity.
        $this->assertNotSame($rendered->profileHash, $this->render(self::document(), $this->signature('other.png', 500, 150))->profileHash);

        // Changed bytes under the pin refuse; an unpinned, absolute, traversing or public path is never read.
        Storage::disk('local')->put('contracts/kit-signature.png', $png."\0");
        $this->refusesIssuance('signature_changed', fn () => $this->render(self::document(), VaseyLicenseSignature::fromConfig()));
        foreach (['/etc/passwd', '../outside.png', 'contracts/../kit-signature.png', '.hidden/kit-signature.png', 'contracts/missing.png', 'contracts/kit-signature.jpg', ''] as $path) {
            config(['contracts.vasey_license_v1.signature_path' => $path]);
            $this->refusesIssuance('signature_unavailable', fn () => VaseyLicenseSignature::fromConfig());
        }
        config(['contracts.vasey_license_v1.signature_path' => 'contracts/kit-signature.png', 'contracts.vasey_license_v1.signature_sha256' => null]);
        $this->refusesIssuance('signature_unavailable', fn () => VaseyLicenseSignature::fromConfig());
    }

    public function test_only_a_document_the_renderer_built_under_its_input_rule_renders(): void
    {
        $outside = SoundKitLicenseDocument::build(self::TEMPLATE, self::bound(), ContractPrintableSet::checkout());
        $this->refusesIssuance('unsupported_input', fn () => $this->render($outside));
        $this->refusesIssuance('profile_changed', fn () => (new VaseyKitLicensePdfRenderer)->render(self::document(), ['version' => 'vasey-license-pdf-v2'] + VaseyKitLicenseProfile::current(), $this->signature()));
        // Template wording a face cannot draw refuses at render, and the private font copy is removed.
        $before = glob(sys_get_temp_dir().'/vasey-kit-license-fonts-*') ?: [];
        $this->refusesIssuance('unsupported_input', fn () => $this->render(self::document("SYNTHETIC Иван\n\nBody {KIT_NAME} {PURCHASE_DATE} {LICENSOR_NAME} {LICENSEE_NAME} {ORDER_REFERENCE} {LICENSE_FEE}.")));
        $this->assertSame($before, glob(sys_get_temp_dir().'/vasey-kit-license-fonts-*') ?: []);
    }

    public function test_a_changed_manifest_pinned_font_or_implementation_file_refuses_and_the_original_is_unaffected(): void
    {
        $root = $this->copiedRoot();
        $signature = $this->signature();
        $this->assertSame($this->render(self::document(), $signature)->sha256, $this->render(self::document(), $signature, $root)->sha256);
        $index = 0;

        foreach ([VaseyKitLicenseProfile::ASSETS_DIRECTORY.'/profile-assets.json', VaseyLicenseV2Profile::ASSETS_DIRECTORY.'/fonts/generated/redditsans.z',
            VaseyLicenseV2Profile::ASSETS_DIRECTORY.'/images/vasey-audio-logo.png', 'app/Domain/Contracts/Production/SoundKitLicenseDocument.php',
            'app/Domain/Contracts/Production/VaseyKitLicensePdfRenderer.php', 'app/Domain/Contracts/Production/VaseyLicenseLayout.php'] as $relative) {
            $tampered = $this->copyOf($root, ++$index);
            self::flipLastByte($tampered.'/'.$relative);
            $this->refusesIssuance('profile_changed', fn () => $this->render(self::document(), $signature, $tampered));
        }
        // A planted link in place of a pinned asset refuses too.
        $linked = $this->copyOf($root, ++$index);
        $asset = $linked.'/'.VaseyLicenseV2Profile::ASSETS_DIRECTORY.'/fonts/generated/bebasneue.z';
        copy($asset, $linked.'/real.z');
        unlink($asset);
        symlink($linked.'/real.z', $asset);
        $this->refusesIssuance('profile_changed', fn () => $this->render(self::document(), $signature, $linked));
        $this->assertSame($this->render(self::document(), $signature)->sha256, $this->render(self::document(), $signature, $root)->sha256);
    }

    private function copyOf(string $root, int $index): string
    {
        File::copyDirectory($root, $this->scratch.'/copy-'.$index);

        return $this->scratch.'/copy-'.$index;
    }
}
