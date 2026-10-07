<?php

namespace Tests\Feature;

use App\Domain\Catalog\PublishTrack;
use App\Domain\Commerce\Models\Quote;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\PriceQuote;
use App\Domain\SiteBuilder\SiteContent;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\CheckoutFixtures;
use Tests\Support\InventoryFixtures;
use Tests\Support\LicenseFixtures;
use Tests\Support\OrderFixtures;
use Tests\Support\QuoteFixtures;
use Tests\Support\SiteEditorialFixtures;
use Tests\TestCase;

class PublicInstallMetadataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        config(['app.debug' => false, 'app.url' => 'https://audio.example.test']);
    }

    private function installationHead(TestResponse $response): DOMXPath
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

    private function assertInstallHead(TestResponse $response, bool $public): void
    {
        $head = $this->installationHead($response);
        foreach (['manifest' => ['/manifest.webmanifest', 'install:manifest'],
            'apple-touch-icon' => ['/brand/apple-touch-icon.png', 'install:touch-icon']] as $rel => [$href, $key]) {
            $tags = $head->query('//head/link[@rel="'.$rel.'"]');
            $this->assertCount($public ? 1 : 0, $tags, $rel);
            if ($public) {
                $this->assertSame($href, $tags->item(0)->getAttribute('href'));
                $this->assertSame($key, $tags->item(0)->getAttribute('data-inertia'));
            }
        }
        $this->assertCount(1, $head->query('//head/meta[@name="theme-color"]'));
        $this->assertSame('#052e3a', $head->evaluate('string(//head/meta[@name="theme-color"]/@content)'));
        $this->assertCount(1, $head->query('//head/meta[@name="csrf-token"]'));
    }

    public function test_static_manifest_is_a_minimal_public_root_identity_without_private_or_tracking_urls(): void
    {
        $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame([
            'id' => '/', 'name' => 'VASEY.AUDIO', 'short_name' => 'VASEY.AUDIO',
            'start_url' => '/', 'scope' => '/', 'display' => 'standalone',
            'background_color' => '#052e3a', 'theme_color' => '#052e3a',
            'icons' => [
                ['src' => '/brand/app-icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/brand/app-icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
            ],
        ], $manifest);
    }

    public function test_icon_derivatives_pin_the_whole_original_geometry_and_actual_png_bytes(): void
    {
        $original = public_path('brand/vasey-audio-logo.png');
        $hash = '7b0ffd26d5ddffab9695fdd3e0a4bb306d50642dc6a51e25233fd1626ff07571';
        $this->assertSame($hash, hash_file('sha256', $original));
        $this->assertSame([420, 100], array_slice(getimagesize($original), 0, 2));
        $provenance = json_decode(file_get_contents(base_path('docs/brand/asset-manifest.json')), true, 32, JSON_THROW_ON_ERROR);
        $this->assertCount(6, $provenance['assets']);
        $this->assertCount(3, $provenance['installation_derivatives']);
        foreach ($provenance['installation_derivatives'] as $asset) {
            $this->assertSame('public/brand/vasey-audio-logo.png', $asset['source_path']);
            $this->assertSame($hash, $asset['source_sha256']);
            $this->assertSame([420, 100], $asset['source_dimensions']);
            [$width, $height] = $asset['contained_dimensions'];
            $this->assertSame(420 * $height, 100 * $width, 'The complete lockup retains its intrinsic ratio.');
            $this->assertLessThanOrEqual(420, $width);
            $this->assertLessThanOrEqual(100, $height);
            $this->assertSame([intdiv($asset['width'] - $width, 2), intdiv($asset['height'] - $height, 2)], $asset['offset']);
            $path = base_path($asset['path']);
            $this->assertSame($asset['sha256'], hash_file('sha256', $path));
            $this->assertSame($asset['bytes'], filesize($path));
            $size = getimagesize($path);
            $this->assertSame([$asset['width'], $asset['height'], IMAGETYPE_PNG], array_slice($size, 0, 3));
            $this->assertSame('any', $asset['purpose']);
        }
        $this->assertSame('unverified: official source package retrieval failed; do not trace raster', $provenance['identity_svg_status']);
    }

    public function test_public_html_uses_fixed_keyed_install_links_independent_of_host_and_query_values(): void
    {
        $actor = LicenseFixtures::admin();
        $release = app(SiteContent::class)->create(SiteEditorialFixtures::content('PUBLIC INSTALL'), 'PRIVATE SOURCE LABEL', $actor);
        app(SiteContent::class)->publish($release->id, 0, $actor);
        foreach (['/', '/about', '/blog/first-note'] as $path) {
            $response = $this->get('https://untrusted.example'.$path.'?token=PRIVATE_QUERY_MARKER&order=PRIVATE_ORDER_MARKER')->assertOk();
            $this->assertInstallHead($response, true);
            $head = $this->installationHead($response);
            foreach ($head->query('//head/link[@rel="manifest" or @rel="apple-touch-icon"]') as $tag) {
                $this->assertStringNotContainsString('PRIVATE_', $tag->getAttribute('href'));
                $this->assertStringNotContainsString('untrusted.example', $tag->getAttribute('href'));
            }
            $response->assertDontSee($release->label, false)->assertDontSee($release->content_hash, false);
        }
    }

    public function test_only_current_eligible_track_html_has_install_metadata_and_withdrawal_stays_private(): void
    {
        $fixture = QuoteFixtures::selection();
        $path = '/tracks/'.$fixture['track']->slug;
        $this->assertInstallHead($this->get($path)->assertOk(), true);
        app(PublishTrack::class)->unpublish($fixture['track'], $fixture['actor']);
        $this->get($path)->assertNotFound()->assertDontSee('rel="manifest"', false)
            ->assertDontSee('rel="apple-touch-icon"', false)->assertDontSee($fixture['track']->title);
    }

    public function test_private_staff_previews_have_no_install_links_and_preserve_private_headers_and_rows(): void
    {
        $actor = LicenseFixtures::admin();
        $release = app(SiteContent::class)->create(SiteEditorialFixtures::content('PRIVATE INSTALL'), 'PRIVATE INSTALL LABEL', $actor);
        $this->actingAs($actor);
        $before = [];
        foreach (['site_releases', 'site_publications', 'audit_events'] as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => get_object_vars($row))->all();
        }
        foreach (['', '/about'] as $suffix) {
            $response = $this->get('/admin/site-releases/'.$release->id.'/preview'.$suffix)->assertOk();
            $this->assertInstallHead($response, false);
            $response->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer')
                ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertDontSee($release->label, false)->assertDontSee($release->content_hash, false);
        }
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => get_object_vars($row))->all());
        }
    }

    public function test_owned_private_checkout_has_no_install_or_public_share_metadata_and_performs_no_provider_handoff(): void
    {
        CheckoutFixtures::configure();
        $gateway = CheckoutFixtures::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $gateway);
        $fixture = InventoryFixtures::selection();
        $quoteId = $this->postJson('/quotes', ['items' => $fixture['items']], ['Idempotency-Key' => (string) Str::uuid()])->assertOk()->json('quote.id');
        $quote = Quote::where('public_id', $quoteId)->sole();
        app(PriceQuote::class)->create($quoteId, $quote->owner_key);
        $orderId = $this->postJson('/orders', OrderFixtures::request($quote, $quote->owner_key), ['Idempotency-Key' => (string) Str::uuid()])->assertOk()->json('order.id');
        $before = [];
        foreach (['orders', 'order_lines', 'order_attempts', 'checkout_intents', 'checkout_sessions', 'audit_events'] as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => get_object_vars($row))->all();
        }
        $response = $this->get('/orders/'.$orderId.'/checkout/return?success=true&payment_status=paid')->assertOk();
        $this->assertInstallHead($response, false);
        $head = $this->installationHead($response);
        $this->assertSame('Checkout status — VASEY.AUDIO', $head->evaluate('string(//head/title)'));
        $this->assertSame('no-referrer', $head->evaluate('string(//head/meta[@name="referrer"]/@content)'));
        $this->assertCount(0, $head->query('//head/link[@rel="canonical"] | //head/meta[starts-with(@property,"og:")] | //head/meta[starts-with(@name,"twitter:")]'));
        $response->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertSame([], $gateway->calls);
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => get_object_vars($row))->all());
        }
    }
}
