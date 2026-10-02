<?php

namespace Tests\Support;

use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublishOffer;
use App\Domain\Catalog\PublishTrack;
use App\Domain\Catalog\SaveOfferDraft;
use App\Domain\Rights\Models\RightsDeclaration;
use Illuminate\Support\Str;

/** Nonbinding test-only catalog with the real synthetic media/review/publication services. */
final class QuoteFixtures
{
    public static function selection(int $priceMinor = 4999): array
    {
        $actor = LicenseFixtures::admin();
        // This explicit synthetic publisher must satisfy the real panel's required MFA enrollment.
        $actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $track = Track::create(['title' => 'Synthetic quote recording', 'slug' => 'quote-fixture-'.Str::uuid(), 'artist' => 'Test only', 'bpm' => 90, 'musical_key' => 'C minor', 'genre' => 'Test']);
        RightsDeclaration::create(['track_id' => $track->id, 'provenance_reference' => 'TEST-ONLY-QUOTE-RIGHTS', 'sample_disclosure' => 'Synthetic source', 'status' => 'verified', 'verified_by' => $actor->id, 'verified_at' => now()]);
        $media = MediaFixtures::readyTrackMedia($track, $actor);
        $license = LicenseFixtures::published($actor);
        $offer = app(SaveOfferDraft::class)->handle(null, ['track_id' => $track->id, 'license_version_id' => $license->id, 'price_minor' => $priceMinor, 'currency' => 'USD', 'deliverable_asset_ids' => [$media['master_wav']->id]], $actor);
        $revision = app(PublishOffer::class)->handle($offer, $actor);
        $track = app(PublishTrack::class)->handle($track, $actor);
        $items = [['trackId' => $track->id, 'offerId' => $offer->id, 'licenseVersionId' => $license->id, 'offerRevisionId' => $revision->id]];

        return compact('actor', 'track', 'offer', 'media', 'revision', 'items');
    }
}
