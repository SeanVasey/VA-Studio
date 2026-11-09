<?php

namespace Tests\Unit;

use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Contracts\Production\VaseyLicenseV2Profile;
use App\Domain\Grants\VaseyLicenseContractSource;
use App\Domain\Grants\VaseyLicenseIssuance;
use App\Support\CanonicalJson;
use Closure;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EconomicLicenseFixtures;
use Tests\Support\VaseyLicenseIssuanceFixtures;
use Tests\TestCase;

/**
 * Contract increment 5 wiring: the selection rule and the render dispatch of `VaseyLicenseIssuance`, on the binder test's
 * synthetic frozen inputs. SYNTHETIC, NONBINDING fixtures only.
 */
class VaseyLicenseIssuanceTest extends TestCase
{
    use VaseyLicenseIssuanceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
    }

    private static function fixture(string $method, mixed ...$arguments): mixed
    {
        return Closure::bind(static fn (): mixed => ContractVariableBinderTest::{$method}(...$arguments), null, ContractVariableBinderTest::class)();
    }

    /** A frozen render input of either family, for a synthetic individual buyer with the given declaration changes. */
    private static function input(string $family = 'tax', array $replace = [], array $terms = []): array
    {
        $declaration = array_replace(self::fixture('individual'), $replace);
        $license = self::fixture('license', $terms ?: self::fixture('terms'));

        return $family === 'tax' ? self::fixture('taxInput', $declaration, $license) : self::fixture('v1Input', $declaration, $license);
    }

    private function refuses(string $reason, callable $call): void
    {
        try {
            $call();
            $this->fail('Expected refusal '.$reason);
        } catch (ContractIssuanceException $exception) {
            $this->assertSame($reason, $exception->reason);
        }
    }

    public static function families(): array
    {
        return ['paid V1' => ['v1', 'paid-license-grant'], 'paid-tax V2' => ['tax', 'paid-tax-license-grant']];
    }

    #[DataProvider('families')]
    public function test_a_v2_declaration_with_the_gate_on_selects_and_renders_the_v2_license(string $family, string $purpose): void
    {
        $this->enableV2Issuance();
        $input = self::input($family, ['legal_name' => 'Łukasz Żółć']);
        [$profile, $branch] = VaseyLicenseIssuance::decide($input);

        $this->assertSame('selected', $branch);
        $this->assertTrue(VaseyLicenseIssuance::selected($profile));
        $this->assertSame([VaseyLicenseIssuance::DESCRIPTOR_SCHEMA, VaseyLicenseV2Profile::VERSION, $purpose, 'synthetic_rehearsal'],
            [$profile['schema_version'], $profile['version'], $profile['purpose'], $profile['provenance']]);
        $this->assertSame(VaseyLicenseV2Profile::current(), $profile['renderer_profile']);
        $this->assertSame(self::SYNTHETIC_TEMPLATE, $profile['contract']['template']);
        $effective = CanonicalJson::hash(['profile_sha256' => VaseyLicenseV2Profile::hash(VaseyLicenseV2Profile::current()),
            'signature_sha256' => config('contracts.vasey_license_v1.signature_sha256')]);
        $this->assertSame($effective, VaseyLicenseIssuance::artifactProfileHash($profile));

        // The recorded profile decides the render, even once the gate is off again.
        config(['contracts.vasey_license_v2_issuance' => false]);
        $rendered = (new VaseyLicenseIssuance)->render($input, $profile);
        $this->assertSame($effective, $rendered->profileHash);
        $this->assertSame(VaseyLicenseIssuance::expected($profile)['text_digest'], $rendered->textDigest);
        $this->assertStringContainsString('vasey-license-pdf-v2,'.$effective, $rendered->pdfBytes);
        $this->assertSame(2, substr_count($this->extractPdfText($rendered->pdfBytes), 'Łukasz Żółć'));
        $this->assertSame($rendered->pdfBytes, (new VaseyLicenseIssuance)->render($input, $profile)->pdfBytes);
        // A paid-grant descriptor JSON round trip (as stored in the encrypted origin) keeps it renderable.
        $stored = json_decode(CanonicalJson::encode($profile), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame($rendered->pdfBytes, (new VaseyLicenseIssuance)->render($input, $stored)->pdfBytes);
    }

    public function test_each_branch_that_keeps_the_existing_paid_profile(): void
    {
        $input = self::input();
        // The shipped literal is false.
        $this->assertFalse((require base_path('config/contracts.php'))['vasey_license_v2_issuance']);
        $this->assertSame([null, 'gate_off'], VaseyLicenseIssuance::decide($input));

        $this->enableV2Issuance();
        $this->assertSame('selected', VaseyLicenseIssuance::decide($input)[1]);
        // Only local and testing admit the gate.
        $this->app['env'] = 'production';
        try {
            $this->assertSame([null, 'gate_off'], VaseyLicenseIssuance::decide($input));
        } finally {
            $this->app['env'] = 'testing';
        }
        config(['contracts.vasey_license_v2_issuance' => 1]);
        $this->assertSame([null, 'gate_off'], VaseyLicenseIssuance::decide($input));
        config(['contracts.vasey_license_v2_issuance' => true]);

        // A legacy name-only declaration.
        $legacy = self::fixture('taxInput', ['legal_name' => 'Declared synthetic buyer', 'identity_meaning' => 'buyer_declared_legal_name'],
            self::fixture('license', self::fixture('terms')));
        $this->assertSame([null, 'name_only_declaration'], VaseyLicenseIssuance::decide($legacy));
        // A declaration frozen before #241 under the wider test-v2 set (Greek prints in DejaVu, not in the v2 faces):
        // finalization holds it, and it is never routed to v2.
        $this->assertSame([null, 'identity_held'], VaseyLicenseIssuance::decide(self::input('tax', ['legal_name' => 'Ελένη Example'])));

        // No v2 text for the license version (increment 1 not done), or no source bound at all.
        $this->bindSyntheticContractSource(null);
        $this->assertSame([null, 'no_contract_source'], VaseyLicenseIssuance::decide($input));
        $this->app->offsetUnset(VaseyLicenseContractSource::class);
        $this->assertSame([null, 'no_contract_source'], VaseyLicenseIssuance::decide($input));
        $this->bindSyntheticContractSource(self::SYNTHETIC_TEMPLATE.str_repeat("\n\nPadding paragraph.", 20000));
        $this->assertSame([null, 'template_too_large'], VaseyLicenseIssuance::decide($input));
        $this->bindSyntheticContractSource();

        // A missing or changed signature.
        $path = Storage::disk('local')->path(config('contracts.vasey_license_v1.signature_path'));
        $bytes = file_get_contents($path);
        file_put_contents($path, $bytes."\0");
        $this->assertSame([null, 'signature_unavailable'], VaseyLicenseIssuance::decide($input));
        unlink($path);
        $this->assertSame([null, 'signature_unavailable'], VaseyLicenseIssuance::decide($input));
        file_put_contents($path, $bytes);
        $this->assertSame('selected', VaseyLicenseIssuance::decide($input)[1]);

        // A line the v2 document refuses now: schema 4 terms, or a template placeholder the binder does not know.
        $this->assertSame([null, 'not_renderable'], VaseyLicenseIssuance::decide(self::input('tax', [], EconomicLicenseFixtures::terms())));
        $this->bindSyntheticContractSource(self::SYNTHETIC_TEMPLATE.' {UNKNOWN_PLACEHOLDER}');
        $this->assertSame([null, 'not_renderable'], VaseyLicenseIssuance::decide($input));
    }

    public function test_a_recorded_selection_refuses_rather_than_rendering_anything_else(): void
    {
        $this->enableV2Issuance();
        $input = self::input();
        [$profile] = VaseyLicenseIssuance::decide($input);
        $issuance = new VaseyLicenseIssuance;

        // Any edit of the frozen descriptor refuses.
        foreach ([['contract', 'template', self::SYNTHETIC_TEMPLATE.' Edited.'], ['contract', 'producer_alias', 'Another Alias'],
            ['expected', 'input_hash', str_repeat('0', 64)], ['expected', 'text_digest', str_repeat('0', 64)],
            ['signature_sha256', null, str_repeat('a', 64)], ['effective_hash', null, str_repeat('b', 64)]] as [$key, $inner, $value]) {
            $edited = $profile;
            $inner === null ? $edited[$key] = $value : $edited[$key][$inner] = $value;
            $this->refuses('profile_changed', fn () => $issuance->render($input, $edited));
        }
        $retained = $profile;
        $retained['renderer_profile']['colors']['band'] = '#000000';
        $this->refuses('profile_changed', fn () => $issuance->render($input, $retained));
        $this->refuses('profile_changed', fn () => VaseyLicenseIssuance::expected(['schema_version' => 1]));

        // Another buyer, another family or another purpose is not the frozen document.
        $this->refuses('profile_changed', fn () => $issuance->render(self::input('tax', ['legal_name' => 'Another Buyer']), $profile));
        $this->refuses('profile_changed', fn () => $issuance->render(self::input('v1'), $profile));
        $this->refuses('unsupported_input', fn () => $issuance->render([...$input, 'purpose' => 'paid-license-grant'], $profile));

        // A different, validly pinned signature at render time refuses, as does a pin its file does not match; the
        // recorded one renders again.
        $path = config('contracts.vasey_license_v1.signature_path');
        $pin = config('contracts.vasey_license_v1.signature_sha256');
        $this->assertNotSame($pin, $this->installSyntheticSignature('contracts/signature/another-signature.png', 255));
        $this->refuses('signature_changed', fn () => $issuance->render($input, $profile));
        config(['contracts.vasey_license_v1.signature_path' => $path, 'contracts.vasey_license_v1.signature_sha256' => str_repeat('c', 64)]);
        $this->refuses('signature_changed', fn () => $issuance->render($input, $profile));
        config(['contracts.vasey_license_v1.signature_sha256' => $pin]);
        $this->assertStringStartsWith('%PDF-', $issuance->render($input, $profile)->pdfBytes);
    }
}
