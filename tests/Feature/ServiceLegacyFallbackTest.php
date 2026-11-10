<?php

namespace Tests\Feature;

use App\Domain\Migration\LegacyRedirects\LegacyRedirectResolver;
use App\Domain\SiteBuilder\Models\SitePublication;
use App\Domain\SiteBuilder\SiteContent;
use App\Domain\SiteBuilder\SiteContentSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

/**
 * URL-006: the legacy /services path falls back to the contact page while the service catalog is off, behind the legacy
 * redirect layer's own literal switch. A real route wins over a map redirect, so the fallback lives in the route's controller.
 */
class ServiceLegacyFallbackTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'https://audio.example.test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['app.url' => self::HOST, 'app.debug' => false]);
    }

    private function publishContactPage(): void
    {
        $operator = LicenseFixtures::admin();
        $content = SiteContentSchema::forEditing(SiteContentSchema::defaults());
        $content['contact'] = ['title' => 'Synthetic contact', 'description' => 'Synthetic fixture.', 'paragraphs' => ['Development records only.'], 'email' => 'operator@example.test'];
        $release = app(SiteContent::class)->create($content, 'Synthetic contact fixture', $operator);
        app(SiteContent::class)->publish($release->id, SitePublication::findOrFail(1)->revision, $operator);
    }

    public function test_the_shipped_defaults_keep_the_plain_404(): void
    {
        $this->assertFalse(config('legacy-redirects.enabled'));
        $this->assertFalse(config('services-projects.catalog_enabled'));
        $this->publishContactPage();
        $this->get(self::HOST.'/services')->assertNotFound()->assertHeaderMissing('Location');
        $this->assertNull(app(LegacyRedirectResolver::class)->contactFallback());
    }

    public function test_catalog_off_and_redirects_on_answers_one_hop_301_to_the_canonical_contact_page(): void
    {
        config(['legacy-redirects.enabled' => true]);
        $this->publishContactPage();

        $this->get(self::HOST.'/services')->assertStatus(301)->assertHeader('Location', self::HOST.'/contact')
            ->assertHeader('Cache-Control', 'max-age=3600, private');
        // The query string is dropped and the request Host never reaches the Location.
        $this->get('https://attacker.example.test/services?utm_source=x&next=https://evil.example.test')->assertStatus(301)
            ->assertHeader('Location', self::HOST.'/contact');
        $this->call('HEAD', self::HOST.'/services')->assertStatus(301)->assertHeader('Location', self::HOST.'/contact');
        $this->get(self::HOST.'/contact')->assertOk();
    }

    public function test_only_the_legacy_index_path_falls_back(): void
    {
        config(['legacy-redirects.enabled' => true]);
        $this->publishContactPage();
        foreach (['/services/mix-review', '/services/unknown', '/services/projects'] as $path) {
            $this->assertNotSame(301, $this->get(self::HOST.$path)->status(), $path);
        }
        $this->get(self::HOST.'/services/mix-review')->assertNotFound();
        $this->post(self::HOST.'/services')->assertStatus(405);
    }

    public function test_the_404_stays_when_no_contact_page_is_public(): void
    {
        config(['legacy-redirects.enabled' => true]);
        // Nothing published: the default site content has no contact section, so there is nowhere to send the visitor.
        $this->assertNull(SiteContentSchema::forEditing(SiteContentSchema::defaults())['contact']);
        $this->get(self::HOST.'/services')->assertNotFound()->assertHeaderMissing('Location');
        $this->assertNull(app(LegacyRedirectResolver::class)->contactFallback());

        // A release that publishes a contact page switches the same request to the redirect.
        $this->publishContactPage();
        $this->get(self::HOST.'/services')->assertStatus(301);
    }

    public function test_catalog_on_serves_the_page_even_with_the_redirect_layer_on(): void
    {
        config(['legacy-redirects.enabled' => true, 'services-projects.catalog_enabled' => true]);
        $this->publishContactPage();
        $this->get(self::HOST.'/services')->assertOk()->assertHeaderMissing('Location');
        config(['legacy-redirects.enabled' => false]);
        $this->get(self::HOST.'/services')->assertOk();
    }

    public function test_only_the_literal_true_switch_enables_the_fallback(): void
    {
        $this->publishContactPage();
        foreach (['true', 1, '1', 'yes'] as $value) {
            config(['legacy-redirects.enabled' => $value]);
            $this->get(self::HOST.'/services')->assertNotFound();
        }
    }
}
