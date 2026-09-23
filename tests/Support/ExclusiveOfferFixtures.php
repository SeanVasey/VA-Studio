<?php

namespace Tests\Support;

use App\Domain\Catalog\SaveOfferDraft;
use App\Domain\Commerce\Inventory\ManageRightsScope;
use App\Domain\Commerce\Models\RightsScope;
use App\Domain\Rights\CreateLicenseDraft;
use App\Domain\Rights\Models\LicenseTemplate;
use App\Domain\Rights\PublishLicense;
use App\Domain\Rights\ReviewLicense;
use Illuminate\Support\Str;

/** Synthetic evidence only. No real exclusive terms, ownership or sale policy. */
final class ExclusiveOfferFixtures
{
    public static function draft(?RightsScope $scope = null, array $content = []): array
    {
        $legacy = QuoteFixtures::selection();
        $actor = $legacy['actor'];
        $template = LicenseTemplate::create(['name' => 'NONBINDING EXCLUSIVE FIXTURE', 'slug' => 'test-exclusive-'.Str::uuid(), 'type' => 'exclusive']);
        $draft = app(CreateLicenseDraft::class)->handle($template, $content + [
            'authored_source' => EconomicLicenseFixtures::source(), 'structured_terms' => EconomicLicenseFixtures::terms(),
        ], $actor);
        $review = app(ReviewLicense::class);
        $submitted = $review->submit($draft, $actor);
        $approved = $review->approve($submitted, LicenseFixtures::admin(), ['approval_reference' => 'SYNTHETIC-EXCLUSIVE-ONLY',
            'review_hash' => $submitted->submission_hash, 'summary_consistency_confirmed' => true]);
        $license = app(PublishLicense::class)->handle($approved, $actor);
        $offer = app(SaveOfferDraft::class)->handle(null, [
            'track_id' => $legacy['track']->id, 'license_version_id' => $license->id, 'price_minor' => 123456,
            'currency' => 'USD', 'deliverable_asset_ids' => [$legacy['media']['master_wav']->id],
        ], $actor);
        $scope ??= app(ManageRightsScope::class)->register('test-exclusive-'.Str::uuid(), 'PRIVATE-TEST-SCOPE', $actor);

        return ['actor' => $actor, 'track' => $legacy['track'], 'media' => $legacy['media'],
            'offer' => $offer, 'license' => $license, 'scope' => $scope, 'legacy' => $legacy];
    }
}
