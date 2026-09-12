<?php

namespace Tests\Feature;

use App\Domain\Catalog\PublishOffer;
use App\Domain\Catalog\PublishTrack;
use App\Domain\Catalog\SaveOfferDraft;
use App\Domain\Commerce\Models\Quote;
use App\Domain\Commerce\ReadQuoteDisclosure;
use App\Domain\Rights\LicenseSourceVariables;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\EconomicLicenseFixtures;
use Tests\Support\LicenseFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class QuoteDisclosureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
    }

    private function create(array $items, ?string $key = null): TestResponse
    {
        return $this->postJson('/quotes', ['items' => $items], ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    private function assertPrivate(TestResponse $response): void
    {
        $response->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Vary', 'Cookie');
    }

    public static function schemas(): array
    {
        return [['legacy'], ['economic']];
    }

    #[DataProvider('schemas')]
    public function test_disclosure_is_bound_to_the_owned_frozen_quote_and_hashes_only_public_fields(string $schema): void
    {
        $selection = QuoteFixtures::selection();
        if ($schema === 'economic') {
            $license = LicenseFixtures::published($selection['actor'], terms: EconomicLicenseFixtures::terms(), content: ['authored_source' => EconomicLicenseFixtures::source()]);
            $offer = app(SaveOfferDraft::class)->handle($selection['offer'], ['license_version_id' => $license->id], $selection['actor']);
            $selection['revision'] = app(PublishOffer::class)->handle($offer, $selection['actor']);
            $selection['items'][0]['offerRevisionId'] = $selection['revision']->id;
            $selection['items'][0]['licenseVersionId'] = $license->id;
        }
        $key = (string) Str::uuid();
        $review = $this->create($selection['items'], $key)->assertOk()->json('quote');
        $url = $review['items'][0]['licenseUrl'];
        $this->assertStringEndsWith('/quotes/'.$review['id'].'/offers/'.$selection['revision']->id.'/license', $url);
        $stored = Quote::sole();
        $snapshot = $stored->snapshot;
        $response = $this->getJson($url, ['X-Inertia' => 'true'])->assertOk();
        $this->assertPrivate($response);
        $data = $response->json();
        $this->assertSame(['disclosureSchema', 'quoteId', 'expiresAt', 'offerId', 'offerRevisionId', 'licenseVersionId', 'name', 'version', 'type', 'features', 'deliverableRoles', 'termsText', 'disclosureHash'], array_keys($data));
        $this->assertSame(1, $data['disclosureSchema']);
        $this->assertSame($review['id'], $data['quoteId']);
        $this->assertSame($review['expiresAt'], $data['expiresAt']);
        $this->assertSame((string) $selection['offer']->id, $data['offerId']);
        $this->assertSame((string) $selection['revision']->id, $data['offerRevisionId']);
        $license = $snapshot['lines'][0]['offer_snapshot']['license'];
        $text = $schema === 'legacy' ? $license['authored_source'] : app(LicenseSourceVariables::class)->render($license['authored_source'], $license['structured_terms']);
        $this->assertSame($text, $data['termsText']);
        $hash = $data['disclosureHash'];
        unset($data['disclosureHash']);
        $this->assertSame(CanonicalJson::hash($data), $hash);
        foreach ([$stored->owner_key, $stored->snapshot_hash, $selection['revision']->snapshot_hash, $selection['media']['master_wav']->storage_path, $license['approval_reference'], $license['review_evidence_hash']] as $private) {
            $response->assertDontSee($private, false);
        }
        $this->create($selection['items'], $key)->assertOk()->assertJsonPath('quote.items.0.licenseUrl', $url);
        $this->getJson('/quotes/'.$review['id'])->assertOk()->assertJsonPath('quote.items.0.licenseUrl', $url)->assertDontSee('termsText', false);
        $this->getJson($url)->assertOk()->assertExactJson($response->json());
        $this->assertSame($snapshot, $stored->refresh()->snapshot);
        $this->assertDatabaseCount('quotes', 1);
    }

    public function test_another_session_or_authenticated_context_cannot_read_a_quote_disclosure(): void
    {
        $selection = QuoteFixtures::selection();
        $review = $this->create($selection['items'])->assertOk()->json('quote');
        $url = $review['items'][0]['licenseUrl'];
        $this->getJson($url)->assertOk();
        $this->flushSession();
        $foreign = $this->getJson($url)->assertNotFound()->assertJsonPath('code', 'QUOTE_NOT_FOUND');
        $unknown = $this->getJson('/quotes/'.Str::uuid().'/offers/'.$selection['revision']->id.'/license')->assertNotFound();
        $this->assertSame($foreign->json(), $unknown->json());
        $this->assertPrivate($foreign);
        $own = $this->create($selection['items'])->assertOk()->json('quote.items.0.licenseUrl');
        $this->actingAs(User::factory()->create())->getJson($own)->assertNotFound();
    }

    public function test_an_owned_quote_cannot_disclose_an_unselected_offer_revision(): void
    {
        $selection = QuoteFixtures::selection();
        $other = QuoteFixtures::selection();
        $id = $this->create($selection['items'])->assertOk()->json('quote.id');
        $response = $this->getJson('/quotes/'.$id.'/offers/'.$other['revision']->id.'/license')->assertNotFound()->assertJsonPath('code', 'QUOTE_NOT_FOUND');
        $response->assertDontSee('termsText', false);
        $this->assertPrivate($response);
    }

    public function test_mutable_drafts_cannot_change_terms_and_successors_require_a_new_review(): void
    {
        $selection = QuoteFixtures::selection();
        $url = $this->create($selection['items'])->assertOk()->json('quote.items.0.licenseUrl');
        $before = $this->getJson($url)->assertOk()->json();
        $snapshot = Quote::sole()->snapshot;
        $license = LicenseFixtures::published($selection['actor'], content: ['authored_source' => 'NONBINDING successor test terms.']);
        $offer = app(SaveOfferDraft::class)->handle($selection['offer'], ['license_version_id' => $license->id, 'price_minor' => 2500], $selection['actor']);
        $this->getJson($url)->assertOk()->assertExactJson($before);
        app(PublishOffer::class)->handle($offer, $selection['actor']);
        $changed = $this->getJson($url)->assertConflict()->assertJsonPath('code', 'SELECTION_CHANGED')->assertDontSee('termsText', false);
        $this->assertPrivate($changed);
        $this->assertSame($snapshot, Quote::sole()->snapshot);
    }

    public static function unavailable(): array
    {
        return [['expiry'], ['withdrawal'], ['missing media']];
    }

    #[DataProvider('unavailable')]
    public function test_expired_or_unavailable_quotes_never_return_disclosure_as_current(string $reason): void
    {
        $selection = QuoteFixtures::selection();
        $url = $this->create($selection['items'])->assertOk()->json('quote.items.0.licenseUrl');
        match ($reason) {
            'expiry' => $this->travelTo(Quote::sole()->expires_at),
            'withdrawal' => app(PublishTrack::class)->unpublish($selection['track'], $selection['actor']),
            'missing media' => Storage::disk('local')->delete($selection['media']['master_wav']->storage_path),
        };
        $response = $this->getJson($url)->assertStatus($reason === 'expiry' ? 410 : 409)->assertJsonPath('code', $reason === 'expiry' ? 'QUOTE_EXPIRED' : 'SELECTION_CHANGED')->assertDontSee('termsText', false);
        $this->assertPrivate($response);
    }

    public function test_disclosure_shares_the_read_rate_limit_and_generic_private_error_boundary(): void
    {
        for ($index = 0; $index < 60; $index++) {
            $this->getJson('/quotes/'.Str::uuid())->assertNotFound();
        }
        $limited = $this->getJson('/quotes/'.Str::uuid().'/offers/1/license')->assertStatus(429)->assertJsonPath('code', 'RATE_LIMITED')->assertHeader('Retry-After');
        $this->assertPrivate($limited);
    }

    public function test_unexpected_disclosure_failure_stays_private_even_in_debug_mode(): void
    {
        config(['app.debug' => true]);
        $this->app->bind(ReadQuoteDisclosure::class, fn () => throw new RuntimeException('private/master.wav owner-secret'));
        $failed = $this->getJson('/quotes/'.Str::uuid().'/offers/1/license')->assertStatus(500)->assertJsonPath('code', 'QUOTE_UNAVAILABLE');
        $failed->assertDontSee('owner-secret')->assertDontSee('master.wav');
        $this->assertPrivate($failed);
    }
}
