<?php

namespace Tests\Feature;

use App\Domain\Catalog\DeactivateOffer;
use App\Domain\Catalog\PublishOffer;
use App\Domain\Catalog\PublishTrack;
use App\Domain\Catalog\SaveOfferDraft;
use App\Domain\Rights\LicenseSourceVariables;
use App\Domain\Rights\Models\RightsDeclaration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EconomicLicenseFixtures;
use Tests\Support\LicenseFixtures;
use Tests\Support\QuoteFixtures;
use Tests\Support\ScopedLicenseFixtures;
use Tests\Support\TypedLicenseFixtures;
use Tests\TestCase;

class PublicLicenseDisclosureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        config(['app.debug' => false]);
    }

    private function url(array $selection): string
    {
        return '/tracks/'.$selection['track']->slug.'/offers/'.$selection['revision']->id.'/license';
    }

    public static function schemas(): array
    {
        return [['v1'], ['v2'], ['v3'], ['v4']];
    }

    #[DataProvider('schemas')]
    public function test_public_disclosure_uses_exact_frozen_source_and_excludes_internal_evidence(string $schema): void
    {
        $selection = QuoteFixtures::selection();
        $fixture = match ($schema) {
            'v2' => TypedLicenseFixtures::class,
            'v3' => ScopedLicenseFixtures::class,
            'v4' => EconomicLicenseFixtures::class,
            default => null,
        };
        if ($fixture) {
            $license = LicenseFixtures::published($selection['actor'], terms: $fixture::terms(), content: ['authored_source' => $fixture::source()]);
            $offer = app(SaveOfferDraft::class)->handle($selection['offer'], ['license_version_id' => $license->id], $selection['actor']);
            $selection['revision'] = app(PublishOffer::class)->handle($offer, $selection['actor']);
        }
        $revision = $selection['revision'];
        $license = $revision->snapshot['license'];
        $expectedText = $schema === 'v1' ? $license['authored_source'] : app(LicenseSourceVariables::class)->render($license['authored_source'], $license['structured_terms']);
        $response = $this->getJson($this->url($selection), ['X-Inertia' => 'true'])->assertOk()->assertHeader('Content-Type', 'application/json');
        $response->assertExactJson([
            'offerId' => (string) $selection['offer']->id,
            'offerRevisionId' => (string) $revision->id,
            'licenseVersionId' => (string) $license['id'],
            'name' => $license['name'], 'version' => $license['version'], 'type' => $license['type'],
            'features' => $license['features'], 'deliverableRoles' => $license['required_asset_roles'],
            'termsText' => $expectedText,
        ]);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        foreach (['approval_reference', 'approved_by', 'review_evidence_id', 'review_evidence_hash', 'submission_hash', 'storage_path', 'provenance_reference'] as $privateField) {
            $response->assertDontSee($privateField, false);
        }
        foreach ([$selection['media']['master_wav']->storage_path, $license['approval_reference'], $license['review_evidence_hash'], $revision->snapshot_hash] as $privateValue) {
            $response->assertDontSee($privateValue, false);
        }
        if ($schema === 'v4') {
            foreach ($license['structured_terms']['policies'] as $policy) {
                $this->assertStringContainsString($policy['text'], $response->json('termsText'));
                $this->assertStringContainsString(hash('sha256', $policy['text']), $response->json('termsText'));
            }
        }
        // Bulk catalog/reconciliation carry a bounded URL, not full policy bodies.
        $catalog = $this->getJson('/api/catalog')->assertOk();
        $this->assertStringEndsWith($this->url($selection), $catalog->json('tracks.0.offers.0.licenseUrl'));
        $catalog->assertDontSee('termsText', false)->assertDontSee('authored_source', false)->assertDontSee('policies', false);
        $this->postJson('/catalog/selections', ['trackIds' => [(string) $selection['track']->id]])->assertOk()->assertDontSee('termsText', false)->assertDontSee('authored_source', false);
    }

    public function test_draft_edits_leave_public_terms_unchanged_and_a_successor_rejects_the_old_url(): void
    {
        $selection = QuoteFixtures::selection();
        $url = $this->url($selection);
        $original = $this->getJson($url)->assertOk()->json();
        $snapshot = $selection['revision']->snapshot;
        $successorLicense = LicenseFixtures::published($selection['actor'], content: ['authored_source' => 'NONBINDING changed test terms.']);
        $offer = app(SaveOfferDraft::class)->handle($selection['offer'], ['license_version_id' => $successorLicense->id, 'price_minor' => 12345], $selection['actor']);
        $this->getJson($url)->assertOk()->assertExactJson($original);
        $selection['revision'] = app(PublishOffer::class)->handle($offer, $selection['actor']);
        $this->getJson($url)->assertNotFound()->assertDontSee($original['termsText']);
        $this->getJson($this->url($selection))->assertOk()->assertJsonPath('termsText', 'NONBINDING changed test terms.')->assertJsonPath('licenseVersionId', (string) $successorLicense->id);
        $this->assertDatabaseHas('offer_revisions', ['id' => $original['offerRevisionId']]);
        $this->assertSame($snapshot, \App\Domain\Catalog\Models\OfferRevision::findOrFail($original['offerRevisionId'])->snapshot);
    }

    public static function unavailableCases(): array
    {
        return [['withdrawn'], ['draft'], ['inactive'], ['rights hold'], ['missing preview'], ['missing master']];
    }

    #[DataProvider('unavailableCases')]
    public function test_current_eligibility_is_rechecked_before_any_terms_are_disclosed(string $reason): void
    {
        $selection = QuoteFixtures::selection();
        $this->getJson($this->url($selection))->assertOk();
        match ($reason) {
            'withdrawn' => app(PublishTrack::class)->unpublish($selection['track'], $selection['actor']),
            'draft' => $selection['track']->update(['status' => 'draft']),
            'inactive' => app(DeactivateOffer::class)->handle($selection['offer'], $selection['actor']),
            'rights hold' => RightsDeclaration::create(['track_id' => $selection['track']->id, 'provenance_reference' => 'PRIVATE-HOLD', 'sample_disclosure' => 'Test only', 'status' => 'pending']),
            'missing preview' => Storage::disk('local')->delete($selection['media']['preview_tagged']->storage_path),
            'missing master' => Storage::disk('local')->delete($selection['media']['master_wav']->storage_path),
        };
        $this->getJson($this->url($selection))->assertNotFound()->assertDontSee($selection['revision']->snapshot['license']['authored_source']);
    }

    public function test_unknown_and_cross_track_revisions_do_not_disclose_terms(): void
    {
        $selection = QuoteFixtures::selection();
        $other = QuoteFixtures::selection();
        foreach (['9999999', 'invalid', $other['revision']->id] as $revisionId) {
            $this->getJson('/tracks/'.$selection['track']->slug.'/offers/'.$revisionId.'/license')->assertNotFound()->assertDontSee('termsText', false);
        }
        $this->getJson('/tracks/unknown-track/offers/'.$selection['revision']->id.'/license')->assertNotFound();
    }

    public function test_a_license_that_has_expired_no_longer_has_public_terms(): void
    {
        $selection = QuoteFixtures::selection();
        $license = LicenseFixtures::published($selection['actor'], content: ['effective_until' => now()->addDay()->toIso8601String()]);
        $offer = app(SaveOfferDraft::class)->handle($selection['offer'], ['license_version_id' => $license->id], $selection['actor']);
        $selection['revision'] = app(PublishOffer::class)->handle($offer, $selection['actor']);
        $this->getJson($this->url($selection))->assertOk();
        $this->travel(2)->days();
        $this->getJson($this->url($selection))->assertNotFound();
    }
}
