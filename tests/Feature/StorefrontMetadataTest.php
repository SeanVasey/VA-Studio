<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublishTrack;
use App\Domain\Rights\Models\RightsDeclaration;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class StorefrontMetadataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        config(['app.url' => 'https://audio.example.test', 'app.debug' => false]);
    }

    private function head(TestResponse $response): DOMXPath
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML($response->getContent());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new DOMXPath($dom);
    }

    private function assertMetadata(TestResponse $response, array $metadata): void
    {
        $head = $this->head($response);
        $this->assertSame($metadata['title'], $head->evaluate('string(//head/title)'));
        $this->assertSame($metadata['canonicalUrl'], $head->evaluate('string(//head/link[@rel="canonical"]/@href)'));
        foreach ([
            'description' => $metadata['description'], 'robots' => $metadata['robots'],
            'og:site_name' => 'VASEY.AUDIO', 'og:type' => $metadata['type'],
            'og:title' => $metadata['title'], 'og:description' => $metadata['description'],
            'og:url' => $metadata['canonicalUrl'], 'og:image' => $metadata['imageUrl'],
            'og:image:alt' => $metadata['imageAlt'], 'twitter:card' => 'summary_large_image',
            'twitter:title' => $metadata['title'], 'twitter:description' => $metadata['description'],
            'twitter:image' => $metadata['imageUrl'], 'twitter:image:alt' => $metadata['imageAlt'],
        ] as $key => $expected) {
            $nodes = $head->query('//head/meta[@data-inertia="'.$key.'"]');
            $this->assertCount(1, $nodes, $key);
            $this->assertSame($expected, $nodes->item(0)->getAttribute('content'), $key);
        }
    }

    public function test_initial_home_html_and_inertia_props_agree_without_invented_catalog_metadata(): void
    {
        $html = $this->get('/?utm_source=test')->assertOk();
        $page = $this->get('/', ['X-Inertia' => 'true'])->assertOk();
        $metadata = $page->json('props.metadata');
        $this->assertMetadata($html, $metadata);
        $this->assertSame('VASEY.AUDIO — Sound with intent', $metadata['title']);
        $this->assertSame('https://audio.example.test/', $metadata['canonicalUrl']);
        $this->assertSame('https://audio.example.test/images/storefront-hero.jpg', $metadata['imageUrl']);
        $this->assertSame('website', $metadata['type']);
        $this->assertSame('noindex, nofollow', $metadata['robots']);
        $page->assertJsonCount(0, 'props.tracks')->assertJsonPath('props.commerceEnabled', false);
    }

    public function test_track_html_uses_the_selected_public_track_and_retrievable_artwork(): void
    {
        $selection = QuoteFixtures::selection();
        QuoteFixtures::selection(); // The selected track need not be the first catalog row.
        $track = $selection['track'];
        $track->update(['title' => 'Night Signal', 'artist' => 'Synthetic Artist']);
        $path = '/tracks/'.$track->slug;
        $html = $this->get($path.'?utm_campaign=test')->assertOk();
        $page = $this->get($path, ['X-Inertia' => 'true'])->assertOk();
        $metadata = $page->json('props.metadata');
        $this->assertMetadata($html, $metadata);
        $this->assertSame('Night Signal by Synthetic Artist — VASEY.AUDIO', $metadata['title']);
        $this->assertSame('https://audio.example.test'.$path, $metadata['canonicalUrl']);
        $this->assertSame('music.song', $metadata['type']);
        $this->assertStringContainsString('90 BPM', $metadata['description']);
        $this->assertSame('https://audio.example.test/media/'.$selection['media']['artwork']->id, $metadata['imageUrl']);
        $this->get('/media/'.$selection['media']['artwork']->id)->assertOk()->assertHeader('Content-Type', 'image/png');
        foreach ([$selection['media']['master_wav']->storage_path, $selection['media']['master_wav']->sha256, $selection['revision']->snapshot_hash, $selection['revision']->snapshot['license']['authored_source']] as $private) {
            $html->assertDontSee($private, false);
            $page->assertDontSee($private, false);
        }
    }

    public function test_host_headers_and_tracking_queries_cannot_change_canonical_or_image_identity(): void
    {
        $selection = QuoteFixtures::selection();
        $path = '/tracks/'.$selection['track']->slug;
        $response = $this->get('https://untrusted.example'.$path.'?url=https://untrusted.example&token=tracking-only')->assertOk();
        $head = $this->head($response);
        $this->assertSame('https://audio.example.test'.$path, $head->evaluate('string(//head/link[@rel="canonical"]/@href)'));
        $this->assertSame('https://audio.example.test'.$path, $head->evaluate('string(//head/meta[@property="og:url"]/@content)'));
        $this->assertSame('https://audio.example.test/media/'.$selection['media']['artwork']->id, $head->evaluate('string(//head/meta[@property="og:image"]/@content)'));
    }

    public function test_metadata_escapes_markup_quotes_and_unicode_without_executable_head_content(): void
    {
        $selection = QuoteFixtures::selection();
        $selection['track']->update(['title' => 'Écho & "Keys"><meta name="injected" content="yes"><script>window.pwned=1</script>', 'artist' => 'A & B']);
        $path = '/tracks/'.$selection['track']->slug;
        $html = $this->get($path)->assertOk();
        $metadata = $this->get($path, ['X-Inertia' => 'true'])->assertOk()->json('props.metadata');
        $this->assertMetadata($html, $metadata);
        $head = $this->head($html);
        $this->assertCount(0, $head->query('//head/meta[@name="injected"] | //head/script | //head/*[@onload]'));
        $this->assertStringContainsString('Écho & "Keys"', $metadata['title']);
        $this->assertLessThanOrEqual(200, mb_strlen($metadata['description']));
    }

    public function test_draft_withdrawn_and_unknown_tracks_have_no_track_metadata(): void
    {
        $draft = Track::create(['title' => 'Private draft name', 'artist' => 'Private artist', 'slug' => 'private-draft']);
        foreach (['/tracks/'.$draft->slug, '/tracks/does-not-exist'] as $path) {
            $this->get($path)->assertNotFound()->assertDontSee('og:title', false)->assertDontSee($draft->title);
            $this->get($path, ['X-Inertia' => 'true'])->assertNotFound()->assertDontSee($draft->title);
        }
        $selection = QuoteFixtures::selection();
        $path = '/tracks/'.$selection['track']->slug;
        $this->get($path)->assertOk();
        app(PublishTrack::class)->unpublish($selection['track'], $selection['actor']);
        $this->get($path)->assertNotFound()->assertDontSee('og:title', false)->assertDontSee($selection['track']->title);
        $this->get('/media/'.$selection['media']['artwork']->id)->assertNotFound();
    }

    public function test_new_rights_hold_removes_track_metadata_even_while_the_track_is_marked_published(): void
    {
        $selection = QuoteFixtures::selection();
        RightsDeclaration::create(['track_id' => $selection['track']->id, 'provenance_reference' => 'PRIVATE-PENDING-REVIEW', 'sample_disclosure' => 'Test only', 'status' => 'pending']);
        $this->get('/tracks/'.$selection['track']->slug)->assertNotFound()->assertDontSee('og:title', false);
        $this->get('/', ['X-Inertia' => 'true'])->assertOk()->assertJsonCount(0, 'props.tracks')->assertJsonPath('props.metadata.type', 'website');
    }

    public function test_missing_artwork_never_produces_an_advertised_track_share_image(): void
    {
        $selection = QuoteFixtures::selection();
        Storage::disk('local')->delete($selection['media']['artwork']->storage_path);
        $this->get('/tracks/'.$selection['track']->slug)->assertNotFound()->assertDontSee('og:image', false);
        $this->get('/media/'.$selection['media']['artwork']->id)->assertNotFound();
    }
}
