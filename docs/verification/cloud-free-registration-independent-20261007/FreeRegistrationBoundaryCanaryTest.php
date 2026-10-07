<?php

namespace Tests\IndependentFreeRegistration;

use App\Domain\Grants\Free\FreeGrantHttpIdentity;
use App\Domain\Grants\Free\FreeGrantIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class FreeRegistrationBoundaryCanaryTest extends TestCase
{
    public function test_actual_mounted_routes_remain_off_in_production_with_only_the_old_test_switch(): void
    {
        $this->withoutVite();
        $this->assertFalse(app()->bound(FreeGrantIdentity::class));
        $this->assertFalse(app()->bound(FreeGrantHttpIdentity::class));
        config(['free-grants.test_enabled' => true, 'free-grants.operative_enabled' => false, 'app.debug' => true]);
        $this->app->instance('env', 'production');
        foreach (['/free-grants', '/free-grants/index'] as $url) {
            $response = $this->get($url)->assertNotFound();
            $response->assertExactJson(['code' => 'FREE_GRANT_UNAVAILABLE',
                'message' => 'This free grant is unavailable or changed. Refresh before trying again.']);
            $this->private($response);
        }
    }

    public function test_actual_registered_controller_and_failed_reporting_cannot_leak_private_diagnostics(): void
    {
        $this->withoutVite();
        config(['free-grants.test_enabled' => true, 'app.debug' => true]);
        app()->instance(FreeGrantHttpIdentity::class, new class implements FreeGrantHttpIdentity
        {
            public function forRequest(Request $request): array
            {
                throw new \RuntimeException('PRIVATE-ACCOUNT-EMAIL-PASSWORD-SENTINEL');
            }
        });
        Log::shouldReceive('error')->once()->with('Free grant request failed.', ['exception_class' => \RuntimeException::class])
            ->andThrow(new \RuntimeException('PRIVATE-LOGGER-SENTINEL'));
        $response = $this->get('/free-grants')->assertStatus(503);
        $response->assertExactJson(['code' => 'FREE_GRANT_UNAVAILABLE',
            'message' => 'This free grant is unavailable or changed. Refresh before trying again.']);
        $response->assertDontSee('PRIVATE-', false)->assertDontSee('RuntimeException', false);
        $this->private($response);
    }

    private function private($response): void
    {
        $response->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertContains('Cookie', $response->baseResponse->getVary());
    }
}
