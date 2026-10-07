<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Boot actual shared registration; never inject route/privacy integration. */
final class ServiceProjectRegistrationCanaryTest extends TestCase
{
    public function test_actual_web_csrf_refuses_a_tokenless_post_with_a_private_response(): void
    {
        $this->app['env'] = 'local';
        try {
            $this->postJson('/services/projects', [])->assertStatus(419)
                ->assertExactJson(['code' => 'SERVICE_PROJECT_UNAVAILABLE', 'message' => 'This service project is unavailable or changed. Refresh before trying again.'])
                ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_actual_route_registry_and_unknown_private_descendant_are_bounded_and_private(): void
    {
        $names = [];
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'services/projects')) {
                $names[] = $route->getName();
                $this->assertContains('web', $route->gatherMiddleware());
            }
        }
        sort($names);
        $this->assertSame(['service-projects.command', 'service-projects.index', 'service-projects.page', 'service-projects.show', 'service-projects.store'], $names);
        config(['app.debug' => true]);
        $this->get('/services/projects/unregistered/private/descendant')->assertNotFound()
            ->assertExactJson(['code' => 'SERVICE_PROJECT_UNAVAILABLE', 'message' => 'This service project is unavailable or changed. Refresh before trying again.'])
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Cache-Control', 'no-store, private')->assertDontSee('stack');
        $this->get('/services/projects/unregistered/private/descendant?brief=private')->assertStatus(422)
            ->assertHeader('Cache-Control', 'no-store, private')->assertDontSee('private');
    }
}
