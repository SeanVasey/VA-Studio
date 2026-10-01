<?php

namespace Tests\Feature;

use App\Domain\Catalog\DeactivateOffer;
use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublishOffer;
use App\Domain\Catalog\PublishTrack;
use App\Domain\Catalog\ReadTrackSharing;
use App\Domain\Catalog\SaveOfferDraft;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Media\MediaProcessor;
use App\Domain\Media\QueueMediaProcessing;
use App\Domain\Rights\Models\RightsDeclaration;
use App\Filament\Resources\TrackResource\Pages\ManageTracks;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ContractFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\Support\PaymentFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class ReadTrackSharingTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        config(['app.url' => 'https://audio.example.test']);
    }

    private function read(array $fixture): array
    {
        return app(ReadTrackSharing::class)->handle($fixture['track']->id, $fixture['actor']);
    }

    private function evidence(): array
    {
        $result = [];
        foreach (['tracks', 'rights_declarations', 'media_assets', 'offers', 'offer_revisions', 'license_versions', 'audit_events',
            'orders', 'license_grants', 'pending_entitlements', 'fulfillment_outbox', 'exclusive_sales'] as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $result;
    }

    private function unavailable(callable $read): void
    {
        try {
            $read();
            $this->fail('Unavailable track produced sharing destinations.');
        } catch (ValidationException $exception) {
            $this->assertSame(['sharing' => ['Public sharing is unavailable for this track. Refresh the catalog or check the configured store origin.']], $exception->errors());
        }
    }

    private function document(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML($html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new DOMXPath($document);
    }

    public function test_descriptor_is_minimal_fixed_origin_and_read_only_even_with_host_query_and_stale_title(): void
    {
        $fixture = QuoteFixtures::selection();
        $fixture['track']->update(['title' => 'Public <script>private()</script> & "title"', 'description' => 'PRIVATE WORKING NOTE']);
        $fixture['track']->title = 'STALE TABLE TITLE';
        request()->headers->set('host', 'untrusted.example.test');
        request()->query->add(['url' => 'https://untrusted.example.test', 'token' => 'PRIVATE-REVIEW-TOKEN']);
        $before = $this->evidence();
        $sharing = $this->read($fixture);
        $this->assertSame(['title', 'trackUrl', 'embedUrl', 'embedCode'], array_keys($sharing));
        $this->assertSame('Public <script>private()</script> & "title"', $sharing['title']);
        $this->assertSame('https://audio.example.test/tracks/'.$fixture['track']->slug, $sharing['trackUrl']);
        $this->assertSame('https://audio.example.test/embed/tracks/'.$fixture['track']->slug, $sharing['embedUrl']);
        $frame = $this->document($sharing['embedCode']);
        $this->assertCount(1, $frame->query('//iframe'));
        $this->assertSame($sharing['embedUrl'], $frame->evaluate('string(//iframe/@src)'));
        $this->assertSame('VASEY.AUDIO tagged track preview', $frame->evaluate('string(//iframe/@title)'));
        $this->assertSame('lazy', $frame->evaluate('string(//iframe/@loading)'));
        $this->assertSame('no-referrer', $frame->evaluate('string(//iframe/@referrerpolicy)'));
        $this->assertCount(0, $frame->query('//script | //*[@onload] | //iframe[@allow or @autoplay or @srcdoc]'));
        $html = view('filament.catalog.track-sharing', ['sharing' => $sharing])->render();
        $dom = $this->document($html);
        $this->assertCount(0, $dom->query('//script | //iframe | //*[@onerror]'));
        $this->assertSame($sharing['trackUrl'], $dom->evaluate('string(//input[@readonly][1]/@value)'));
        $this->assertSame($sharing['embedCode'], $dom->evaluate('string(//textarea[@readonly][1])'));
        foreach (['PRIVATE WORKING NOTE', 'PRIVATE-REVIEW-TOKEN', 'untrusted.example.test', 'storage_path',
            $fixture['media']['master_wav']->storage_path, 'licenseVersionId', 'priceMinor', 'site-releases', 'orders/'] as $private) {
            $this->assertStringNotContainsString($private, json_encode($sharing).$html);
        }
        $this->assertSame($before, $this->evidence());
    }

    public function test_safe_local_origin_and_one_trailing_slash_are_supported(): void
    {
        $fixture = QuoteFixtures::selection();
        foreach (['http://127.0.0.1:8173', 'http://localhost:8173/', 'https://audio.example.test/'] as $origin) {
            config(['app.url' => $origin]);
            $sharing = $this->read($fixture);
            $this->assertSame(rtrim($origin, '/').'/tracks/'.$fixture['track']->slug, $sharing['trackUrl']);
            $this->assertSame(rtrim($origin, '/').'/embed/tracks/'.$fixture['track']->slug, $sharing['embedUrl']);
        }
    }

    public function test_unsafe_or_non_root_origins_never_produce_copy_text(): void
    {
        $fixture = QuoteFixtures::selection();
        foreach ([null, '', 'javascript:alert(1)', '//audio.example.test', 'ftp://audio.example.test',
            'https://operator@example.test', 'https://operator:secret@example.test', 'https://audio.example.test/?',
            'https://audio.example.test/#', 'https://audio.example.test?url=private', 'https://audio.example.test/#private',
            'https://audio.example.test/admin', 'https://audio.example.test//', 'https://audio.example.test/%2fadmin',
            "https://audio.example.test/\n", 'https://audio.example.test\\admin', ' https://audio.example.test',
            'https://audio.example.test '.str_repeat('x', 2048)] as $origin) {
            config(['app.url' => $origin]);
            $this->unavailable(fn () => $this->read($fixture));
        }
    }

    public function test_legacy_noncanonical_persisted_published_slugs_cannot_change_copy_destinations(): void
    {
        $ordinary = QuoteFixtures::selection();
        foreach (['legacy/private', 'legacy?order=PRIVATE', 'legacy#PRIVATE', 'legacy%2fadmin', 'legacy\\admin',
            'Legacy-uppercase', 'legacy-trailing ', 'café'] as $slug) {
            // Model-level legacy imports can bypass the ordinary metadata command's slug grammar.
            // Build a genuinely eligible recording before its first publication; do not relax guards.
            $track = Track::create(['title' => 'Synthetic legacy URL', 'slug' => $slug, 'artist' => 'Test only',
                'bpm' => 90, 'musical_key' => 'C minor', 'genre' => 'Test']);
            RightsDeclaration::create(['track_id' => $track->id, 'provenance_reference' => 'SYNTHETIC LEGACY URL',
                'sample_disclosure' => 'Synthetic source', 'status' => 'verified', 'verified_by' => $ordinary['actor']->id, 'verified_at' => now()]);
            $media = MediaFixtures::readyTrackMedia($track, $ordinary['actor']);
            $offer = app(SaveOfferDraft::class)->handle(null, ['track_id' => $track->id,
                'license_version_id' => $ordinary['offer']->license_version_id, 'price_minor' => 4999, 'currency' => 'USD',
                'deliverable_asset_ids' => [$media['master_wav']->id]], $ordinary['actor']);
            app(PublishOffer::class)->handle($offer, $ordinary['actor']);
            app(PublishTrack::class)->handle($track, $ordinary['actor']);
            $this->assertSame('published', $track->fresh()->status);
            $before = $this->evidence();
            $this->unavailable(fn () => app(ReadTrackSharing::class)->handle($track->id, $ordinary['actor']));
            $this->assertSame($before, $this->evidence());
        }
    }

    public static function unavailableStates(): array
    {
        return [['withdrawn'], ['inactive_offer'], ['rights_hold'], ['missing_preview'], ['missing_artwork'], ['missing_master'], ['corrupt_preview']];
    }

    #[DataProvider('unavailableStates')]
    public function test_current_public_eligibility_is_rechecked_after_a_prior_success(string $state): void
    {
        $fixture = QuoteFixtures::selection();
        $this->read($fixture);
        if ($state === 'withdrawn') {
            app(PublishTrack::class)->unpublish($fixture['track'], $fixture['actor']);
        } elseif ($state === 'inactive_offer') {
            app(DeactivateOffer::class)->handle($fixture['offer'], $fixture['actor']);
        } elseif ($state === 'rights_hold') {
            RightsDeclaration::create(['track_id' => $fixture['track']->id, 'provenance_reference' => 'PRIVATE NEW HOLD', 'sample_disclosure' => 'Private', 'status' => 'pending']);
        } else {
            $role = match ($state) { 'missing_artwork' => 'artwork', 'missing_master' => 'master_wav', default => 'preview_tagged' };
            $path = Storage::disk('local')->path($fixture['media'][$role]->storage_path);
            if ($state === 'corrupt_preview') {
                $bytes = file_get_contents($path);
                $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) ^ 1);
                chmod($path, 0600);
                file_put_contents($path, $bytes);
                chmod($path, 0400);
                $this->travel(61)->seconds();
            } else {
                unlink($path);
            }
        }
        $before = $this->evidence();
        $this->unavailable(fn () => $this->read($fixture));
        $this->assertSame($before, $this->evidence());
    }

    public function test_missing_draft_and_invalid_locators_do_not_emit_draft_identity(): void
    {
        $actor = LicenseFixtures::admin();
        $draft = Track::create(['title' => 'PRIVATE DRAFT TITLE', 'slug' => 'private-draft', 'artist' => 'PRIVATE DRAFT ARTIST']);
        foreach ([$draft->id, 0, -1, 2147483647] as $id) {
            $this->unavailable(fn () => app(ReadTrackSharing::class)->handle($id, $actor));
        }
        $html = view('filament.catalog.track-sharing', ['sharing' => null])->render();
        $this->assertStringNotContainsString('PRIVATE DRAFT', $html);
        $this->assertCount(0, $this->document($html)->query('//input | //textarea | //button | //a'));
    }

    public function test_republication_descriptor_uses_public_routes_without_binding_superseded_or_private_media(): void
    {
        $fixture = QuoteFixtures::selection();
        $original = $this->read($fixture);
        app(PublishTrack::class)->unpublish($fixture['track'], $fixture['actor']);
        $source = MediaFixtures::source($fixture['track']->fresh(), 'master_wav', MediaFixtures::wav(1.2, 880));
        $run = app(QueueMediaProcessing::class)->handle($source, $fixture['actor']);
        app(MediaProcessor::class)->handle($run->id);
        $master = $run->outputs()->where('role', 'master_wav')->sole();
        $offer = app(SaveOfferDraft::class)->handle($fixture['offer'], ['deliverable_asset_ids' => [$master->id]], $fixture['actor']);
        app(PublishOffer::class)->handle($offer, $fixture['actor']);
        app(PublishTrack::class)->handle($fixture['track']->fresh(), $fixture['actor']);
        $before = $this->evidence();
        $this->assertSame($original, $this->read($fixture));
        $this->assertSame($before, $this->evidence());
    }

    public function test_exclusive_sale_denies_sharing_without_mutating_retained_purchase_evidence(): void
    {
        ContractFixtures::configure();
        $gateway = PaymentFixtures::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $gateway);
        $this->app->instance(StripePaymentGateway::class, $gateway);
        $fixture = ContractFixtures::paid($gateway);
        $before = ContractFixtures::retained();
        $this->assertSame('published', $fixture['track']->fresh()->status);
        $this->unavailable(fn () => $this->read($fixture));
        $this->assertSame($before, ContractFixtures::retained());
    }

    public function test_direct_reads_recheck_persisted_role_verification_and_deleted_or_unpersisted_actor(): void
    {
        $fixture = QuoteFixtures::selection();
        $revoked = LicenseFixtures::admin();
        User::whereKey($revoked->id)->update(['is_admin' => false]);
        $unverified = LicenseFixtures::admin();
        User::whereKey($unverified->id)->update(['email_verified_at' => null]);
        $deleted = LicenseFixtures::admin();
        $deleted->delete();
        foreach ([null, new User(['name' => 'Not persisted']), User::factory()->create(), $revoked, $unverified, $deleted] as $actor) {
            try {
                app(ReadTrackSharing::class)->handle($fixture['track']->id, $actor);
                $this->fail('Unauthorized actor read sharing destinations.');
            } catch (AuthorizationException $error) {
                $this->assertInstanceOf(AuthorizationException::class, $error);
            }
        }
    }

    public function test_caller_owned_transaction_cannot_supply_a_stale_authority_snapshot(): void
    {
        $fixture = QuoteFixtures::selection();
        $before = $this->evidence();
        DB::beginTransaction();
        try {
            // Establish the caller's normal-read snapshot before invoking the descriptor service.
            User::findOrFail($fixture['actor']->id);
            $this->unavailable(fn () => $this->read($fixture));
        } finally {
            DB::rollBack();
        }
        $this->assertSame($before, $this->evidence());
    }

    public function test_secondary_connection_transaction_also_prevents_descriptor_generation(): void
    {
        $fixture = QuoteFixtures::selection();
        $before = $this->evidence();
        config(['database.connections.sharing_secondary' => config('database.connections.'.config('database.default'))]);
        $secondary = DB::connection('sharing_secondary');
        $secondary->beginTransaction();
        try {
            $this->unavailable(fn () => $this->read($fixture));
        } finally {
            $secondary->rollBack();
            DB::purge('sharing_secondary');
        }
        $this->assertSame($before, $this->evidence());
    }

    public function test_required_mfa_and_retained_component_refresh_use_current_enrollment(): void
    {
        $fixture = QuoteFixtures::selection();
        $panel = Filament::getPanel('admin');
        $wasRequired = $panel->isMultiFactorAuthenticationRequired();
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        try {
            $fixture['actor']->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
            $this->actingAs($fixture['actor']);
            $page = Livewire::test(ManageTracks::class)->mountTableAction('share', $fixture['track'])->assertMountedActionModalSee('Share public track');
            $this->read($fixture);
            User::findOrFail($fixture['actor']->id)->saveAppAuthenticationSecret(null);
            $page->call('$refresh')->assertForbidden();
            try {
                $this->read($fixture);
                $this->fail('Removed MFA enrollment disclosed sharing destinations.');
            } catch (AuthorizationException $error) {
                $this->assertInstanceOf(AuthorizationException::class, $error);
            }
        } finally {
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $wasRequired);
        }
    }

    public function test_actual_operator_modal_projects_copy_fields_and_rechecks_availability_on_refresh(): void
    {
        $fixture = QuoteFixtures::selection();
        $this->actingAs($fixture['actor']);
        $before = $this->evidence();
        $page = Livewire::test(ManageTracks::class)->mountTableAction('share', $fixture['track'])
            ->assertMountedActionModalSee('Share public track')->assertMountedActionModalSee('Copy link')->assertMountedActionModalSee('Copy embed')
            ->assertMountedActionModalSee('https://audio.example.test/tracks/'.$fixture['track']->slug)
            ->assertMountedActionModalSee('https://audio.example.test/embed/tracks/'.$fixture['track']->slug);
        $this->assertSame($before, $this->evidence());
        RightsDeclaration::create(['track_id' => $fixture['track']->id, 'provenance_reference' => 'NEW PRIVATE HOLD', 'sample_disclosure' => 'Private', 'status' => 'pending']);
        $before = $this->evidence();
        $page->call('$refresh')->assertMountedActionModalSee('Public sharing is unavailable')->assertMountedActionModalDontSee('Copy embed');
        $this->assertSame($before, $this->evidence());
    }

    #[DataProvider('authorityWithdrawals')]
    public function test_mounted_share_modal_refresh_denies_withdrawn_persisted_authority(string $field, mixed $value): void
    {
        $fixture = QuoteFixtures::selection();
        $this->actingAs($fixture['actor']);
        $page = Livewire::test(ManageTracks::class)->mountTableAction('share', $fixture['track'])->assertMountedActionModalSee('Copy embed');
        User::whereKey($fixture['actor']->id)->update([$field => $value]);
        $page->call('$refresh')->assertForbidden();
    }

    public static function authorityWithdrawals(): array
    {
        return [['is_admin', false], ['email_verified_at', null]];
    }
}
