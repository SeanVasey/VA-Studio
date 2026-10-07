<?php

namespace Tests\Feature;

use App\Domain\Commerce\ProductionCheckout\ApproveExemptionAuthority;
use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\CheckoutSchema;
use App\Domain\Commerce\ProductionCheckout\Evidence;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionCheckoutFixtures as F;
use Tests\TestCase;

class ProductionCheckoutExemptionAuthorityTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('q', 32))]);
    }

    public function test_actual_owner_command_freezes_scoped_policy_and_exact_replay_conflicts_preserve_original(): void
    {
        $f = F::catalog();
        $policy = F::exemptionPolicy($f);
        $command = app(ApproveExemptionAuthority::class);
        $authority = $command->approve($f['candidate']->id, $policy, 'synthetic-owner-policy', $f['actor']);
        $body = Evidence::open($authority, 'production_checkout_exemption_authority');
        $this->assertFalse($body['external_tax_fact_verified']);
        $this->assertSame('owner_approved_scoped_qualification_delegation', $body['authority_meaning']);
        $this->assertSame('synthetic_rehearsal', $body['policy']['provenance']);
        $this->assertSame($authority, $command->approve($f['candidate']->id, $policy, 'synthetic-owner-policy', $f['actor']));
        $before = DB::table(CheckoutSchema::TABLES['authority'])->get()->toJson();
        $policy['qualification_source_sha256'] = hash('sha256', 'different source');
        try {
            $command->approve($f['candidate']->id, $policy, 'synthetic-owner-policy', $f['actor']);
            $this->fail('Changed scope reused an owner key.');
        } catch (CheckoutException) {
            $this->assertSame($before, DB::table(CheckoutSchema::TABLES['authority'])->get()->toJson());
            $this->assertDatabaseCount(CheckoutSchema::TABLES['authority'], 1);
            $this->assertDatabaseCount('orders', 0);
            $this->assertDatabaseCount('license_grants', 0);
        }
    }

    public static function invalidPolicies(): array
    {
        return [['disabled'], ['not_delegated'], ['not_approved'], ['wrong_provenance'], ['wrong_tax_source'], ['unscoped_qualifier'], ['expired'], ['config_race']];
    }

    public static function unknownStaffFlags(): array
    {
        return [[2], ['withdrawn']];
    }

    #[DataProvider('unknownStaffFlags')]
    public function test_unknown_persisted_staff_role_cannot_author_owner_policy(int|string $flag): void
    {
        $f = F::catalog();
        $policy = F::exemptionPolicy($f);
        try {
            DB::connection()->getPdo()->prepare('UPDATE users SET is_admin = ? WHERE id = ?')->execute([$flag, $f['actor']->id]);
        } catch (\PDOException) {
            // Native strict numeric storage refuses this malformed textual assignment.
            $this->assertSame('mysql', DB::getDriverName());
            $this->assertSame('withdrawn', $flag);
            $this->assertDatabaseCount(CheckoutSchema::TABLES['authority'], 0);

            return;
        }
        $this->assertTrue($f['actor']->fresh()->is_admin);
        try {
            app(ApproveExemptionAuthority::class)->approve($f['candidate']->id, $policy, 'synthetic-unknown-staff-role', $f['actor']);
            $this->fail('Unknown persisted role became delegated owner authority.');
        } catch (CheckoutException) {
            $this->assertDatabaseCount(CheckoutSchema::TABLES['authority'], 0);
            $this->assertDatabaseCount('orders', 0);
            $this->assertDatabaseCount('license_grants', 0);
            $this->assertSame(0, DB::transactionLevel());
        }
    }

    #[DataProvider('invalidPolicies')]
    public function test_mfa_and_reported_qualification_never_substitute_for_explicit_owner_scope(string $case): void
    {
        $f = F::catalog();
        $policy = F::exemptionPolicy($f);
        match ($case) {
            'disabled' => config(['production_checkout.exemption_authoring_enabled' => false]),
            'not_delegated' => config(['production_checkout.exemption_policy_owner_ids' => []]),
            'not_approved' => $policy['owner_approved'] = false,
            'wrong_provenance' => $policy['provenance'] = 'verified_production',
            'wrong_tax_source' => $policy['tax_source_sha256'] = str_repeat('a', 64),
            'unscoped_qualifier' => $policy['qualifier_ids'] = [],
            'expired' => [$policy['effective_from'], $policy['effective_until']] = ['2000-01-01T00:00:00Z', '2001-01-01T00:00:00Z'],
            default => null,
        };
        if ($case === 'config_race') {
            $encrypter = app('encrypter');
            Crypt::shouldReceive('decryptString')->andReturnUsing(fn (string $cipher): string => $encrypter->decryptString($cipher));
            Crypt::shouldReceive('encryptString')->andReturnUsing(function (string $plain) use ($encrypter): string {
                config(['production_checkout.exemption_policy_owner_ids' => []]);

                return $encrypter->encryptString($plain);
            });
        }
        try {
            app(ApproveExemptionAuthority::class)->approve($f['candidate']->id, $policy, 'synthetic-owner-refusal', $f['actor']);
            $this->fail('Invalid delegation became authority.');
        } catch (CheckoutException) {
            $this->assertDatabaseCount(CheckoutSchema::TABLES['authority'], 0);
            $this->assertDatabaseCount('orders', 0);
            $this->assertDatabaseCount('license_grants', 0);
            $this->assertSame(0, DB::transactionLevel());
        }
    }
}
