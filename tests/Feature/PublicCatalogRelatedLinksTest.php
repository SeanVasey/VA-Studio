<?php

namespace Tests\Feature;

use App\Domain\Catalog\DeactivateOffer;
use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublicationReadiness;
use App\Domain\Catalog\PublicCatalog;
use App\Domain\Catalog\PublishTrack;
use App\Domain\Catalog\SaveTrackMetadata;
use App\Domain\Commerce\Finalization\FinalizationPolicy;
use App\Domain\Commerce\Finalization\FinalizeTestPayment;
use App\Domain\Commerce\Inventory\ManageRightsScope;
use App\Domain\Commerce\Inventory\SelectionInventory;
use App\Domain\Commerce\Models\VerifiedPayment;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Commerce\Payments\VerifyTestPayment;
use App\Domain\Commerce\ReservePricedQuote;
use App\Domain\Rights\Models\RightsDeclaration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ExclusiveSelectionFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\InventoryFixtures;
use Tests\Support\PaymentFixtures;
use Tests\Support\PricingFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class PublicCatalogRelatedLinksTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
    }

    public function test_links_preserve_selected_order_current_text_and_only_public_fields_without_changing_selections(): void
    {
        $a = QuoteFixtures::selection();
        $b = QuoteFixtures::selection();
        $this->rename($a, 'Current first title', 'Current first artist');
        $this->rename($b, 'Current second title', 'Current second artist');
        $catalog = app(PublicCatalog::class);
        $ids = [$b['track']->id, $a['track']->id];
        $ordinary = $catalog->selections($ids);
        $before = $this->retainedEvidence();

        $this->assertSame([$this->link($b), $this->link($a)], $catalog->relatedLinks($ids));
        $this->assertSame([
            ['title' => 'Current second title', 'artist' => 'Current second artist'],
            ['title' => 'Current first title', 'artist' => 'Current first artist'],
        ], $catalog->relatedLinks($ids, false));
        $this->assertSame($ordinary, $catalog->selections($ids));
        $this->assertCount(2, $ordinary['tracks']);
        $this->assertCount(2, $ordinary['licenseTiers']);
        $this->assertSame(4999, $ordinary['tracks'][0]['offers'][0]['priceMinor']);
        foreach (['id', 'slug', 'waveform', 'previewUrl', 'artworkUrl', 'offers', 'shareUrl'] as $field) {
            $this->assertArrayHasKey($field, $ordinary['tracks'][0]);
        }
        $this->assertSame($before, $this->retainedEvidence());

        $this->rename($a, 'New current title', 'New current artist');
        $this->assertSame([
            ['title' => 'New current title', 'artist' => 'New current artist', 'href' => route('tracks.show', $a['track']->slug, false)],
        ], $catalog->relatedLinks([$a['track']->id]));
    }

    public function test_empty_and_unknown_native_id_lists_have_no_projection(): void
    {
        $catalog = app(PublicCatalog::class);
        DB::enableQueryLog();
        $this->assertSame([], $catalog->relatedLinks([]));
        $this->assertSame([], $catalog->relatedLinks([], false));
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame([], $catalog->relatedLinks(range(1, 6)));
        // Distinct native integers must not become equal through float conversion.
        $this->assertSame([], $catalog->relatedLinks([PHP_INT_MAX - 1, PHP_INT_MAX]));
    }

    public static function invalidIds(): array
    {
        return [
            'more than six' => [range(1, 7)],
            'duplicate' => [[1, 1]],
            'associative' => [['selected' => 1]],
            'nonzero index' => [[1 => 1]],
            'zero' => [[0]],
            'negative' => [[-1]],
            'numeric string' => [['1']],
            'leading-zero string' => [['01']],
            'exponent string' => [['1e0']],
            'integral float' => [[1.0]],
            'fraction' => [[1.5]],
            'true' => [[true]],
            'false' => [[false]],
            'null' => [[null]],
            'nested' => [[[1]]],
            'mixed types' => [[1, '2']],
        ];
    }

    #[DataProvider('invalidIds')]
    public function test_related_id_validation_rejects_malformed_input_before_queries(array $ids): void
    {
        DB::enableQueryLog();
        try {
            app(PublicCatalog::class)->relatedLinks($ids);
            $this->fail('Malformed related track IDs were accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('related_track_ids', $exception->errors());
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }

    public static function unavailableStates(): array
    {
        return [['withdrawn'], ['inactive_offer'], ['rights_hold'], ['missing_preview'], ['missing_artwork'], ['missing_master'], ['corrupt_preview']];
    }

    #[DataProvider('unavailableStates')]
    public function test_fresh_links_and_choices_omit_only_currently_ineligible_tracks(string $state): void
    {
        $a = QuoteFixtures::selection();
        $b = QuoteFixtures::selection();
        $this->rename($a, 'Affected related track', 'Synthetic related artist');
        $this->rename($b, 'Surviving related track', 'Synthetic related artist');
        $draft = Track::create(['title' => 'PRIVATE unrelated draft', 'slug' => 'private-unrelated-draft']);
        $catalog = app(PublicCatalog::class);
        $ids = [$a['track']->id, $draft->id, $b['track']->id, PHP_INT_MAX];
        $this->assertSame([$this->link($a), $this->link($b)], $catalog->relatedLinks($ids));
        $this->assertCount(2, $catalog->relatedChoices('related'));

        if ($state === 'withdrawn') {
            app(PublishTrack::class)->unpublish($a['track'], $a['actor']);
        } elseif ($state === 'inactive_offer') {
            app(DeactivateOffer::class)->handle($a['offer'], $a['actor']);
        } elseif ($state === 'rights_hold') {
            RightsDeclaration::create(['track_id' => $a['track']->id, 'status' => 'pending', 'provenance_reference' => 'PRIVATE-RELATED-HOLD', 'sample_disclosure' => 'Private']);
        } else {
            $role = match ($state) {
                'missing_artwork' => 'artwork',
                'missing_master' => 'master_wav',
                default => 'preview_tagged',
            };
            $path = Storage::disk('local')->path($a['media'][$role]->storage_path);
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
        $before = $this->retainedEvidence();
        $this->assertSame([$this->link($b)], $catalog->relatedLinks($ids));
        $this->assertSame([['title' => $b['track']->title, 'artist' => $b['track']->artist]], $catalog->relatedLinks($ids, false));
        $this->assertSame([$b['track']->id => 'Surviving related track · Synthetic related artist'], $catalog->relatedChoices('related'));
        $this->assertSame($before, $this->retainedEvidence());
    }

    public function test_choices_search_only_current_title_and_artist_with_literal_wildcards_and_stable_order(): void
    {
        $a = QuoteFixtures::selection();
        $b = QuoteFixtures::selection();
        $c = QuoteFixtures::selection();
        $d = QuoteFixtures::selection();
        $this->rename($a, 'Alpha', 'Zulu');
        $this->rename($b, 'Alpha', 'Bravo');
        $this->rename($c, 'Zulu 100%_!', 'Literal artist');
        $this->rename($d, 'Alpha', 'Bravo');
        $catalog = app(PublicCatalog::class);
        $before = $this->retainedEvidence();
        $this->assertSame([
            $b['track']->id => 'Alpha · Bravo', $d['track']->id => 'Alpha · Bravo',
            $a['track']->id => 'Alpha · Zulu', $c['track']->id => 'Zulu 100%_! · Literal artist',
        ], $catalog->relatedChoices());
        $this->assertSame([$b['track']->id => 'Alpha · Bravo', $d['track']->id => 'Alpha · Bravo'], $catalog->relatedChoices(' bRaVo '));
        $this->assertSame([$c['track']->id => 'Zulu 100%_! · Literal artist'], $catalog->relatedChoices('%_!'));
        $this->assertSame([$c['track']->id => 'Zulu 100%_! · Literal artist'], $catalog->relatedChoices('literal artist'));
        $this->assertSame([], $catalog->relatedChoices('NONBINDING TEST FIXTURE'));
        $this->assertSame($before, $this->retainedEvidence());
    }

    public function test_choice_search_is_bounded_in_characters_before_hydrating_candidates(): void
    {
        $catalog = app(PublicCatalog::class);
        $this->assertSame([], $catalog->relatedChoices(str_repeat('é', 100)));
        DB::enableQueryLog();
        try {
            $catalog->relatedChoices(str_repeat('é', 101));
            $this->fail('An oversized related track search was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('search', $exception->errors());
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_choices_hydrate_at_most_the_fixed_scan_limit_without_offset_or_unbounded_fallback(): void
    {
        $eligible = QuoteFixtures::selection();
        $this->rename($eligible, 'Zulu eligible choice', 'Synthetic artist');
        $rows = [];
        for ($index = 0; $index < PublicCatalog::SCAN_LIMIT; $index++) {
            // Stale published flags are not readiness evidence and must not leak these records.
            $rows[] = ['title' => 'A PRIVATE UNREADY '.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                'slug' => 'related-unready-'.$index, 'published_slug' => 'related-unready-'.$index,
                'status' => 'published', 'published_at' => now()];
        }
        DB::table('tracks')->insert($rows);
        DB::enableQueryLog();
        $this->assertSame([], app(PublicCatalog::class)->relatedChoices());
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertStringContainsString('limit 48', $queries[0]['query']);
        $this->assertStringNotContainsString('offset', $queries[0]['query']);
        $this->assertSame([$eligible['track']->id => 'Zulu eligible choice · Synthetic artist'], app(PublicCatalog::class)->relatedChoices('eligible choice'));
    }

    public static function inventoryStates(): array
    {
        return [['blocked'], ['held'], ['pending']];
    }

    #[DataProvider('inventoryStates')]
    public function test_inventory_unavailability_omits_a_track_even_while_publication_readiness_is_intact(string $state): void
    {
        ExclusiveSelectionFixtures::configure();
        PricingFixtures::configure(null);
        $fixture = ExclusiveSelectionFixtures::active();
        $catalog = app(PublicCatalog::class);
        $this->assertSame([$this->link($fixture)], $catalog->relatedLinks([$fixture['track']->id]));
        if ($state === 'blocked') {
            app(DeactivateOffer::class)->handle($fixture['offer'], $fixture['actor']);
            app(ManageRightsScope::class)->block($fixture['scope']->id, true, 0, 'TEST-RELATED-BLOCK', $fixture['actor']);
        } else {
            $quote = ExclusiveSelectionFixtures::quote($fixture);
            $service = app(ReservePricedQuote::class);
            $held = $service->hold($quote->public_id, InventoryFixtures::OWNER);
            $this->assertSame('held', $held['reservation']->state);
            if ($state === 'pending') {
                $attempt = (string) Str::uuid();
                $service->beginAttempt($quote->public_id, InventoryFixtures::OWNER, $attempt);
                $this->travelTo($held['reservation']->expires_at->addSecond());
                $this->assertSame('pending', $held['reservation']->refresh()->state);
                $this->assertSame($attempt, $held['reservation']->attempt_id);
            }
        }
        $this->assertSame([], app(PublicationReadiness::class)->blockers($fixture['track']->fresh()));
        $this->assertFalse(app(SelectionInventory::class)->available($fixture['legacy']['revision']->id));
        $before = $this->retainedEvidence();
        $this->assertSame([], $catalog->relatedLinks([$fixture['track']->id]));
        $this->assertSame([], $catalog->relatedLinks([$fixture['track']->id], false));
        $this->assertSame([], $catalog->relatedChoices());
        $this->assertSame($before, $this->retainedEvidence());
    }

    public function test_a_genuinely_finalized_exclusive_sale_omits_the_ready_legacy_track_without_rewriting_evidence(): void
    {
        PaymentFixtures::configure();
        Queue::fake();
        config(['payments.stripe.finalization_enabled' => true,
            'payments.stripe.finalization_policy' => json_encode(FinalizationPolicy::CONTRACT, JSON_THROW_ON_ERROR)]);
        $gateway = PaymentFixtures::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $gateway);
        $this->app->instance(StripePaymentGateway::class, $gateway);
        $fixture = PaymentFixtures::started($gateway, true);
        $this->assertSame('awaiting_finalization', app(VerifyTestPayment::class)->reconcile($fixture['intent']));
        $payment = VerifiedPayment::where('order_id', $fixture['order']->id)->sole();
        $this->assertSame('paid', app(FinalizeTestPayment::class)->handle($payment->id));
        app(DeactivateOffer::class)->handle($fixture['offer'], $fixture['actor']);
        $this->assertSame([], app(PublicationReadiness::class)->blockers($fixture['track']->fresh()));
        $this->assertFalse(app(SelectionInventory::class)->available($fixture['legacy']['revision']->id));
        $this->assertDatabaseCount('exclusive_sales', 1);
        $this->assertDatabaseCount('license_grants', 1);
        $this->assertDatabaseHas('inventory_reservations', ['quote_id' => $fixture['quote']->id, 'state' => 'consumed']);
        $before = $this->retainedEvidence();
        $this->assertSame([], app(PublicCatalog::class)->relatedLinks([$fixture['track']->id]));
        $this->assertSame([], app(PublicCatalog::class)->relatedChoices());
        $this->assertSame($before, $this->retainedEvidence());
    }

    private function rename(array $fixture, string $title, string $artist): void
    {
        app(SaveTrackMetadata::class)->handle($fixture['track'], [
            'title' => $title, 'artist' => $artist, 'metadata_version' => $fixture['track']->fresh()->metadata_version,
        ], $fixture['actor']);
        $fixture['track']->refresh();
    }

    private function link(array $fixture): array
    {
        return ['title' => $fixture['track']->title, 'artist' => $fixture['track']->artist,
            'href' => route('tracks.show', $fixture['track']->slug, false)];
    }

    private function retainedEvidence(): array
    {
        $evidence = [];
        foreach (['tracks', 'rights_declarations', 'media_assets', 'media_processing_runs', 'offers', 'offer_revisions',
            'rights_scopes', 'rights_scope_offers', 'exclusive_activations', 'quotes', 'quote_lines', 'quote_pricings',
            'inventory_reservations', 'inventory_claims', 'orders', 'order_lines', 'order_attempts', 'verified_payments',
            'order_finalizations', 'exclusive_sales', 'license_grants', 'pending_entitlements', 'fulfillment_outbox', 'audit_events'] as $table) {
            $evidence[$table] = json_encode(DB::table($table)->orderBy('id')->get(), JSON_THROW_ON_ERROR);
        }

        return $evidence;
    }
}
