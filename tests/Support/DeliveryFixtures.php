<?php

namespace Tests\Support;

use App\Domain\Catalog\PublishOffer;
use App\Domain\Catalog\PublishTrack;
use App\Domain\Catalog\SaveOfferDraft;
use App\Domain\Commerce\Checkout\HostedCheckout;
use App\Domain\Commerce\CreateQuote;
use App\Domain\Commerce\Inventory\ManageRightsScope;
use App\Domain\Commerce\Models\CheckoutIntent;
use App\Domain\Commerce\Orders\PrepareOrder;
use App\Domain\Commerce\PriceQuote;
use App\Domain\Delivery\ActivateTestFulfillment;
use App\Domain\Delivery\ManageTestDeliveryControl;
use App\Domain\Delivery\Models\TestFulfillmentActivation;
use App\Domain\Delivery\PrepareTestDeliveryStream;
use App\Domain\Delivery\PreparedDeliveryStream;
use App\Domain\Media\BindStemsToRecording;
use App\Domain\Rights\Models\RightsDeclaration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Real synthetic paid-order state transitions and private files; no production grants or access. */
final class DeliveryFixtures
{
    public static function policy(): array
    {
        return ['schema_version' => 1, 'purpose' => 'test_owner_delivery', 'version' => 'test-owner-delivery-v1',
            'scope' => 'activated_order_owner', 'storage' => 'private_local', 'verification' => 'fresh_sha256',
            'token_bytes' => 32, 'authorization_ttl_seconds' => 60, 'new_authorizations_per_order60_seconds' => 3,
            'stream_attempts' => 1, 'ranges' => 'disabled', 'pending_entitlements' => 'preserve', 'buyer_identity' => 'unverified_guest'];
    }

    public static function configure(): void
    {
        ActivationFixtures::configure();
        config(['delivery.test_access_enabled' => true, 'delivery.test_access_policy' => json_encode(self::policy(), JSON_THROW_ON_ERROR)]);
    }

    public static function ready(object $gateway, bool $mixed = false, bool $enable = true): array
    {
        return self::activate(ActivationFixtures::issued($gateway, $mixed), $enable);
    }

    public static function activate(array $fixture, bool $enable = true): array
    {
        if (app(ActivateTestFulfillment::class)->handle($fixture['order']->id) !== 'activated') { throw new \LogicException('Synthetic order did not activate.'); }
        $fixture['fulfillment_activation'] = TestFulfillmentActivation::where('order_id', $fixture['order']->id)->sole();
        if ($enable) {
            app(ManageTestDeliveryControl::class)->handle($fixture['order']->public_id, true, 0, 'SYNTHETIC-INITIAL-BLOCK');
            $fixture['delivery_control'] = app(ManageTestDeliveryControl::class)->handle($fixture['order']->public_id, false, 0, 'SYNTHETIC-ENABLE');
        }
        return $fixture;
    }

    public static function allRoles(object $gateway): array
    {
        $f = RecordingFixtures::draft(); extract($f);
        app(BindStemsToRecording::class)->handle($stems, $data, $actor);
        RightsDeclaration::create(['track_id' => $track->id, 'provenance_reference' => 'SYNTHETIC DELIVERY RIGHTS',
            'sample_disclosure' => 'Synthetic source', 'status' => 'verified', 'verified_by' => $actor->id, 'verified_at' => now()]);
        $license = LicenseFixtures::published($actor, terms: ['schema_version' => 1, 'features' => ['Synthetic delivery fixture'],
            'required_asset_roles' => ['download_mp3', 'master_wav', 'stems_zip']]);
        $offer = app(SaveOfferDraft::class)->handle(null, ['track_id' => $track->id, 'license_version_id' => $license->id,
            'price_minor' => 4999, 'currency' => 'USD', 'deliverable_asset_ids' => [$media['download_mp3']->id, $media['master_wav']->id, $stems->id]], $actor);
        $revision = app(PublishOffer::class)->handle($offer, $actor); app(PublishTrack::class)->handle($track, $actor);
        $scopes = app(ManageRightsScope::class); $scope = $scopes->register('delivery-'.Str::uuid(), 'SYNTHETIC-DELIVERY-SCOPE', $actor);
        $scopes->link($scope->id, $revision->id, 'SYNTHETIC-DELIVERY-LINK', $actor);
        $quote = app(CreateQuote::class)->handle(InventoryFixtures::OWNER, (string) Str::uuid(), [[
            'trackId' => $track->id, 'offerId' => $offer->id, 'licenseVersionId' => $license->id, 'offerRevisionId' => $revision->id]]);
        app(PriceQuote::class)->create($quote->public_id, InventoryFixtures::OWNER);
        $order = app(PrepareOrder::class)->handle(InventoryFixtures::OWNER, (string) Str::uuid(), OrderFixtures::request($quote));
        app(HostedCheckout::class)->start($order->public_id, InventoryFixtures::OWNER);
        $gateway->session['status'] = 'complete'; $gateway->session['payment_status'] = 'paid'; $gateway->session['url'] = null;
        $gateway->session['payment_intent'] = PaymentFixtures::PAYMENT; $gateway->payment = PaymentFixtures::payment($gateway->session);
        $confirmed = FinalizationFixtures::confirm(['order' => $order, 'intent' => CheckoutIntent::where('order_id', $order->id)->sole()]);
        return self::activate(ActivationFixtures::issue(ContractFixtures::finalize($confirmed)));
    }

    public static function retained(): array
    {
        return ContractFixtures::retained() + ['test_fulfillment_activations' => json_encode(DB::table('test_fulfillment_activations')->orderBy('id')->get(), JSON_THROW_ON_ERROR)];
    }

    public static function observingStreams(): PrepareTestDeliveryStream
    {
        return new class extends PrepareTestDeliveryStream {
            public array $transactionLevels = [];
            public array $resources = [];
            public mixed $afterPrepare = null;
            public function handle(array $target): PreparedDeliveryStream
            {
                $this->transactionLevels[] = DB::transactionLevel();
                $prepared = parent::handle($target); $this->resources[] = $prepared->stream();
                if ($this->afterPrepare !== null) { ($this->afterPrepare)($target, $prepared); }
                return $prepared;
            }
        };
    }
}
