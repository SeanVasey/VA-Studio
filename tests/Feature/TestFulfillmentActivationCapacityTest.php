<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublishOffer;
use App\Domain\Catalog\PublishTrack;
use App\Domain\Catalog\SaveOfferDraft;
use App\Domain\Commerce\Checkout\HostedCheckout;
use App\Domain\Commerce\CreateQuote;
use App\Domain\Commerce\Inventory\ManageRightsScope;
use App\Domain\Commerce\Models\CheckoutIntent;
use App\Domain\Commerce\Models\PendingEntitlement;
use App\Domain\Commerce\Orders\PrepareOrder;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Commerce\PriceQuote;
use App\Domain\Contracts\ContractRenderer;
use App\Domain\Contracts\Models\GrantContract;
use App\Domain\Delivery\ActivateTestFulfillment;
use App\Domain\Delivery\ActivationEvidence;
use App\Domain\Delivery\Models\TestFulfillmentActivation;
use App\Domain\Delivery\ReadTestFulfillmentActivation;
use App\Domain\Media\BindStemsToRecording;
use App\Domain\Media\MediaProcessor;
use App\Domain\Media\PrivateMediaFiles;
use App\Domain\Media\QueueMediaProcessing;
use App\Domain\Rights\Models\RightsDeclaration;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ActivationFixtures;
use Tests\Support\ContractFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\FinalizationFixtures;
use Tests\Support\InventoryFixtures;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\Support\OrderFixtures;
use Tests\Support\PaymentFixtures;
use Tests\Support\StemsFixtures;
use Tests\TestCase;

class TestFulfillmentActivationCapacityTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public function test_maximum_cart_retains_all_thirty_deliverables_with_maximum_stems_metadata_within_original_evidence_limits(): void
    {
        $this->fakePrivateMediaStorage(); $this->travelTo(now()->startOfSecond()); ActivationFixtures::configure();
        config(['media.waveform_points' => 1000]);
        $gateway = PaymentFixtures::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $gateway); $this->app->instance(StripePaymentGateway::class, $gateway);
        $this->app->instance(ContractRenderer::class, ContractFixtures::renderer());
        $actor = LicenseFixtures::admin();
        $first = Track::create(['title' => 'Synthetic capacity recording', 'slug' => 'capacity-first',
            'artist' => 'Test only', 'bpm' => 90, 'musical_key' => 'C minor', 'genre' => 'Test']);
        $media = MediaFixtures::readyTrackMedia($first, $actor);
        $members = [];
        for ($index = 0; $index < 128; $index++) {
            // Maximum-sized fixture paths use valid <=100-byte segments, not an invalid long basename.
            $name = str_repeat('A', 80).'/'.str_repeat('B', 80).'/'.sprintf('%03d', $index).'-'.str_repeat('C', 68).'.wav';
            $this->assertSame(238, strlen($name)); $members[] = ['name' => $name];
        }
        $source = MediaFixtures::source($first, 'stems_zip', StemsFixtures::zip($members));
        $run = app(MediaProcessor::class)->handle(app(QueueMediaProcessing::class)->handle($source, $actor)->id);
        $media['stems_zip'] = $run->outputs()->sole();
        $this->assertCount(128, $media['stems_zip']->technical_metadata['manifest']);
        $this->assertCount(1000, $media['preview_tagged']->technical_metadata['waveform']);
        $license = LicenseFixtures::published($actor, terms: ['schema_version' => 1,
            'features' => ['NONBINDING synthetic MP3, WAV and stems capacity fixture'],
            'required_asset_roles' => ['download_mp3', 'master_wav', 'stems_zip']]);
        $items = [];
        for ($index = 0; $index < 10; $index++) {
            if ($index === 0) { $track = $first; $outputs = $media; }
            else {
                $track = Track::create(['title' => 'Synthetic capacity recording '.$index, 'slug' => 'capacity-'.$index,
                    'artist' => 'Test only', 'bpm' => 90, 'musical_key' => 'C minor', 'genre' => 'Test']);
                // Copy already-validated synthetic processing evidence into new fixture identities.
                // Every copied revision has its own private sealed file; no guard is disabled or bypassed.
                $outputs = $this->copySyntheticMedia($media, $track);
            }
            app(BindStemsToRecording::class)->handle($outputs['stems_zip'], [
                'master_asset_id' => $outputs['master_wav']->id, 'preview_asset_id' => $outputs['preview_tagged']->id,
                'verification_reference' => 'SYNTHETIC CAPACITY FIXTURE ONLY', 'same_recording_confirmed' => true,
            ], $actor);
            RightsDeclaration::create(['track_id' => $track->id, 'provenance_reference' => 'SYNTHETIC CAPACITY RIGHTS',
                'sample_disclosure' => 'Synthetic source', 'status' => 'verified', 'verified_by' => $actor->id, 'verified_at' => now()]);
            $offer = app(SaveOfferDraft::class)->handle(null, ['track_id' => $track->id, 'license_version_id' => $license->id,
                'price_minor' => 4999, 'currency' => 'USD',
                'deliverable_asset_ids' => array_map(fn ($role) => $outputs[$role]->id, ['download_mp3', 'master_wav', 'stems_zip'])], $actor);
            $revision = app(PublishOffer::class)->handle($offer, $actor);
            app(PublishTrack::class)->handle($track, $actor);
            $scopes = app(ManageRightsScope::class);
            $scope = $scopes->register('capacity-scope-'.$index, 'SYNTHETIC-CAPACITY-SCOPE', $actor);
            $scopes->link($scope->id, $revision->id, 'SYNTHETIC-CAPACITY-LINK', $actor);
            $items[] = ['trackId' => $track->id, 'offerId' => $offer->id, 'licenseVersionId' => $license->id, 'offerRevisionId' => $revision->id];
        }
        $quote = app(CreateQuote::class)->handle(InventoryFixtures::OWNER, (string) Str::uuid(), $items);
        app(PriceQuote::class)->create($quote->public_id, InventoryFixtures::OWNER);
        $order = app(PrepareOrder::class)->handle(InventoryFixtures::OWNER, (string) Str::uuid(), OrderFixtures::request($quote));
        app(HostedCheckout::class)->start($order->public_id, InventoryFixtures::OWNER);
        $gateway->session['status'] = 'complete'; $gateway->session['payment_status'] = 'paid';
        $gateway->session['url'] = null; $gateway->session['payment_intent'] = PaymentFixtures::PAYMENT;
        $gateway->payment = PaymentFixtures::payment($gateway->session);
        $confirmed = FinalizationFixtures::confirm(['order' => $order, 'intent' => CheckoutIntent::where('order_id', $order->id)->sole()]);
        ActivationFixtures::issue(ContractFixtures::finalize($confirmed));
        $this->assertDatabaseCount('grant_contracts', 10); $this->assertDatabaseCount('pending_entitlements', 30);
        $before = ContractFixtures::retained();

        $this->assertSame('activated', app(ActivateTestFulfillment::class)->handle($order->id));
        $proof = TestFulfillmentActivation::sole(); $canonical = Crypt::decryptString($proof->evidence_ciphertext);
        $payload = json_decode($canonical, true, 128, JSON_THROW_ON_ERROR);
        $this->assertSame($canonical, CanonicalJson::encode($payload));
        $this->assertCount(10, $payload['snapshot']['members']); $this->assertCount(30, $payload['snapshot']['assets']);
        $this->assertLessThan(ActivationEvidence::MAX_BYTES / 4, strlen($canonical));
        $this->assertLessThanOrEqual(ActivationEvidence::MAX_CIPHERTEXT_BYTES, strlen($proof->evidence_ciphertext));
        $ids = collect($payload['snapshot']['members'])->flatMap(fn ($member) => array_column($member['entitlements'], 'id'))->sort()->values()->all();
        $this->assertSame(PendingEntitlement::orderBy('id')->pluck('id')->all(), $ids);
        $this->assertSame(GrantContract::orderBy('id')->pluck('public_id')->all(), array_column(array_column($payload['snapshot']['members'], 'original'), 'public_id'));
        $this->assertSame($proof->id, app(ReadTestFulfillmentActivation::class)->forOrder($order->fresh())->id);
        $this->assertSame('activated', app(ActivateTestFulfillment::class)->handle($order->id));
        $this->assertSame(1, DB::table('audit_events')->where('action', 'commerce.fulfillment.test_activated')->count());
        $this->assertSame($before, ContractFixtures::retained());
    }

    private function copySyntheticMedia(array $originals, Track $track): array
    {
        $outputs = [];
        foreach (collect($originals)->unique('processing_run_id') as $example) {
            $originalRun = $example->processingRun()->sole();
            $source = $originalRun->source()->sole()->replicate();
            $sourceBytes = Storage::disk($source->disk)->get($source->storage_path);
            $source->storage_path = 'quarantine/'.Str::uuid().'/source.bin';
            $this->assertTrue(Storage::disk($source->disk)->put($source->storage_path, $sourceBytes));
            $source->track_id = $track->id; $source->save();
            $run = $originalRun->replicate();
            $run->source_asset_id = $source->id; $run->status = 'processing';
            $run->output_asset_ids = null; $run->completed_at = null; $run->save();
            $newIds = [];
            foreach ($originalRun->outputs()->orderBy('id')->get() as $original) {
                $output = $original->replicate();
                $output->track_id = $track->id; $output->parent_asset_id = $source->id; $output->processing_run_id = $run->id;
                $files = app(PrivateMediaFiles::class);
                $output->storage_path = $files->promote($files->resolve($original->storage_path), (string) Str::uuid(), basename($original->storage_path));
                $output->save(); $newIds[] = $output->id; $outputs[$output->role] = $output;
            }
            $run->output_asset_ids = $newIds; $run->status = 'completed'; $run->completed_at = $originalRun->completed_at; $run->save();
        }
        return $outputs;
    }
}
