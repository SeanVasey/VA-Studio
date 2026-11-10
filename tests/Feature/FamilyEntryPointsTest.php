<?php

namespace Tests\Feature;

use App\Domain\Services\Catalog\ServicePublications;
use App\Domain\SiteBuilder\FamilyEntryPoints;
use App\Domain\SiteBuilder\SiteContent;
use App\Domain\SiteBuilder\SiteContentSchema;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\LicenseFixtures;
use Tests\Support\SiteEditorialFixtures;
use Tests\TestCase;

/**
 * BL-50 / FP-001: every storefront entry point of a product family that is built default-off behind its own server
 * policy follows that policy: no link or path while it is off, the link while it is on. The published navigation is
 * walked through the HTTP layer; the real-browser walk is CI/host evidence (the Playwright browsers here do not match
 * the pinned version).
 */
class FamilyEntryPointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['app.debug' => false, 'app.url' => 'https://audio.example.test', 'services-projects.test_enabled' => false,
            'services-projects.catalog_enabled' => false, 'memberships.test_mode_enabled' => false]);
    }

    public static function familyPaths(): array
    {
        return ['service projects' => ['/services/projects', 'service-projects'], 'encoded service' => ['/%73ervices//projects?x=1', 'service-projects'],
            'service root' => ['/SERVICES#top', 'services'], 'service page' => ['/services/web-design', 'services'], 'membership' => ['/memberships', 'memberships'],
            'member credits' => ['/account/membership-credits/1', 'memberships'], 'member periods' => ['/production-memberships/periods', 'memberships']];
    }

    #[DataProvider('familyPaths')]
    public function test_each_family_path_is_refused_while_its_policy_is_off_and_admitted_while_on(string $path, string $family): void
    {
        $families = app(FamilyEntryPoints::class);
        $this->assertSame($family, $families->familyOf($path));
        $this->assertFalse($families->admits($path));
        config(['services-projects.test_enabled' => true, 'services-projects.catalog_enabled' => true, 'memberships.test_mode_enabled' => true]);
        $this->assertTrue($families->admits($path));
        // The policies, not this registry, decide: production refuses the test journeys. The public service pages follow
        // their own code-literal switch (BL-64), which is not environment-bound, so they stay admitted while it is on.
        $this->app->instance('env', 'production');
        $this->assertSame($family === 'services', $families->admits($path));
    }

    public function test_the_catalog_and_the_test_journey_follow_their_own_switches_and_the_projects_slug_stays_reserved(): void
    {
        $families = app(FamilyEntryPoints::class);
        $this->assertContains('projects', ServicePublications::RESERVED_SLUGS);
        // Catalog on, journey off: public service pages are admitted, the private journey path is not.
        config(['services-projects.catalog_enabled' => true]);
        $this->assertTrue($families->admits('/services'));
        $this->assertTrue($families->admits('/services/web-design'));
        $this->assertFalse($families->admits('/services/projects'));
        $this->assertFalse($families->admits('/services/projects/12'));
        $this->assertSame([], $families->links());
        // Journey on, catalog off: the reverse.
        config(['services-projects.catalog_enabled' => false, 'services-projects.test_enabled' => true]);
        $this->assertFalse($families->admits('/services'));
        $this->assertFalse($families->admits('/services/web-design'));
        $this->assertTrue($families->admits('/services/projects'));
        $this->assertSame([['label' => 'Service projects', 'href' => '/services/projects']], $families->links());
    }

    public function test_paths_of_families_without_a_policy_and_ordinary_pages_are_left_to_their_own_checks(): void
    {
        $families = app(FamilyEntryPoints::class);
        foreach (['/', '/#catalog', '/kits', '/collections/x', '/merch', '/offers/1', '/bundles', '/tracks/a/offers/2/license',
            '/servicesx', '/contact', '/blog/services', '/legal/terms', '/account'] as $path) {
            $this->assertNull($families->familyOf($path), $path);
            $this->assertTrue($families->admits($path), $path);
        }
    }

    public function test_links_follow_each_policy_and_memberships_have_no_public_page(): void
    {
        $families = app(FamilyEntryPoints::class);
        $this->assertSame([], $families->links());
        config(['memberships.test_mode_enabled' => true]);
        $this->assertSame([], $families->links());
        config(['services-projects.test_enabled' => true]);
        $this->assertSame([['label' => 'Service projects', 'href' => '/services/projects']], $families->links());
    }

    public function test_site_content_navigation_cannot_name_a_family_path(): void
    {
        foreach ([SiteContentSchema::defaults(), SiteEditorialFixtures::content()] as $content) {
            foreach (self::familyPaths() as [$path]) {
                $candidate = $content;
                $candidate['navigation'][0]['href'] = $path;
                try {
                    SiteContentSchema::validate($candidate);
                    $this->fail('Accepted navigation into '.$path);
                } catch (ValidationException $error) {
                    $this->assertArrayHasKey('content.navigation.0.href', $error->errors());
                }
            }
        }
    }

    public function test_published_pages_show_the_service_link_only_while_its_policy_is_on(): void
    {
        $actor = LicenseFixtures::admin();
        $release = app(SiteContent::class)->create(SiteEditorialFixtures::content(), 'BL-50 on', $actor);
        app(SiteContent::class)->publish($release->id, 0, $actor);
        $headers = ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/'))];
        foreach (['/', '/contact', '/blog/first-note'] as $path) {
            $this->withHeaders($headers)->get($path)->assertOk()->assertJsonPath('props.familyLinks', []);
        }
        config(['services-projects.test_enabled' => true]);
        foreach (['/', '/contact', '/blog/first-note'] as $path) {
            $this->withHeaders($headers)->get($path)->assertOk()
                ->assertJsonPath('props.familyLinks', [['label' => 'Service projects', 'href' => '/services/projects']]);
        }
    }

    public function test_published_navigation_pages_sitemap_and_structured_data_offer_no_family_path_while_policies_are_off(): void
    {
        $this->app->instance('env', 'production');
        $actor = LicenseFixtures::admin();
        $release = app(SiteContent::class)->create(SiteEditorialFixtures::content(), 'BL-50 walk', $actor);
        app(SiteContent::class)->publish($release->id, 0, $actor);
        $families = app(FamilyEntryPoints::class);
        $headers = ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/'))];

        $queue = ['/'];
        $visited = [];
        while ($queue !== []) {
            $path = array_shift($queue);
            if (isset($visited[$path])) {
                continue;
            }
            $visited[$path] = true;
            $props = $this->withHeaders($headers)->get($path)->assertOk()->json('props');
            $strings = [];
            array_walk_recursive($props, function (mixed $value) use (&$strings): void {
                if (is_string($value)) {
                    $strings[] = $value;
                }
            });
            foreach ($strings as $value) {
                foreach ($this->hrefs($value) as $href) {
                    $this->assertNull($families->familyOf($href), $path.' offers '.$href);
                }
            }
            // Follow every navigation, footer and editorial link the page publishes.
            $links = array_merge(array_column($props['siteContent']['navigation'], 'href'),
                array_column($props['siteContent']['legal_links'] ?? [], 'path'),
                array_column($props['editorial']['entries'] ?? [], 'path'));
            foreach ($links as $link) {
                $next = explode('#', $link)[0] ?: '/';
                $queue[] = $next;
            }
            $this->assertSame([], $props['familyLinks']);
            $this->assertArrayNotHasKey('serviceProjectsEnabled', $props);
            $this->assertArrayNotHasKey('testMembershipsEnabled', $props);
        }
        $this->assertEqualsCanonicalizing(['/', '/about', '/contact', '/blog', '/blog/first-note', '/blog/second-note', '/videos', '/videos/first-film', '/videos/second-film'], array_keys($visited));

        $home = $this->flushHeaders()->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('application/ld+json', $home);
        foreach ($this->hrefs(html_entity_decode($home)) as $href) {
            $this->assertNull($families->familyOf($href), 'home HTML offers '.$href);
        }
        $sitemap = $this->get('/site-pages-sitemap.xml')->assertOk()->getContent();
        preg_match_all('#<loc>https://audio\.example\.test([^<]*)</loc>#', $sitemap, $locs);
        $this->assertNotEmpty($locs[1]);
        foreach ($locs[1] as $loc) {
            $this->assertNull($families->familyOf($loc), 'sitemap lists '.$loc);
        }
    }

    /** Root-relative paths and same-origin URLs found in a string (props, HTML attributes or JSON-LD). */
    private function hrefs(string $text): array
    {
        preg_match_all('#(?:https://audio\.example\.test|(?<![\w.:/])(?=/[A-Za-z%]))(/[^\s"\'<>\\\\]*)#', str_replace('\/', '/', $text), $matches);

        return $matches[1];
    }
}
