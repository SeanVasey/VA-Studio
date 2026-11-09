<?php

namespace Tests\Unit;

use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Contracts\Production\VaseyLicenseV2Profile;
use App\Domain\Grants\VaseyLicenseContractSource;
use App\Domain\Grants\VaseyLicenseContractTexts;
use App\Domain\Grants\VaseyLicenseIssuance;
use App\Support\CanonicalJson;
use Closure;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EconomicLicenseFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\VaseyLicenseIssuanceFixtures;
use Tests\TestCase;

/**
 * Contract increment 5 wiring: the selection rule and the render dispatch of `VaseyLicenseIssuance`, on the binder test's
 * synthetic frozen inputs. SYNTHETIC, NONBINDING fixtures only.
 */
class VaseyLicenseIssuanceTest extends TestCase
{
    // Renders run outside any transaction (ContractIo), so the stored contract texts need a migrated, non-transactional database.
    use FinalizationDatabaseMigrations;
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
        // Payload growth: the descriptor records the template and the renderer profile by hash only; their bytes are stored
        // once each by that hash, so a v2 origin stays about as large as a gate-off one.
        $this->assertEqualsCanonicalizing(['producer_alias', 'state_province_country', 'template_sha256'], array_keys($profile['contract']));
        $this->assertArrayNotHasKey('renderer_profile', $profile);
        $this->assertSame(hash('sha256', self::SYNTHETIC_TEMPLATE), $profile['contract']['template_sha256']);
        $this->assertSame(VaseyLicenseV2Profile::hash(VaseyLicenseV2Profile::current()), $profile['renderer_profile_sha256']);
        $this->assertSame(self::SYNTHETIC_TEMPLATE, VaseyLicenseContractTexts::resolve('template', $profile['contract']['template_sha256']));
        $this->assertSame(CanonicalJson::encode(VaseyLicenseV2Profile::current()), VaseyLicenseContractTexts::resolve('renderer_profile', $profile['renderer_profile_sha256']));
        $this->assertLessThan(2048, strlen(CanonicalJson::encode($profile)));
        // Every selection of the same template and profile shares one stored row of each.
        $this->assertSame('selected', VaseyLicenseIssuance::decide(self::input($family, ['legal_name' => 'Another Buyer']))[1]);
        $this->assertSame(['renderer_profile' => 1, 'template' => 1],
            DB::table(VaseyLicenseContractTexts::TABLE)->groupBy('kind')->orderBy('kind')->pluck(DB::raw('COUNT(*)'), 'kind')->map(fn ($n) => (int) $n)->all());
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
        // A source that throws, and an unexpected runtime error while building (terms that are not an array reach the
        // binder's typed signature), keep the existing profile instead of escaping into the locked finalization.
        $this->app->instance(VaseyLicenseContractSource::class, new class implements VaseyLicenseContractSource
        {
            public function contract(array $license): ?array
            {
                throw new \RuntimeException('synthetic contract source failure');
            }
        });
        $this->assertSame([null, 'no_contract_source'], VaseyLicenseIssuance::decide($input));
        $this->bindSyntheticContractSource();
        $broken = $input;
        $broken['source']['license']['structured_terms'] = 'not-an-array';
        $this->assertSame([null, 'not_renderable'], VaseyLicenseIssuance::decide($broken));
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

