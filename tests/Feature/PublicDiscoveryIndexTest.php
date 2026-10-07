<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PublicDiscoveryIndexTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false, 'app.url' => 'https://audio.example.test/']);
        $this->app->instance('env', 'production');
    }

    public function test_production_index_and_robots_advertise_only_canonical_public_sitemap_without_private_request_echo(): void
    {
        $headers = ['Host' => 'attacker.example', 'X-Forwarded-Host' => 'forwarded.example', 'X-Forwarded-Proto' => 'http', 'Cookie' => 'customer=PRIVATE_TOKEN'];
        $index = $this->withHeaders($headers)->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $xml = simplexml_load_string($index->getContent());
        $this->assertNotFalse($xml);
        $xml->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $this->assertSame(['https://audio.example.test/site-pages-sitemap.xml'], array_map(fn ($loc) => (string) $loc, $xml->xpath('/s:sitemapindex/s:sitemap/s:loc')));
        $this->assertCount(1, $xml->xpath('/s:sitemapindex/s:sitemap/*'));
        $robots = $this->withHeaders($headers)->get('/robots.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $this->assertSame("User-agent: *\nDisallow: /admin\nDisallow: /account\nDisallow: /orders\nDisallow: /quotes\nDisallow: /api\nDisallow: /inquiries\nSitemap: https://audio.example.test/sitemap.xml\n", $robots->getContent());
        foreach ([$index, $robots] as $response) {
            $response->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Robots-Tag', 'noindex, follow');
            $this->assertSame([], $response->headers->getCookies());
            foreach (['attacker', 'forwarded', 'PRIVATE_TOKEN', 'tracks/', 'preview', 'draft'] as $private) {
                $response->assertDontSee($private, false);
            }
        }
    }

    #[DataProvider('nonproductionEnvironments')]
    public function test_nonproduction_disallows_all_and_advertises_no_sitemap(string $environment): void
    {
        $this->app->instance('env', $environment);
        $this->get('/sitemap.xml')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertDontSee('<loc>', false);
        $this->assertSame("User-agent: *\nDisallow: /\n", $this->get('/robots.txt')->assertOk()->getContent());
    }

    public static function nonproductionEnvironments(): array
    {
        return [['testing'], ['local'], ['staging']];
    }

    #[DataProvider('bodyRequests')]
    public function test_nonempty_actual_body_is_denied_despite_false_length(string $path, string $method, string $body): void
    {
        $this->call($method, $path, [], [], [], ['CONTENT_LENGTH' => '0'], $body)->assertNotFound();
    }

    public static function bodyRequests(): array
    {
        $rows = [];
        foreach (['/sitemap.xml', '/robots.txt'] as $path) {
            foreach (['GET', 'HEAD'] as $method) {
                foreach (['x', str_repeat('x', 65536)] as $body) {
                    $rows[] = [$path, $method, $body];
                }
            }
        }

        return $rows;
    }

    public function test_empty_get_and_head_are_allowed_but_query_fields_are_rejected(): void
    {
        foreach (['/sitemap.xml', '/robots.txt'] as $path) {
            foreach (['GET', 'HEAD'] as $method) {
                $this->call($method, $path, [], [], [], ['CONTENT_LENGTH' => '0'], '')->assertOk();
            }
            foreach (['release=1', 'preview=1', 'anything='] as $query) {
                $this->get($path.'?'.$query)->assertNotFound();
            }
        }
    }

    #[DataProvider('invalidOrigins')]
    public function test_invalid_canonical_origin_fails_closed(string $origin): void
    {
        config(['app.url' => $origin]);
        foreach (['/sitemap.xml', '/robots.txt'] as $path) {
            $this->get($path)->assertStatus(503);
        }
    }

    public static function invalidOrigins(): array
    {
        return PublicPagesSitemapTest::invalidOrigins();
    }
}
