<?php

namespace Tests\Canary;

use App\Domain\Commerce\ProductionCheckout\ApproveExemptionAuthority;
use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\CheckoutSchema;
use Illuminate\Support\Facades\DB;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionCheckoutFixtures;
use Tests\TestCase;

final class ProductionCheckoutUnknownStaffCanaryTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public function test_unknown_raw_staff_flag_is_refused_before_owner_authority_write(): void
    {
        $this->fakePrivateMediaStorage();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('q', 32))]);
        $fixture = ProductionCheckoutFixtures::catalog();
        $policy = ProductionCheckoutFixtures::exemptionPolicy($fixture);
        DB::connection()->getPdo()->exec("UPDATE users SET is_admin='withdrawn' WHERE id=".$fixture['actor']->id);
        $this->assertTrue($fixture['actor']->fresh()->is_admin);
        try {
            app(ApproveExemptionAuthority::class)->approve($fixture['candidate']->id, $policy, 'synthetic-unknown-staff-role', $fixture['actor']);
            $this->fail('Unknown raw staff flag was accepted as delegated owner authority.');
        } catch (CheckoutException) {
            $this->assertDatabaseCount(CheckoutSchema::TABLES['authority'], 0);
            $this->assertDatabaseCount('orders', 0);
            $this->assertDatabaseCount('license_grants', 0);
        }
    }
}
