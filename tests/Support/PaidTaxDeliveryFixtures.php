<?php

namespace Tests\Support;

use App\Domain\Catalog\PublishTrack;
use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxCheckout;
use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Media\BindStemsToRecording;
use App\Domain\Media\MediaProcessor;
use App\Domain\Media\QueueMediaProcessing;
use App\Domain\Offers\PublishOffer;
use App\Domain\Offers\SaveOfferDraft;

/** Authored synthetic multi-role offer on actual processed immutable private media. */
trait PaidTaxDeliveryFixtures
{
    protected function allRolesTaxOrder(): array
    {
        $catalog = $this->taxCatalog();
        $actor = $catalog['actor'];
        $track = $catalog['track'];
        $source = MediaFixtures::source($track, 'master_wav', MediaFixtures::wav(13));
        $run = app(MediaProcessor::class)->handle(app(QueueMediaProcessing::class)->handle($source, $actor)->id);
        $media = $run->outputs()->get()->keyBy('role')->all();
        $source = MediaFixtures::source($track, 'stems_zip', StemsFixtures::zip([['name' => 'Synthetic drums.wav']]));
        $run = app(MediaProcessor::class)->handle(app(QueueMediaProcessing::class)->handle($source, $actor)->id);
        $stems = $run->outputs()->sole();
        app(BindStemsToRecording::class)->handle($stems, ['master_asset_id' => $media['master_wav']->id,
            'preview_asset_id' => $media['preview_tagged']->id, 'verification_reference' => 'NONBINDING SYNTHETIC DELIVERY RECORDING',
            'same_recording_confirmed' => true], $actor);
        $media['stems_zip'] = $stems;
        $license = LicenseFixtures::published($actor, terms: ['schema_version' => 1, 'features' => ['NONBINDING SYNTHETIC ALL ROLES'],
            'required_asset_roles' => ['master_wav', 'download_mp3', 'stems_zip']]);
        $offer = app(SaveOfferDraft::class)->handle($catalog['offer'], ['track_id' => $track->id, 'license_version_id' => $license->id,
            'price_minor' => 4999, 'currency' => 'USD', 'deliverable_asset_ids' => [$media['master_wav']->id, $media['download_mp3']->id, $stems->id]], $actor);
        $revision = app(PublishOffer::class)->handle($offer, $actor);
        app(PublishTrack::class)->handle($track->fresh(), $actor);
        $catalog['media'] = $media;
        $catalog['items'] = [['trackId' => $track->id, 'offerId' => $offer->id, 'licenseVersionId' => $license->id, 'offerRevisionId' => $revision->id]];
        $buyer = $this->enrollThroughLocalSmtp();
        $access = new ProductionCustomerAccess;
        $transport = new RecordingTaxCheckoutTransport;
        $checkout = new ProductionTaxCheckout($access, $transport);
        $preview = $checkout->preview($buyer['principal'], $buyer['user'], $catalog['candidate']->id, $catalog['items']);
        $order = $checkout->order($buyer['principal'], $buyer['user'], $catalog['candidate']->id, $catalog['items'], $preview['previewHash'], true,
            ['legalName' => 'Declared synthetic buyer'], 'synthetic-all-roles-tax');
        self::configureTax(self::SYNTHETIC_PROVIDER);
        $checkout->initiate($buyer['principal'], $buyer['user'], $order['orderId']);
        $transport->paid = true;
        $this->assertSame('verified', $checkout->reconcile($buyer['principal'], $buyer['user'], $order['orderId'])['paymentStatus']);

        return compact('catalog', 'buyer', 'access', 'transport', 'checkout', 'preview', 'order');
    }
}
