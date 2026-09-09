<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublicCatalog;
use App\Domain\Catalog\PublishTrack;
use App\Domain\Rights\Models\RightsDeclaration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class CatalogPaginationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
    }

    private function api(string $url): string
    {
        return str_replace('/?', '/api/catalog?', $url);
    }

    public function test_pages_round_trip_without_duplicates_and_direct_links_resolve_off_page(): void
    {
        $ids = [];
        for ($i = 0; $i < 13; $i++) {
            $selection = QuoteFixtures::selection();
            $ids[] = (string) $selection['track']->id;
            $oldest ??= $selection['track'];
        }
        DB::table('tracks')->update(['published_at' => '2026-09-01 00:00:00']);
        DB::enableQueryLog();
        $first = $this->getJson('/api/catalog?per_page=100000')->assertOk()->assertJsonCount(12, 'tracks');
        $query = collect(DB::getQueryLog())->first(fn ($query) => str_contains($query['query'], 'order by'))['query'];
        DB::disableQueryLog();
        $this->assertStringContainsString('limit 49', $query);
        $this->assertStringNotContainsString('offset', $query);
        $this->assertSame(array_slice(array_reverse($ids), 0, 12), array_column($first->json('tracks'), 'id'));
        $first->assertJsonPath('catalogPage.previousUrl', null);
        $second = $this->getJson($this->api($first->json('catalogPage.nextUrl')))->assertOk()->assertJsonCount(1, 'tracks');
        $second->assertJsonPath('tracks.0.id', $ids[0])->assertJsonPath('catalogPage.nextUrl', null);
        $again = $this->getJson($this->api($second->json('catalogPage.previousUrl')))->assertOk();
        $this->assertSame($first->json('tracks'), $again->json('tracks'));
        $again->assertJsonPath('catalogPage.previousUrl', null);
        $detail = $this->get('/tracks/'.$oldest->slug, ['X-Inertia' => 'true'])->assertOk();
        $detail->assertJsonCount(12, 'props.tracks')->assertJsonPath('props.selectedTrack.id', (string) $oldest->id)->assertJsonPath('props.metadata.type', 'music.song');
        $this->postJson('/catalog/selections', ['trackIds' => [$oldest->id]])->assertOk()->assertJsonCount(1, 'tracks')->assertJsonPath('tracks.0.id', (string) $oldest->id);
    }

    public function test_ineligible_batches_are_bounded_private_and_can_be_traversed_in_both_directions(): void
    {
        $eligible = QuoteFixtures::selection()['track'];
        $rows = [];
        for ($i = 0; $i < PublicCatalog::SCAN_LIMIT + 3; $i++) {
            $rows[] = ['title' => 'PRIVATE-UNREADY-'.$i, 'slug' => 'unready-'.$i, 'status' => 'published', 'genre' => 'PRIVATE-GENRE', 'published_at' => now()->addDay()];
        }
        // Simulate stale publication flags. None has eligible rights/media/offers.
        DB::table('tracks')->insert($rows);
        $first = $this->getJson('/api/catalog')->assertOk()->assertJsonCount(0, 'tracks')->assertJsonCount(0, 'licenseTiers')->assertDontSee('PRIVATE-')->assertDontSee('total');
        $this->assertNotNull($first->json('catalogPage.nextUrl'));
        parse_str(parse_url($first->json('catalogPage.nextUrl'), PHP_URL_QUERY), $query);
        $this->assertStringNotContainsString('unready', base64_decode($query['cursor']));
        $second = $this->getJson($this->api($first->json('catalogPage.nextUrl')))->assertOk()->assertJsonCount(1, 'tracks')->assertJsonPath('tracks.0.id', (string) $eligible->id);
        $previous = $this->getJson($this->api($second->json('catalogPage.previousUrl')))->assertOk()->assertJsonCount(0, 'tracks');
        $this->assertNotNull($previous->json('catalogPage.nextUrl'));
        $this->getJson($this->api($previous->json('catalogPage.nextUrl')))->assertOk()->assertJsonPath('tracks.0.id', (string) $eligible->id);
    }

    public function test_search_and_sort_are_server_side_and_wildcards_are_literal(): void
    {
        $a = QuoteFixtures::selection()['track'];
        $b = QuoteFixtures::selection()['track'];
        $c = QuoteFixtures::selection()['track'];
        $a->update(['title' => 'Zulu 100%_!', 'bpm' => 100, 'genre' => 'Jazz', 'mood' => 'Soft', 'tags' => ['Piano']]);
        $b->update(['title' => 'Alpha', 'bpm' => 80, 'genre' => 'Jazz', 'mood' => 'Bright']);
        $c->update(['title' => 'Alpha', 'bpm' => 80, 'genre' => 'Trap']);
        $byTitle = $this->getJson('/api/catalog?sort=title')->assertOk();
        $this->assertSame([(string) $b->id, (string) $c->id, (string) $a->id], array_column($byTitle->json('tracks'), 'id'));
        $byTempo = $this->getJson('/api/catalog?sort=tempo&genre=Jazz')->assertOk();
        $this->assertSame([(string) $b->id, (string) $a->id], array_column($byTempo->json('tracks'), 'id'));
        $this->getJson('/api/catalog?'.http_build_query(['q' => 'piano soft', 'genre' => 'Jazz']))->assertJsonCount(1, 'tracks')->assertJsonPath('tracks.0.id', (string) $a->id);
        $this->getJson('/api/catalog?'.http_build_query(['q' => '%_!']))->assertJsonCount(1, 'tracks')->assertJsonPath('tracks.0.id', (string) $a->id);
        $this->getJson('/api/catalog?q=absent')->assertJsonCount(0, 'tracks');
    }

    public function test_tampered_or_cross_filter_cursors_and_invalid_filters_are_rejected(): void
    {
        $rows = [];
        for ($i = 0; $i < 49; $i++) {
            $rows[] = ['title' => 'Unavailable', 'slug' => 'unavailable-'.$i, 'status' => 'published', 'published_at' => now()];
        }
        DB::table('tracks')->insert($rows);
        $page = $this->getJson('/api/catalog')->assertOk();
        $next = $this->api($page->json('catalogPage.nextUrl'));
        $this->getJson($next.'&q=changed')->assertUnprocessable()->assertJsonValidationErrors('cursor');
        foreach (['cursor=forged', 'cursor[]=array', 'sort=arbitrary', 'q[]=array', 'q='.str_repeat('x', 101), 'genre='.str_repeat('x', 81)] as $invalid) {
            $this->getJson('/api/catalog?'.$invalid)->assertUnprocessable();
        }
    }

    public function test_selection_lookup_returns_only_current_public_projection_and_no_private_evidence(): void
    {
        $selection = QuoteFixtures::selection();
        $track = $selection['track'];
        $selection['offer']->update(['price_minor' => 1]);
        $this->postJson('/catalog/selections', ['trackIds' => [(string) $track->id]])->assertOk()->assertJsonPath('tracks.0.offers.0.priceMinor', 4999)
            ->assertHeader('Cache-Control', 'no-store, private')->assertDontSee('storage_path')->assertDontSee('authored_source')->assertDontSee('approval_reference')->assertDontSee('fixtures/');
        RightsDeclaration::create(['track_id' => $track->id, 'status' => 'pending', 'provenance_reference' => 'PRIVATE-HOLD', 'sample_disclosure' => 'Private']);
        $this->postJson('/catalog/selections', ['trackIds' => [$track->id]])->assertOk()->assertExactJson(['tracks' => [], 'licenseTiers' => []]);
        $draft = Track::create(['title' => 'Secret draft', 'slug' => 'secret-draft']);
        $this->postJson('/catalog/selections', ['trackIds' => [$draft->id, 999999]])->assertOk()->assertExactJson(['tracks' => [], 'licenseTiers' => []]);
    }

    public function test_withdrawal_and_missing_media_remove_only_the_affected_saved_record(): void
    {
        $a = QuoteFixtures::selection();
        $b = QuoteFixtures::selection();
        app(PublishTrack::class)->unpublish($a['track'], $a['actor']);
        $ids = [$a['track']->id, $b['track']->id];
        $this->postJson('/catalog/selections', ['trackIds' => $ids])->assertOk()->assertJsonCount(1, 'tracks')->assertJsonPath('tracks.0.id', (string) $b['track']->id);
        Storage::disk('local')->delete($b['media']['master_wav']->storage_path);
        $this->postJson('/catalog/selections', ['trackIds' => $ids])->assertOk()->assertJsonCount(0, 'tracks');
    }

    public function test_selection_request_is_bounded_and_validated(): void
    {
        foreach ([[], [1, 1], range(1, 11), [0], [-1], ['abc'], [1.5], [['nested']], ['9007199254740992']] as $ids) {
            $this->postJson('/catalog/selections', ['trackIds' => $ids])->assertUnprocessable();
        }
        $this->getJson('/catalog/selections')->assertMethodNotAllowed();
        $this->postJson('/catalog/selections', ['trackIds' => [999999]])->assertOk()->assertExactJson(['tracks' => [], 'licenseTiers' => []]);
    }
}
