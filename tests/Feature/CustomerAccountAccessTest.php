<?php

namespace Tests\Feature;

use App\Domain\Customers\CustomerAccess;
use App\Domain\Customers\CustomerAccessException;
use App\Domain\Customers\CustomerAccounts;
use App\Domain\Customers\CustomerPrincipal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\CustomerFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

class CustomerAccountAccessTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public function test_provisioning_is_explicit_idempotent_and_never_revives_withdrawn_access(): void
    {
        $f = F::account();
        $this->assertSame($f['account']->id, app(CustomerAccounts::class)->provision($f['user'])->id);
        $this->assertDatabaseCount('customer_accounts', 1);
        $this->assertDatabaseCount('quote_owners', 1);
        F::withdraw($f);
        $this->assertFalse(app(CustomerAccounts::class)->provision($f['user'])->active);
        $this->expectException(CustomerAccessException::class);
        app(CustomerAccess::class)->current($f['principal']);
    }

    public function test_account_owner_and_actor_cannot_be_swapped_or_omitted_at_transaction_fence(): void
    {
        $f = F::account();
        $other = F::account();
        $access = app(CustomerAccess::class);
        foreach ([[$f['principal'], $other['principal']->ownerKey, $f['user']],
            [$f['principal'], $f['principal']->ownerKey, $other['user']], [$f['principal'], $f['principal']->ownerKey, null],
            [new CustomerPrincipal($other['account']->id, $f['user']->id, $f['principal']->ownerKey, 1, $f['principal']->credentialStamp), $f['principal']->ownerKey, $f['user']]] as $input) {
            try {
                DB::transaction(fn () => $access->lock(...$input));
                $this->fail('Forged customer principal was accepted.');
            } catch (CustomerAccessException) {
                $this->assertTrue(true);
            }
        }
        DB::transaction(fn () => $access->lock($f['principal'], $f['principal']->ownerKey, $f['user']));
        $this->assertSame($f['user']->id, $access->current($f['principal'])->id);
    }

    public function test_password_change_and_verification_or_staff_changes_invalidate_retained_principal(): void
    {
        foreach (['password' => 'Changed-synthetic-password', 'email_verified_at' => null, 'is_admin' => true] as $field => $value) {
            $f = F::account();
            $f['user']->forceFill([$field => $value])->save();
            try {
                app(CustomerAccess::class)->current($f['principal']);
                $this->fail('Stale principal accepted.');
            } catch (CustomerAccessException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_feature_withdrawal_and_production_environment_refuse_accounts_without_deleting_them(): void
    {
        $f = F::account();
        config(['customer.test_accounts_enabled' => false]);
        try {
            app(CustomerAccess::class)->current($f['principal']);
            $this->fail('Disabled feature accepted.');
        } catch (CustomerAccessException) {
            $this->assertDatabaseCount('customer_accounts', 1);
        }
        config(['customer.test_accounts_enabled' => true]);
        $this->app->instance('env', 'production');
        try {
            app(CustomerAccess::class)->current($f['principal']);
            $this->fail('Production customer fixture accepted.');
        } catch (CustomerAccessException) {
            $this->assertTrue(true);
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public function test_internal_principal_cannot_leak_owner_or_credential_through_debug_or_serialization(): void
    {
        $f = F::account();
        ob_start();
        var_dump($f['principal']);
        $dump = ob_get_clean();
        $this->assertStringNotContainsString($f['principal']->ownerKey, $dump);
        $this->assertStringNotContainsString($f['principal']->credentialStamp, $dump);
        foreach ([fn () => json_encode($f['principal'], JSON_THROW_ON_ERROR), fn () => serialize($f['principal'])] as $serialize) {
            try {
                $serialize();
                $this->fail('Private principal serialized.');
            } catch (\LogicException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_staff_and_unverified_users_cannot_be_provisioned(): void
    {
        config(['customer.test_accounts_enabled' => true]);
        foreach ([['is_admin' => true], ['email_verified_at' => null]] as $attributes) {
            $user = User::factory()->create($attributes);
            try {
                app(CustomerAccounts::class)->provision($user);
                $this->fail('Ineligible account provisioned.');
            } catch (CustomerAccessException) {
                $this->assertDatabaseCount('customer_accounts', 0);
            }
        }
    }
}
