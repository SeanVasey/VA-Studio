<?php

namespace Tests\Support;

use App\Domain\Rights\CreateLicenseDraft;
use App\Domain\Rights\Models\LicenseTemplate;
use App\Domain\Rights\Models\LicenseVersion;
use App\Domain\Rights\PublishLicense;
use App\Domain\Rights\ReviewLicense;
use App\Domain\SoundKits\Models\SoundKitDraft;
use App\Domain\SoundKits\Models\SoundKitRevision;
use App\Domain\SoundKits\Products\SoundKitProduct;
use App\Domain\SoundKits\Products\SoundKitProducts;
use App\Domain\SoundKits\SoundKitDrafts;
use App\Domain\SoundKits\SoundKitIntake;
use App\Domain\SoundKits\SoundKitProcessor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Synthetic sound-kit products for tests, shared by the storefront and media suites. The host test class provides
 * `$this->actor` (an MFA-enrolled catalog admin) and has bound the testing-only scanner (MediaFixtures::configure()).
 */
trait SoundKitProductFixtures
{
    private int $sequence = 0;

    /** A private kit with a technically verified (ready) archive revision, built through the real intake and processor. */
    private function kit(bool $process = true): array
    {
        $this->sequence++;
        $draft = app(SoundKitDrafts::class)->save(null, ['title' => 'Synthetic private kit '.$this->sequence,
            'description' => 'No commercial license or price', 'provenance' => 'Synthetic test generator'], $this->actor);
        $upload = UploadedFile::fake()->createWithContent('samples.zip', StemsFixtures::zip([
            ['name' => 'Kick.wav'], ['name' => 'Bass.wav', 'bytes' => MediaFixtures::wav(0.35, 120)],
        ]));
        $revision = app(SoundKitIntake::class)->handle($draft->id, $upload, 1, $this->actor);
        if ($process) {
            $revision = app(SoundKitProcessor::class)->handle($revision->id);
            $this->assertSame('ready', $revision->status, $revision->failure_code ?? '');
        }

        return [$draft, $revision];
    }

    /** A published, reviewed license version of the given template type. Synthetic and nonbinding. */
    private function license(string $type = 'sound-kit', bool $publish = true): LicenseVersion
    {
        $author = LicenseFixtures::admin();
        $template = LicenseTemplate::create(['name' => 'NONBINDING SYNTHETIC '.$type, 'slug' => 'synthetic-'.Str::uuid(), 'type' => $type]);
        $draft = app(CreateLicenseDraft::class)->handle($template, ['authored_source' => '[SYNTHETIC] TEST LICENSE. Test evidence only.',
            'structured_terms' => ['schema_version' => 1, 'features' => ['Synthetic grant'], 'required_asset_roles' => ['master_wav']]], $author);
        if (! $publish) {
            return $draft;
        }
        $review = app(ReviewLicense::class);
        $submitted = $review->submit($draft, $author);
        $approved = $review->approve($submitted, LicenseFixtures::admin(), ['approval_reference' => 'SYNTHETIC-TEST-ONLY',
            'review_hash' => $submitted->submission_hash, 'summary_consistency_confirmed' => true]);

        return app(PublishLicense::class)->handle($approved, $author);
    }

    private function form(SoundKitDraft $draft, ?SoundKitRevision $revision, ?LicenseVersion $license, array $over = []): array
    {
        return $over + ['sound_kit_draft_id' => $draft->id, 'title' => 'Synthetic Kit '.$draft->id, 'slug' => 'synthetic-kit-'.$draft->id,
            'summary' => 'A clearly synthetic public summary.', 'revision_public_id' => $revision?->public_id, 'license_version_id' => $license?->id, 'price_minor' => 2500];
    }

    /** A product taken as far as asked: 'saved', 'cleared', 'approved' or 'published'. */
    private function product(string $upTo = 'published', array $over = [], ?LicenseVersion $license = null, bool $media = true): SoundKitProduct
    {
        [$draft, $revision] = $this->kit();
        $service = app(SoundKitProducts::class);
        $product = $service->save(null, $this->form($draft, $revision, $license ?? $this->license(), $over), $this->actor);
        foreach (['cleared', 'approved', 'published'] as $stage) {
            if ($upTo === 'saved') {
                break;
            }
            if ($stage === 'published' && $media) {
                // A kit needs a staff-approved cover and demo as well (SoundKitProductMedia).
                $product = SoundKitMediaFixtures::approved($product, $this->actor);
            }
            $product = match ($stage) {
                'cleared' => $service->clearRights($product, $product->revision, 'Synthetic clearance note', $this->actor),
                'approved' => $service->approveOffer($product, $product->revision, $this->actor),
                'published' => $service->publish($product, $product->revision, $this->actor),
            };
            if ($upTo === $stage) {
                break;
            }
        }

        return $product->refresh();
    }
}
