<?php

namespace Tests\Feature;

use Tests\TestCase;

final class ServiceProjectRegistrationTest extends TestCase
{
    public function test_actual_application_registers_each_private_service_route_and_crawler_prefix(): void
    {
        $expected = [
            'service-projects.page' => ['GET', 'services/projects'],
            'service-projects.index' => ['GET', 'services/projects/data'],
            'service-projects.show' => ['GET', 'services/projects/{project}'],
            'service-projects.store' => ['POST', 'services/projects'],
            'service-projects.command' => ['POST', 'services/projects/{project}/commands'],
        ];
        foreach ($expected as $name => [$method, $uri]) {
            $route = app('router')->getRoutes()->getByName($name);
            $this->assertNotNull($route, $name.' must be registered by actual application bootstrap.');
            $this->assertSame($uri, $route->uri());
            $this->assertContains($method, $route->methods());
        }
        config(['app.url' => 'https://audio.example.test/']);
        $this->app->instance('env', 'production');
        $robots = $this->get('/robots.txt')->assertOk()->getContent();
        $this->assertStringContainsString("Disallow: /services/projects\n", $robots);
        $this->get('/sitemap.xml')->assertOk()->assertDontSee('/services/projects', false);
    }

    public function test_actual_outer_privacy_rejects_unrecognized_private_paths_without_debug_or_referrer_leaks(): void
    {
        config(['app.debug' => true]);
        $response = $this->get('/services/projects/unknown/nested?private=SYNTHETIC-PRIVATE-BODY')->assertStatus(422);
        $response->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Vary', 'Cookie')->assertDontSee('SYNTHETIC-PRIVATE-BODY', false)
            ->assertJsonPath('code', 'SERVICE_PROJECT_UNAVAILABLE');
    }
}
