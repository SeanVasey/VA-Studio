<?php

namespace Tests\Canary;

use App\Domain\Commerce\ProductionCheckout\ApproveExemptionAuthority;
use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\CheckoutSchema;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\DB;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionCheckoutFixtures as F;
use Tests\TestCase;

/**
 * Codex P2 r4208109348: the owner's staff authority (role or MFA enrollment) withdrawn in the
 * SAME physical commit as a NEW exemption authority must not leave a committed authority row.
 */
final class ExemptionAuthorityCommitAdmissionCanaryTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('q', 32))]);
    }

    public function test_committing_owner_role_withdrawal_refuses_new_authority(): void
    {
        $f = F::catalog();
        $this->assertTrue($this->approveDuring($f, ['is_admin' => false]));
        $this->assertDatabaseCount(CheckoutSchema::TABLES['authority'], 0);
        $this->assertTrue((bool) DB::table('users')->where('id', $f['actor']->id)->value('is_admin'));
    }

    public function test_committing_owner_mfa_withdrawal_refuses_new_authority(): void
    {
        $f = F::catalog();
        DB::table('users')->where('id', $f['actor']->id)->update(['app_authentication_secret' => 'SYNTHETIC-ENROLLED-SECRET',
            'app_authentication_recovery_codes' => 'SYNTHETIC-RECOVERY']);
        $this->assertTrue($this->approveDuring($f, ['app_authentication_secret' => null, 'app_authentication_recovery_codes' => null]));
        $this->assertDatabaseCount(CheckoutSchema::TABLES['authority'], 0);
        $this->assertSame('SYNTHETIC-ENROLLED-SECRET', DB::table('users')->where('id', $f['actor']->id)->value('app_authentication_secret'));
    }

    private function approveDuring(array $f, array $withdrawal): bool
    {
        $actor = $f['actor']->id;
        $callbacks = 0;
        app('events')->listen(TransactionCommitting::class, function () use ($actor, $withdrawal, &$callbacks): void {
            $callbacks++;
            DB::table('users')->where('id', $actor)->update($withdrawal);
        });
        $refused = false;
        try {
            app(ApproveExemptionAuthority::class)->approve($f['candidate']->id, F::exemptionPolicy($f), 'synthetic-owner-policy', $f['actor']);
        } catch (CheckoutException) {
            $refused = true;
        }
        $this->assertGreaterThan(0, $callbacks);
        $directory = sys_get_temp_dir().'/va-checkout-write-admission-all';
        @mkdir($directory, 0700, true);
        file_put_contents($directory.'/authority-'.implode('-', array_keys($withdrawal)).'.json', json_encode(['driver' => DB::getDriverName(), 'callbacks' => $callbacks,
            'refused' => $refused, 'authority_rows' => DB::table(CheckoutSchema::TABLES['authority'])->count()], JSON_PRETTY_PRINT)."\n");

        return $refused;
    }
}
