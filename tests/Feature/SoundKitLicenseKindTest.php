<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Offer;
use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\OfferDraftInput;
use App\Domain\Rights\CreateLicenseDraft;
use App\Domain\Rights\Models\LicenseTemplate;
use App\Domain\Rights\Models\LicenseVersion;
use App\Domain\Rights\SaveLicenseTemplate;
use App\Domain\Rights\SoundKitLicenseAttachment;
use App\Domain\Rights\SoundKitLicenseKind;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

/**
 * BL-66: the sound-kit license kind is a template type of its own. A kit license can never be attached to a beat offer, and
 * a beat license can never be attached to a kit. SYNTHETIC, NONBINDING records only; no license wording is involved.
 */
class SoundKitLicenseKindTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private function kitVersion(): LicenseVersion
    {
        $author = LicenseFixtures::admin();
        $this->actingAs($author);
        $template = app(SaveLicenseTemplate::class)->create(['name' => 'NONBINDING SYNTHETIC KIT LICENSE', 'slug' => 'synthetic-kit-'.Str::uuid(), 'type' => 'sound-kit'], $author);

        return app(CreateLicenseDraft::class)->handle($template, [
            'authored_source' => '[SYNTHETIC] TEST KIT LICENSE. Test evidence only.',
            'structured_terms' => ['schema_version' => 1, 'features' => ['Synthetic kit grant'], 'required_asset_roles' => ['master_wav']],
        ], $author);
    }

    private function offerData(Track $track, LicenseVersion $version): array
    {
        return ['track_id' => $track->id, 'license_version_id' => $version->id, 'price_minor' => 111, 'currency' => 'USD', 'deliverable_asset_ids' => []];
    }

    private function refuses(callable $call, string $field = 'license_version_id'): void
    {
        try {
            $call();
            $this->fail('Expected the sound-kit guard to refuse.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public function test_the_kit_type_is_accepted_for_a_template_and_other_unknown_types_still_are_not(): void
    {
        $this->assertSame('sound-kit', $this->kitVersion()->template->type);
        $this->assertTrue(SoundKitLicenseKind::isKitType('sound-kit'));
        foreach (['non-exclusive', 'exclusive', 'free', 'sound kit', 'Sound-Kit', null, 1] as $other) {
            $this->assertFalse(SoundKitLicenseKind::isKitType($other));
        }
        $author = LicenseFixtures::admin();
        $this->actingAs($author);
        $this->expectException(ValidationException::class);
        app(SaveLicenseTemplate::class)->create(['name' => 'X', 'slug' => 'unknown-kind', 'type' => 'sample-pack'], $author);
    }

    public function test_a_beat_offer_can_never_carry_a_kit_license(): void
    {
        $kit = $this->kitVersion();
        $beat = LicenseFixtures::draft();
        $track = Track::create(['title' => 'Synthetic track', 'slug' => 'synthetic-track-'.Str::uuid()]);

        // The draft validation refuses with a field error, and the beat license passes the same validation.
        $this->refuses(fn () => app(OfferDraftInput::class)->validate($this->offerData($track, $kit)));
        $this->assertSame($beat->id, app(OfferDraftInput::class)->validate($this->offerData($track, $beat))['license_version_id']);

        // Defense in depth at the model: a create or a re-point of an existing beat offer refuses too.
        $this->refuses(fn () => Offer::create($this->offerData($track, $kit)));
        $this->assertSame(0, Offer::where('license_version_id', $kit->id)->count());
        $offer = Offer::create($this->offerData($track, $beat));
        $offer->license_version_id = $kit->id;
        $this->refuses(fn () => $offer->save());
        $this->assertSame($beat->id, $offer->fresh()->license_version_id);
        // Unrelated edits to a beat offer do not consult the license at all.
        $offer->refresh()->update(['price_minor' => 222]);
        $this->assertSame(222, $offer->fresh()->price_minor);
    }

    public function test_a_kit_carries_only_a_kit_license(): void
    {
        $kit = $this->kitVersion();
        SoundKitLicenseAttachment::assertKitAttachable($kit);
        foreach (['non-exclusive', 'exclusive', 'free'] as $type) {
            $template = LicenseTemplate::create(['name' => 'NONBINDING '.$type, 'slug' => 'beat-'.Str::uuid(), 'type' => $type]);
            $version = app(CreateLicenseDraft::class)->handle($template, ['authored_source' => 'NONBINDING SYNTHETIC',
                'structured_terms' => ['schema_version' => 1, 'features' => ['x'], 'required_asset_roles' => ['master_wav']]], LicenseFixtures::admin());
            $this->refuses(fn () => SoundKitLicenseAttachment::assertKitAttachable($version));
        }
        $this->refuses(fn () => SoundKitLicenseAttachment::assertKitAttachable(null));
        $this->refuses(fn () => SoundKitLicenseAttachment::assertBeatOfferable($kit));
        SoundKitLicenseAttachment::assertBeatOfferable(null);
    }

    public function test_a_kit_license_text_must_declare_exactly_the_placeholder_set(): void
    {
        $text = '[SYNTHETIC] '.implode(' ', array_map(static fn (string $name): string => '{'.$name.'}', SoundKitLicenseKind::PLACEHOLDERS));
        SoundKitLicenseKind::assertTemplate($text);
        foreach ([str_replace('{LICENSE_FEE}', '', $text), $text.' {CONTRACT_DATE}', $text.' {', '[SYNTHETIC]'] as $bad) {
            try {
                SoundKitLicenseKind::assertTemplate($bad);
                $this->fail('Expected a refusal.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('license', $exception->errors());
            }
        }
    }
}
