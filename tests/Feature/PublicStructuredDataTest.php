<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublishTrack;
use App\Domain\Catalog\SyntheticRehearsal\SyntheticRehearsalCatalog;
use App\Domain\Media\MalwareScanner;
use App\Domain\SiteBuilder\SiteContent;
use App\Http\Middleware\HandleInertiaRequests;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Tests\Support\LicenseFixtures;
use Tests\Support\QuoteFixtures;
use Tests\Support\SiteEditorialFixtures;
use Tests\TestCase;

class PublicStructuredDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        config(['app.url' => 'https://audio.example.test', 'app.debug' => false]);
    }

    /** Fixtures refuse production, so each test builds its data first and then switches the running environment. */
    private function production(): void
    {
        $this->app->instance('env', 'production');
    }

    /** Production accepts only ClamAV scan evidence, so the fixture media carry a synthetic host-scan stand-in. */
    private function selection(int $priceMinor = 4999): array
    {
        return QuoteFixtures::selection($priceMinor, new class extends MalwareScanner
        {
            public function scan(string $path): array
            {
                return ['engine' => 'clamav', 'version' => 'synthetic-host-scan-stand-in', 'status' => 'clean', 'sha256' => hash_file('sha256', $path)];
            }
        });
    }

    private function inertia(): array
    {
        return ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/'))];
    }

    /** @return list<string> the text of every JSON-LD block in the document head */
    private function blocks(TestResponse $response): array
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML($response->getContent());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($dom);
        $this->assertCount(0, $xpath->query('//script[not(@src) and not(@type="application/ld+json") and not(@type="application/json")]'), 'no executable inline script');
        $blocks = [];
        foreach ($xpath->query('//script[@type="application/ld+json"]') as $node) {
            $this->assertSame('structured-data', $node->getAttribute('data-inertia'));
            $this->assertSame('head', $node->parentNode->nodeName);
            $blocks[] = $node->textContent;
        }

        return $blocks;
    }

    private function graph(TestResponse $html, string $path): array
    {
        $blocks = $this->blocks($html);
        $this->assertCount(1, $blocks);
        $this->assertSame($blocks[0], $this->get($path, $this->inertia())->assertOk()->json('props.metadata.structuredData'));
        $this->assertMatchesRegularExpression('/\A[\x20-\x7E]+\z/', $blocks[0]);
        $data = json_decode($blocks[0], true, 16, JSON_THROW_ON_ERROR);
        $this->assertSame('https://schema.org', $data['@context']);
        $this->assertStringNotContainsString('VASEY/AI', $blocks[0]);

        return $data['@graph'];
    }

    public function test_home_describes_the_vasey_audio_organization_and_website_on_the_canonical_origin(): void
    {
        $this->production();
        $graph = $this->graph($this->get('https://attacker.example/?utm_source=x')->assertOk(), '/');
        $this->assertSame([
            ['@type' => 'Organization', '@id' => 'https://audio.example.test/#organization', 'name' => 'VASEY.AUDIO',
                'url' => 'https://audio.example.test/', 'logo' => 'https://audio.example.test/brand/vasey-audio-logo.png',
                'parentOrganization' => ['@type' => 'Organization', 'name' => 'Sean Vasey Productions']],
            ['@type' => 'WebSite', '@id' => 'https://audio.example.test/#website', 'name' => 'VASEY.AUDIO', 'url' => 'https://audio.example.test/',
                'description' => $this->get('/', $this->inertia())->json('props.metadata.description'),
                'publisher' => ['@id' => 'https://audio.example.test/#organization']],
        ], $graph);
        $this->assertFileExists(public_path('brand/vasey-audio-logo.png'));
    }

    public function test_a_public_track_is_a_music_recording_with_breadcrumbs_and_no_price_or_offer_claims(): void
    {
        $selection = $this->selection(4321);
        $selection['track']->update(['title' => 'Night Signal', 'artist' => 'Example Artist']);
        $path = '/tracks/'.$selection['track']->slug;
        $this->production();
        $html = $this->get($path.'?utm_campaign=x')->assertOk();
        $graph = $this->graph($html, $path);
        $url = 'https://audio.example.test'.$path;
        $this->assertSame([
            ['@type' => 'MusicRecording', '@id' => $url.'#recording', 'name' => 'Night Signal', 'url' => $url,
                'byArtist' => ['@type' => 'MusicGroup', 'name' => 'Example Artist'], 'genre' => 'Test',
                'image' => 'https://audio.example.test/media/'.$selection['media']['artwork']->id],
            ['@type' => 'BreadcrumbList', 'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'VASEY.AUDIO', 'item' => 'https://audio.example.test/'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Night Signal', 'item' => $url],
            ]],
        ], $graph);
        $block = $this->blocks($html)[0];
        foreach (['offer', 'Offer', 'price', '4321', '43.21', 'USD', 'availability', $selection['media']['master_wav']->storage_path] as $absent) {
            $this->assertStringNotContainsString($absent, $block);
        }
    }

    public function test_json_ld_cannot_close_its_script_element_or_inject_markup(): void
    {
        $selection = $this->selection();
        $title = "Écho </script><script>window.pwned=1</script> <!-- & 'q' \"d\" \u{2028}end";
        $selection['track']->update(['title' => $title, 'artist' => '</SCRIPT ><img src=x onerror=alert(1)>']);
        $path = '/tracks/'.$selection['track']->slug;
        $this->production();
        $html = $this->get($path)->assertOk();
        $graph = $this->graph($html, $path);
        $this->assertSame(trim(preg_replace('/\s+/u', ' ', $title)), $graph[0]['name']);
        $this->assertSame('</SCRIPT ><img src=x onerror=alert(1)>', $graph[0]['byArtist']['name']);
        $raw = $html->getContent();
        $start = strpos($raw, '<script data-inertia="structured-data"');
        $end = strpos($raw, '</script>', $start);
        $element = substr($raw, $start, $end - $start);
        foreach (['<script>', '</', '<!--', '<img', '&'] as $markup) {
            $this->assertStringNotContainsString($markup, substr($element, strlen('<script data-inertia="structured-data" type="application/ld+json">')));
        }
        $this->assertStringNotContainsString('window.pwned=1</script>', $raw);
    }

    public function test_blog_entries_are_blog_postings_and_other_editorial_pages_carry_no_structured_data(): void
    {
        $actor = LicenseFixtures::admin();
        $service = app(SiteContent::class);
        $service->publish($service->create(SiteEditorialFixtures::content('LIVE'), 'LIVE LABEL', $actor)->id, 0, $actor);
        $this->production();
        $graph = $this->graph($this->get('/blog/first-note')->assertOk(), '/blog/first-note');
        $url = 'https://audio.example.test/blog/first-note';
        $organization = $this->graph($this->get('/')->assertOk(), '/')[0];
        $this->assertSame([
            ['@type' => 'BlogPosting', '@id' => $url.'#article', 'headline' => 'LIVE FIRST NOTE', 'description' => 'LIVE FIRST DESCRIPTION',
                'url' => $url, 'mainEntityOfPage' => $url, 'publisher' => $organization],
            ['@type' => 'BreadcrumbList', 'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'VASEY.AUDIO', 'item' => 'https://audio.example.test/'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => SiteEditorialFixtures::content('LIVE')['blog']['title'], 'item' => 'https://audio.example.test/blog'],
                ['@type' => 'ListItem', 'position' => 3, 'name' => 'LIVE FIRST NOTE', 'item' => $url],
            ]],
        ], $graph);
        foreach (['/about', '/contact', '/blog', '/videos', '/videos/first-film'] as $path) {
            $this->assertSame([], $this->blocks($this->get($path)->assertOk()), $path);
            $this->assertNull($this->get($path, $this->inertia())->assertOk()->json('props.metadata.structuredData'), $path);
        }
    }

    public function test_drafts_unpublished_ineligible_synthetic_and_preview_content_never_get_structured_data(): void
    {
        $actor = LicenseFixtures::admin();
        $draft = app(SiteContent::class)->create(SiteEditorialFixtures::content('DRAFT ONLY'), 'DRAFT LABEL', $actor);
        $track = Track::create(['title' => 'Private draft name', 'artist' => 'Private artist', 'slug' => 'private-draft']);
        $withdrawn = $this->selection();
        $path = '/tracks/'.$withdrawn['track']->slug;
        $synthetic = $this->selection();
        $synthetic['track']->update(['tags' => SyntheticRehearsalCatalog::TAGS]);
        $syntheticPath = '/tracks/'.$synthetic['track']->slug;
        $this->production();

        $this->get('/blog/first-note')->assertNotFound()->assertDontSee('application/ld+json', false)->assertDontSee('DRAFT ONLY', false);
        $this->get('/tracks/'.$track->slug)->assertNotFound()->assertDontSee('application/ld+json', false)->assertDontSee($track->title);

        $this->assertCount(1, $this->blocks($this->get($path)->assertOk()));
        app(PublishTrack::class)->unpublish($withdrawn['track'], $withdrawn['actor']);
        $this->get($path)->assertNotFound()->assertDontSee('application/ld+json', false);

        $this->assertSame([], $this->blocks($this->get($syntheticPath)->assertOk()));
        $this->assertNull($this->get($syntheticPath, $this->inertia())->json('props.metadata.structuredData'));
        $synthetic['track']->update(['tags' => [], 'title' => SyntheticRehearsalCatalog::TITLE_PREFIX.' Rehearsal']);
        $this->assertSame([], $this->blocks($this->get($syntheticPath)->assertOk()));
        $synthetic['track']->update(['title' => 'Plain title', 'artist' => SyntheticRehearsalCatalog::ARTIST]);
        $this->assertSame([], $this->blocks($this->get($syntheticPath)->assertOk()));

        // The staff preview of a draft release renders the same page components without any structured data.
        $this->actingAs($actor);
        foreach (['', '/blog/first-note'] as $suffix) {
            $response = $this->get('/admin/site-releases/'.$draft->id.'/preview'.$suffix, $this->inertia())->assertOk();
            $response->assertDontSee('application/ld+json', false)->assertJsonPath('props.sitePreview', true);
            $this->assertArrayNotHasKey('structuredData', $response->json('props.metadata'));
            $this->assertSame([], $this->blocks($this->get('/admin/site-releases/'.$draft->id.'/preview'.$suffix)->assertOk()));
        }
    }

    public function test_outside_production_no_page_carries_structured_data(): void
    {
        $selection = $this->selection();
        foreach (['local', 'testing', 'staging'] as $environment) {
            $this->app->instance('env', $environment);
            foreach (['/', '/tracks/'.$selection['track']->slug] as $path) {
                $this->assertSame([], $this->blocks($this->get($path)->assertOk()), $environment.$path);
                $this->assertNull($this->get($path, $this->inertia())->assertOk()->json('props.metadata.structuredData'));
            }
        }
    }
}
