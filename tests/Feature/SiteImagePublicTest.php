<?php

namespace Tests\Feature;

use App\Domain\SiteBuilder\Models\SiteImage;
use App\Domain\SiteBuilder\Models\SiteImageVariant;
use App\Domain\SiteBuilder\SiteContent;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\Support\SiteEditorialFixtures;
use Tests\Support\SiteImageFixtures as F;
use Tests\TestCase;

class SiteImagePublicTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        MediaFixtures::configure();
        $this->actor = LicenseFixtures::admin();
        config(['app.debug' => false, 'app.url' => 'https://audio.example.test']);
    }

    /** @param  array<string, SiteImage>  $images */
    private function content(array $images, string $marker): array
    {
        $content = SiteEditorialFixtures::content($marker);
        $content['schema_version'] = 3;
        $content['images'] = [
            'hero' => isset($images['hero_desktop']) ? ['desktop' => ['id' => $images['hero_desktop']->id], 'mobile' => ['id' => $images['hero_mobile']->id], 'alt' => 'Synthetic hero'] : null,
            'studio' => isset($images['studio']) ? ['id' => $images['studio']->id, 'alt' => 'Synthetic studio'] : null,
            'share' => isset($images['share']) ? ['id' => $images['share']->id, 'alt' => 'Synthetic share'] : null,
        ];

        return $content;
    }

    private function url(SiteImageVariant $variant): string
    {
        return '/site-images/'.$variant->sha256.($variant->format === 'webp' ? '.webp' : '.jpg');
    }

    private function inertia(): array
    {
        return ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/'))];
    }

    private function assertMissing(TestResponse $response): void
    {
        $response->assertNotFound()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeaderMissing('Set-Cookie');
        $this->assertSame('', $response->getContent());
    }

    public function test_image_urls_serve_only_images_a_live_release_has_used(): void
    {
        $studio = ['draft' => F::ready('studio', $this->actor), 'scheduled' => F::ready('studio', $this->actor, F::jpeg(1440, 630, ['progressive' => true])),
            'live' => F::ready('studio', $this->actor, F::png(1440, 630)), 'unused' => F::ready('studio', $this->actor, F::png(1440, 630, 'gray'))];
        $site = app(SiteContent::class);
        $site->create($this->content(['studio' => $studio['draft']], 'SYNTHETIC DRAFT'), 'Draft', $this->actor);
        $live = $site->create($this->content(['studio' => $studio['live']], 'SYNTHETIC LIVE'), 'Live', $this->actor);
        $scheduled = $site->create($this->content(['studio' => $studio['scheduled']], 'SYNTHETIC SCHEDULED'), 'Scheduled', $this->actor);
        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00', 'UTC'));
        $site->schedule($scheduled->id, CarbonImmutable::parse('2026-10-01 13:00:00', 'UTC'), 0, $this->actor);
        foreach ($studio as $image) {
            $this->assertMissing($this->get($this->url($image->variants()->firstOrFail())));
        }

        $site->publish($live->id, 0, $this->actor, $site->pendingSchedule()->id);
        foreach ($studio['live']->variants()->get() as $variant) {
            $response = $this->get($this->url($variant))->assertOk()->assertHeader('Content-Type', $variant->mimeType())
                ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public')->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeaderMissing('Set-Cookie');
            $this->assertSame(Storage::disk('local')->get($variant->storage_path), $response->getContent());
        }
        foreach (['draft', 'scheduled', 'unused'] as $private) {
            $this->assertMissing($this->get($this->url($studio[$private]->variants()->firstOrFail())));
        }
        $this->assertMissing($this->get('/site-images/'.str_repeat('a', 64).'.jpg'));
        // The same file under the other format's extension is not the same variant.
        $jpeg = $studio['live']->variants()->where('format', 'jpeg')->firstOrFail();
        $this->assertMissing($this->get('/site-images/'.$jpeg->sha256.'.webp'));

        // A staff session changes nothing on the public route.
        $this->actingAs($this->actor);
        $this->assertMissing($this->get($this->url($studio['draft']->variants()->firstOrFail())));
        $this->get($this->url($jpeg))->assertOk();

        // Once live, an image stays reachable after the site moves on.
        $later = $site->create(SiteEditorialFixtures::content('SYNTHETIC LATER'), 'Later', $this->actor);
        $site->publish($later->id, 1, $this->actor);
        $this->get($this->url($jpeg))->assertOk();
    }

    public function test_malformed_names_get_the_same_throttled_empty_404_as_private_images(): void
    {
        $studio = F::ready('studio', $this->actor);
        $site = app(SiteContent::class);
        $site->publish($site->create($this->content(['studio' => $studio], 'SYNTHETIC LIVE'), 'Live', $this->actor)->id, 0, $this->actor);
        $jpeg = $studio->variants()->where('format', 'jpeg')->firstOrFail();
        $this->get($this->url($jpeg))->assertOk()->assertHeader('X-RateLimit-Limit', '600');

        // Near misses of a live file's name reach the controller rather than the framework's HTML error page.
        foreach ([strtoupper($jpeg->sha256).'.jpg', $jpeg->sha256.'.jpeg', $jpeg->sha256.'.JPG', substr($jpeg->sha256, 1).'.jpg', $jpeg->sha256] as $name) {
            $response = $this->get('/site-images/'.$name);
            $this->assertMissing($response);
            $response->assertHeader('X-RateLimit-Limit', '600');
        }
    }

    public function test_the_storefront_shows_release_images_with_every_size_and_shares_the_share_image(): void
    {
        $images = [];
        foreach (array_keys(F::SIZES) as $slot) {
            $images[$slot] = F::ready($slot, $this->actor);
        }
        $site = app(SiteContent::class);
        $release = $site->create($this->content($images, 'SYNTHETIC PICTURES'), 'Pictures', $this->actor);
        $site->publish($release->id, 0, $this->actor);

        $props = $this->get('/', $this->inertia())->assertOk()->json('props');
        $this->assertArrayNotHasKey('images', $props['siteContent']);
        $hero = $props['siteImages']['hero'];
        $this->assertSame('Synthetic hero', $hero['alt']);
        $sources = fn (SiteImage $image, string $format): array => $image->variants()->where('format', $format)->orderBy('width')->get()
            ->map(fn (SiteImageVariant $variant): array => ['url' => $this->url($variant), 'width' => $variant->width, 'height' => $variant->height])->all();
        $this->assertSame(['width' => 2400, 'height' => 890, 'jpeg' => $sources($images['hero_desktop'], 'jpeg'), 'webp' => $sources($images['hero_desktop'], 'webp')], $hero['desktop']);
        $this->assertSame(['width' => 960, 'height' => 890, 'jpeg' => $sources($images['hero_mobile'], 'jpeg'), 'webp' => $sources($images['hero_mobile'], 'webp')], $hero['mobile']);
        $this->assertSame(['alt' => 'Synthetic studio', 'width' => 1440, 'height' => 630, 'jpeg' => $sources($images['studio'], 'jpeg'), 'webp' => $sources($images['studio'], 'webp')],
            $props['siteImages']['studio']);
        foreach ([$hero['desktop'], $hero['mobile'], $props['siteImages']['studio']] as $set) {
            foreach ([...$set['jpeg'], ...$set['webp']] as $source) {
                $this->get($source['url'])->assertOk();
            }
        }

        $share = $images['share']->variants()->sole();
        $expected = ['imageUrl' => 'https://audio.example.test'.$this->url($share), 'imageAlt' => 'Synthetic share', 'imageWidth' => 1200, 'imageHeight' => 630, 'imageType' => 'image/jpeg'];
        $this->assertSame($expected, array_intersect_key($props['metadata'], $expected));
        $html = (string) $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('<meta data-inertia="og:image" property="og:image" content="'.$expected['imageUrl'].'">', $html);
        $this->assertStringContainsString('<meta data-inertia="og:image:width" property="og:image:width" content="1200">', $html);
        $this->assertStringContainsString('<meta data-inertia="og:image:type" property="og:image:type" content="image/jpeg">', $html);
        $editorial = $this->get('/about', $this->inertia())->assertOk()->json('props.metadata');
        $this->assertSame($expected, array_intersect_key($editorial, $expected));
    }

    public function test_without_a_share_image_the_hero_is_shared_and_without_images_the_built_in_files_remain(): void
    {
        $images = ['hero_desktop' => F::ready('hero_desktop', $this->actor), 'hero_mobile' => F::ready('hero_mobile', $this->actor)];
        $site = app(SiteContent::class);
        $imageFree = $site->create(SiteEditorialFixtures::content('SYNTHETIC BUILT IN'), 'Built in', $this->actor);
        $site->publish($imageFree->id, 0, $this->actor);
        $props = $this->get('/', $this->inertia())->json('props');
        $this->assertSame(['hero' => null, 'studio' => null], $props['siteImages']);
        $builtIn = ['imageUrl' => 'https://audio.example.test/images/storefront-hero.jpg', 'imageWidth' => 2400, 'imageHeight' => 890, 'imageType' => 'image/jpeg'];
        $this->assertSame($builtIn, array_intersect_key($props['metadata'], $builtIn));

        $heroOnly = $site->create($this->content($images, 'SYNTHETIC HERO'), 'Hero only', $this->actor);
        $site->publish($heroOnly->id, 1, $this->actor);
        $props = $this->get('/', $this->inertia())->json('props');
        $this->assertNull($props['siteImages']['studio']);
        $shared = $images['hero_desktop']->variants()->where('format', 'jpeg')->where('width', 1200)->sole();
        $expected = ['imageUrl' => 'https://audio.example.test'.$this->url($shared), 'imageAlt' => 'Synthetic hero', 'imageWidth' => 1200, 'imageHeight' => 445];
        $this->assertSame($expected, array_intersect_key($props['metadata'], $expected));
    }

    public function test_a_staff_preview_links_the_private_preview_route_for_draft_images(): void
    {
        $studio = F::ready('studio', $this->actor);
        $draft = app(SiteContent::class)->create($this->content(['studio' => $studio], 'SYNTHETIC PREVIEW'), 'Preview', $this->actor);
        $this->actingAs($this->actor);

        $props = $this->get(route('filament.admin.site-releases.preview', $draft), $this->inertia())->assertOk()->json('props');
        $urls = array_column([...$props['siteImages']['studio']['jpeg'], ...$props['siteImages']['studio']['webp']], 'url');
        $this->assertCount(6, $urls);
        foreach ($urls as $url) {
            $this->assertMatchesRegularExpression('~\A/admin/site-images/[0-9]+/preview\z~D', $url);
            $this->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        }
        // The draft's images are still private on the public route.
        $this->assertMissing($this->get($this->url($studio->variants()->firstOrFail())));
    }
}