        // A row already stored under the template's address with other content (planted or damaged) keeps the existing
        // profile instead of recording a template that would not resolve; nothing is overwritten.
        foreach (['other bytes' => Crypt::encryptString(self::SYNTHETIC_TEMPLATE), 'undecryptable' => 'x'] as $case => $payload) {
            $fresh = self::SYNTHETIC_TEMPLATE."\n\nFresh paragraph ({$case}).";
            DB::table(VaseyLicenseContractTexts::TABLE)->insert(['kind' => 'template', 'sha256' => hash('sha256', $fresh), 'payload' => $payload, 'created_at' => '2026-11-09 00:00:00']);
            $this->bindSyntheticContractSource($fresh);
            $this->assertSame([null, 'contract_text_unavailable'], VaseyLicenseIssuance::decide($input), $case);
            $this->assertSame($payload, DB::table(VaseyLicenseContractTexts::TABLE)->where('sha256', hash('sha256', $fresh))->value('payload'));
        }
    }

    public function test_a_recorded_selection_refuses_rather_than_rendering_anything_else(): void
    {
        $this->enableV2Issuance();
        $input = self::input();
        [$profile] = VaseyLicenseIssuance::decide($input);
        $issuance = new VaseyLicenseIssuance;

        // Any edit of the frozen descriptor refuses.
        foreach ([['contract', 'template_sha256', str_repeat('e', 64)], ['contract', 'producer_alias', 'Another Alias'],
            ['expected', 'input_hash', str_repeat('0', 64)], ['expected', 'text_digest', str_repeat('0', 64)],
            ['signature_sha256', null, str_repeat('a', 64)], ['effective_hash', null, str_repeat('b', 64)]] as [$key, $inner, $value]) {
            $edited = $profile;
            $inner === null ? $edited[$key] = $value : $edited[$key][$inner] = $value;
            $this->refuses('profile_changed', fn () => $issuance->render($input, $edited));
        }
        // A stored renderer profile other than the pinned one refuses, even with every recorded hash consistent with it.
        $changed = VaseyLicenseV2Profile::current();
        $changed['colors']['band'] = '#000000';
        $this->assertTrue(VaseyLicenseContractTexts::retain('renderer_profile', CanonicalJson::encode($changed)));
        $rehashed = $profile;
        $rehashed['renderer_profile_sha256'] = CanonicalJson::hash($changed);
        $rehashed['effective_hash'] = CanonicalJson::hash(['profile_sha256' => $rehashed['renderer_profile_sha256'], 'signature_sha256' => $profile['signature_sha256']]);
        $this->refuses('profile_changed', fn () => $issuance->render($input, $rehashed));
        // So do stored profile bytes that are not canonical JSON, and another stored template whose hash verifies: it binds
        // to a document other than the frozen one.
        $padded = ' '.CanonicalJson::encode(VaseyLicenseV2Profile::current());
        $this->assertTrue(VaseyLicenseContractTexts::retain('renderer_profile', $padded));
        $nonCanonical = [...$profile, 'renderer_profile_sha256' => hash('sha256', $padded)];
        $nonCanonical['effective_hash'] = CanonicalJson::hash(['profile_sha256' => $nonCanonical['renderer_profile_sha256'], 'signature_sha256' => $profile['signature_sha256']]);
        $this->refuses('profile_changed', fn () => $issuance->render($input, $nonCanonical));
        $other = self::SYNTHETIC_TEMPLATE.' Edited.';
        $this->assertTrue(VaseyLicenseContractTexts::retain('template', $other));
        $this->refuses('profile_changed', fn () => $issuance->render($input, [...$profile, 'contract' => [...$profile['contract'], 'template_sha256' => hash('sha256', $other)]]));
        // A stored text changed under its address (the immutability trigger bypassed) refuses as a changed profile.
        $row = (array) DB::table(VaseyLicenseContractTexts::TABLE)->where('kind', 'template')->where('sha256', $profile['contract']['template_sha256'])->sole();
        $this->dropContractTextTriggers();
        foreach ([Crypt::encryptString($other), 'x'] as $tampered) {
            DB::table(VaseyLicenseContractTexts::TABLE)->where('id', $row['id'])->update(['payload' => $tampered]);
            $this->refuses('profile_changed', fn () => $issuance->render($input, $profile));
        }
        DB::table(VaseyLicenseContractTexts::TABLE)->where('id', $row['id'])->delete();
        $this->refuses('profile_changed', fn () => $issuance->render($input, $profile));
        DB::table(VaseyLicenseContractTexts::TABLE)->insert($row);
        $this->assertStringStartsWith('%PDF-', $issuance->render($input, $profile)->pdfBytes);
        // The #245 descriptor that carried its texts inline is still a v2 selection but no longer renders.
        $retired = [...$profile, 'schema_version' => VaseyLicenseIssuance::RETIRED_DESCRIPTOR_SCHEMA];
        $this->assertTrue(VaseyLicenseIssuance::selected($retired));
        $this->refuses('profile_changed', fn () => $issuance->render($input, $retired));
        // A #245 descriptor of its real shape (template and profile inline) still verifies its completed original by the
        // frozen digests, but never renders; one whose inline template no longer matches its hash does neither.
        $legacy = [...$retired, 'renderer_profile' => VaseyLicenseV2Profile::current(),
            'contract' => [...$profile['contract'], 'template' => self::SYNTHETIC_TEMPLATE]];
        $this->assertSame($profile['expected'], VaseyLicenseIssuance::expected($legacy));
        $this->assertSame($profile['effective_hash'], VaseyLicenseIssuance::artifactProfileHash($legacy));
        $this->refuses('profile_changed', fn () => $issuance->render($input, $legacy));
        $this->refuses('profile_changed', fn () => VaseyLicenseIssuance::expected($retired));
        foreach ([['contract', 'template', self::SYNTHETIC_TEMPLATE.' Edited.'], ['renderer_profile', 'version', 'another']] as [$key, $inner, $value]) {
            $edited = $legacy;
            $edited[$key][$inner] = $value;
            $this->refuses('profile_changed', fn () => VaseyLicenseIssuance::expected($edited));
            $this->refuses('profile_changed', fn () => VaseyLicenseIssuance::artifactProfileHash($edited));
        }
        // The current shape never carries its texts inline.
        $this->refuses('profile_changed', fn () => VaseyLicenseIssuance::expected([...$profile, 'renderer_profile' => VaseyLicenseV2Profile::current()]));
        $this->refuses('profile_changed', fn () => $issuance->render($input, [...$profile, 'renderer_profile' => VaseyLicenseV2Profile::current()]));
        $mismatched = $profile;
        $mismatched['renderer_profile_sha256'] = str_repeat('d', 64);
        $mismatched['effective_hash'] = CanonicalJson::hash(['profile_sha256' => $mismatched['renderer_profile_sha256'], 'signature_sha256' => $profile['signature_sha256']]);
        $this->refuses('profile_changed', fn () => $issuance->render($input, $mismatched));
        // Checked before the signature or anything else is read: with the signature gone it still refuses as a changed profile.
        $signaturePath = Storage::disk('local')->path(config('contracts.vasey_license_v1.signature_path'));
        $signatureBytes = file_get_contents($signaturePath);
        unlink($signaturePath);
        try {
            $this->refuses('profile_changed', fn () => $issuance->render($input, $mismatched));
            $this->refuses('signature_unavailable', fn () => $issuance->render($input, $profile));
        } finally {
            file_put_contents($signaturePath, $signatureBytes);
        }
        // The recorded purpose and provenance must be the input's.
        foreach (['purpose' => 'paid-license-grant', 'provenance' => 'verified_production'] as $key => $value) {
            $relabelled = $profile;
            $relabelled[$key] = $value;
            $this->refuses('profile_changed', fn () => $issuance->render($input, $relabelled));
        }
        // Defence in depth: outside local and testing a recorded selection never renders.
        $this->app['env'] = 'production';
        try {
            $this->refuses('profile_changed', fn () => $issuance->render($input, $profile));
        } finally {
            $this->app['env'] = 'testing';
        }
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

    /** The immutability triggers are dropped only inside this test's database, to plant a changed stored text. */
    private function dropContractTextTriggers(): void
    {
        foreach (['vasey_license_contract_texts_insert', 'vasey_license_contract_texts_immutable', 'vasey_license_contract_texts_retain'] as $trigger) {
            DB::unprepared('DROP TRIGGER '.$trigger);
        }
    }
}
