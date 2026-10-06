<?php

namespace Tests\Support;

use App\Domain\Catalog\SaveOfferDraft;
use App\Domain\Catalog\SaveTrackMetadata;
use Illuminate\Support\Str;
use LogicException;

/** Disposable synthetic drafts; publication and paid-history tests use their real existing fixtures. */
final class ReviewedOfferDraftFixtures
{
    public static function draft(): array
    {
        if (! app()->environment('testing')) {
            throw new LogicException('Reviewed offer fixtures require the isolated testing environment.');
        }
        $actor = LicenseFixtures::admin();
        $actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $track = app(SaveTrackMetadata::class)->handle(null, ['title' => 'Synthetic reviewed offer recording',
            'slug' => 'reviewed-offer-'.Str::uuid(), 'artist' => 'Test only'], $actor);
        $license = LicenseFixtures::draft($actor);
        $offer = app(SaveOfferDraft::class)->handle(null, ['track_id' => $track->id, 'license_version_id' => $license->id,
            'price_minor' => 4999, 'currency' => 'USD', 'deliverable_asset_ids' => []], $actor);
        $offer->refresh();

        return compact('actor', 'track', 'license', 'offer');
    }
}
