<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PaidGrantRootHttpFixture;
use Tests\TestCase;

final class PaidGrantRootRegistrationTest extends TestCase
{
    use PaidGrantRootHttpFixture;

    public static function refusedMountSettings(): array
    {
        return [['shipped', false], ['string', 'true'], ['integer', 1], ['missing', null]];
    }

    #[DataProvider('refusedMountSettings')]
    public function test_only_an_explicit_boolean_mount_registers_paid_routes(string $case, mixed $setting): void
    {
        $this->paidHttpMountSetting = $setting;
        $this->refreshApplication();
        $shipped = require base_path('config/paid-grants.php');
        $this->assertFalse($shipped['http_mounted']);
        $this->assertFalse($shipped['operative_enabled']);
        $this->assertFalse($shipped['rehearsal_enabled']);
        $this->assertFalse(Route::has('paid-grants.page'), $case);
        $response = $this->get('/paid-grants')->assertNotFound()->assertExactJson([
            'code' => 'PAID_GRANT_UNAVAILABLE',
            'message' => 'This paid license is unavailable or changed. Refresh before trying again.',
        ]);
        $response->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_the_explicit_mount_uses_root_privacy_before_any_paid_controller(): void
    {
        $this->assertTrue(Route::has('paid-grants.redeem'));
        $response = $this->get('/paid-grants?PRIVATE-QUERY-SENTINEL=1')->assertStatus(422)->assertDontSee('PRIVATE-QUERY-SENTINEL', false);
        $response->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
