<?php

namespace Tests\Canary;

use App\Domain\Commerce\ProductionCheckout\ApproveExemptionAuthority;
use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\CheckoutSchema;
use App\Domain\Commerce\ProductionCheckout\TaxExemptions;
use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionCheckoutFixtures as F;
use Tests\Support\ProductionIdentityFixture;
use Tests\TestCase;

/**
 * Codex P2 r4208264416: the qualifier's staff authority (role or MFA enrollment) withdrawn in the
 * SAME physical commit as a NEW exemption basis must not leave a committed basis row.
 */
final class ExemptionBasisCommitAdmissionCanaryTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use ProductionIdentityFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('j', 32)),
            'production-customer-identity.enabled' => true, 'production-customer-identity.provenance' => IdentityPolicy::REHEARSAL,
            'production-customer-identity.public_origin' => 'http://localhost', 'production-customer-identity.notifications_enabled' => true,
            'production-customer-identity.transport_capability' => LoopbackSmtp::CAPABILITY,
            'production_checkout.fresh_checkout_enabled' => true, 'production_checkout.reconciliation_enabled' => true]);
        Queue::fake();
    }

    public function test_committing_qualifier_role_withdrawal_refuses_new_basis(): void
    {
        $f = $this->authorized();
        $refused = $this->qualifyDuring($f, ['is_admin' => false]);
        $this->assertTrue($refused);
        $this->assertDatabaseCount(CheckoutSchema::TABLES['basis'], 0);
        $this->assertTrue((bool) DB::table('users')->where('id', $f['catalog']['actor']->id)->value('is_admin'));
    }

    public function test_committing_qualifier_mfa_withdrawal_refuses_new_basis(): void
    {
        $f = $this->authorized();
        DB::table('users')->where('id', $f['catalog']['actor']->id)->update(['app_authentication_secret' => 'SYNTHETIC-ENROLLED-SECRET',
            'app_authentication_recovery_codes' => 'SYNTHETIC-RECOVERY']);
        $refused = $this->qualifyDuring($f, ['app_authentication_secret' => null, 'app_authentication_recovery_codes' => null]);
        $this->assertTrue($refused);
        $this->assertDatabaseCount(CheckoutSchema::TABLES['basis'], 0);
        $this->assertSame('SYNTHETIC-ENROLLED-SECRET', DB::table('users')->where('id', $f['catalog']['actor']->id)->value('app_authentication_secret'));
    }

    private function authorized(): array
    {
        $catalog = F::catalog();
        $buyer = $this->enrollThroughLocalSmtp();
        $authority = app(ApproveExemptionAuthority::class)->approve($catalog['candidate']->id, F::exemptionPolicy($catalog), 'synthetic-owner-policy', $catalog['actor']);

        return compact('catalog', 'buyer', 'authority');
    }

    private function qualifyDuring(array $f, array $withdrawal): bool
    {
        $actor = $f['catalog']['actor']->id;
        $callbacks = 0;
        app('events')->listen(TransactionCommitting::class, function () use ($actor, $withdrawal, &$callbacks): void {
            $callbacks++;
            DB::table('users')->where('id', $actor)->update($withdrawal);
        });
        $refused = false;
        try {
            (new TaxExemptions(new ProductionCustomerAccess))->qualify($f['buyer']['principal'], $f['buyer']['user'], $f['authority']['public_id'], $f['catalog']['items'],
                ['qualified_exemption_confirmed' => true, 'reference' => 'synthetic:buyer-bound-qualification', 'source_sha256' => hash('sha256', 'NONBINDING SYNTHETIC BUYER EXEMPTION'),
                    'effective_from' => CarbonImmutable::now('UTC')->subDay()->format('Y-m-d\TH:i:s\Z'),
                    'effective_until' => CarbonImmutable::now('UTC')->addDays(30)->format('Y-m-d\TH:i:s\Z')], 'synthetic-qualified-buyer', $f['catalog']['actor']);
        } catch (CheckoutException) {
            $refused = true;
        }
        $this->assertGreaterThan(0, $callbacks);
        $directory = sys_get_temp_dir().'/va-checkout-write-admission-all';
        @mkdir($directory, 0700, true);
        file_put_contents($directory.'/basis-'.implode('-', array_keys($withdrawal)).'.json', json_encode(['driver' => DB::getDriverName(), 'callbacks' => $callbacks,
            'refused' => $refused, 'basis_rows' => DB::table(CheckoutSchema::TABLES['basis'])->count()], JSON_PRETTY_PRINT)."\n");

        return $refused;
    }
}
