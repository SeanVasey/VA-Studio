<?php

namespace Tests\Feature;

use App\Domain\Commerce\Inventory\ManageRightsScope;
use App\Domain\Commerce\Operations\TestPaymentExceptionOperations;
use App\Domain\Commerce\PromotionAdministration;
use App\Domain\Commerce\QuoteException;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\Environment\TestEnvironment;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\Support\InventoryFixtures;
use Tests\Support\LicenseFixtures;
use Tests\Support\PromotionFixtures;
use Tests\TestCase;
use Throwable;

/**
 * Independent reviewer probes for lane B2 (not part of the branch). Each probe records an outcome string
 * so the evidence shows what actually happened under each environment, not only pass/fail.
 */
class ReviewerStagingProbeTest extends TestCase
{
    use RefreshDatabase;

    private function in(string $environment, Closure $probe): mixed
    {
        $this->app->detectEnvironment(fn () => $environment);
        try {
            return $probe();
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }
    }

    private static function outcome(Closure $operation): string
    {
        try {
            $result = $operation();

            return 'completed'.(is_object($result) ? ':'.class_basename($result) : '');
        } catch (QuoteException $error) {
            return 'QuoteException:'.$error->errorCode.':'.$error->status;
        } catch (Throwable $error) {
            return class_basename($error);
        }
    }

    private function enrolled(): User
    {
        $admin = LicenseFixtures::admin();
        $provider = Filament::getPanel('admin')->getMultiFactorAuthenticationProviders()['app'];
        $admin->saveAppAuthenticationSecret($provider->generateSecret());

        return $admin->fresh();
    }

    public function test_probe_helper_exact_match_semantics(): void
    {
        $rows = [];
        foreach (['staging', 'Staging', 'STAGING', 'staging-eu', 'stage', ' staging', 'staging ', "staging\n", '', 'testing', 'local', 'production', 'preview'] as $env) {
            $rows[json_encode($env)] = $this->in($env, fn () => [
                'admitsTestCommerce' => TestEnvironment::admitsTestCommerce(),
                'requiresStaffMfa' => TestEnvironment::requiresStaffMfa(),
                'refusesProductionOnly' => TestEnvironment::refusesProductionOnly(),
                'panelMfaRequired' => Filament::getPanel('admin')->isMultiFactorAuthenticationRequired(),
            ]);
        }
        fwrite(STDERR, "\nPROBE helper matrix: ".json_encode($rows, JSON_PRETTY_PRINT)."\n");
        $this->assertTrue($rows['"staging"']['admitsTestCommerce']);
        $this->assertTrue($rows['"staging"']['requiresStaffMfa']);
        foreach (['"Staging"', '"STAGING"', '"staging-eu"', '"stage"', '" staging"', '"staging "', '"staging\n"', '""', '"preview"'] as $near) {
            $this->assertFalse($rows[$near]['admitsTestCommerce'], $near);
            $this->assertFalse($rows[$near]['refusesProductionOnly'], $near);
        }
        $this->assertTrue($rows['"production"']['panelMfaRequired']);
        $this->assertFalse($rows['"production"']['admitsTestCommerce']);
    }

    public function test_probe_mfa_rule_by_environment_for_unenrolled_and_enrolled_admins(): void
    {
        $unenrolled = LicenseFixtures::admin();
        $enrolled = $this->enrolled();
        $rows = [];
        foreach (['staging', 'production', 'local', 'testing'] as $env) {
            $rows[$env] = $this->in($env, fn () => [
                'unenrolled' => AdminMultiFactor::satisfiedBy($unenrolled),
                'enrolled' => AdminMultiFactor::satisfiedBy($enrolled),
            ]);
        }
        fwrite(STDERR, "\nPROBE AdminMultiFactor::satisfiedBy: ".json_encode($rows)."\n");
        $this->assertFalse($rows['staging']['unenrolled']);
        $this->assertTrue($rows['staging']['enrolled']);
        $this->assertFalse($rows['production']['unenrolled']);
        $this->assertTrue($rows['local']['unenrolled']);
    }

    public function test_probe_mfa_gated_staff_domain_action_refuses_unenrolled_admin_in_staging(): void
    {
        $unenrolled = LicenseFixtures::admin();
        $enrolled = $this->enrolled();
        $locked = function (User $actor): string {
            return self::outcome(fn () => DB::transaction(fn () => (new ReflectionMethod(TestPaymentExceptionOperations::class, 'locked'))
                ->invoke(app(TestPaymentExceptionOperations::class), (string) Str::uuid(), $actor)));
        };
        $rows = [
            'staging unenrolled' => $this->in('staging', fn () => $locked($unenrolled)),
            'staging enrolled' => $this->in('staging', fn () => $locked($enrolled)),
            'production unenrolled' => $this->in('production', fn () => $locked($unenrolled)),
            'production enrolled' => $this->in('production', fn () => $locked($enrolled)),
            'local unenrolled' => $this->in('local', fn () => $locked($unenrolled)),
        ];
        fwrite(STDERR, "\nPROBE TestPaymentExceptionOperations::locked: ".json_encode($rows)."\n");
        $this->assertSame('AuthorizationException', $rows['staging unenrolled']);
        // Enrolled staff passes authority and reaches the (empty) record lookup.
        $this->assertSame('ModelNotFoundException', $rows['staging enrolled']);
        $this->assertSame('AuthorizationException', $rows['production unenrolled']);
        $this->assertSame('ModelNotFoundException', $rows['production enrolled'], 'production refuses after authority by not finding');
        $this->assertSame('ModelNotFoundException', $rows['local unenrolled']);
    }

    public function test_probe_staff_writes_without_domain_mfa_check_in_staging(): void
    {
        // Observation probe: records whether admitted staff writes that do not call AdminMultiFactor accept an
        // unenrolled admin when invoked below the panel (the panel middleware itself requires enrollment).
        config(['commerce.test_promotions' => null, 'commerce.test_pricing_policy' => null]);
        InventoryFixtures::configure();
        $unenrolled = LicenseFixtures::admin();
        $rows = [];
        foreach (['staging', 'production', 'preview'] as $env) {
            $rows['promotion.create '.$env] = $this->in($env, fn () => self::outcome(fn () => app(PromotionAdministration::class)
                ->create(PromotionFixtures::policy(['key' => 'probe-'.$env, 'code' => 'PROBE'.strtoupper(substr(md5($env), 0, 6))]), $unenrolled)));
            $rows['rights-scope.register '.$env] = $this->in($env, fn () => self::outcome(fn () => app(ManageRightsScope::class)
                ->register('probe.'.$env, 'reviewer-probe-reference', $unenrolled)));
        }
        fwrite(STDERR, "\nPROBE staff writes by an UNENROLLED admin: ".json_encode($rows, JSON_PRETTY_PRINT)."\n");
        $this->assertSame('AuthorizationException', $rows['promotion.create production']);
        $this->assertSame('QuoteException:INVENTORY_UNAVAILABLE:503', $rows['rights-scope.register production']);
        $this->assertSame('AuthorizationException', $rows['promotion.create preview']);
        // Recorded, not asserted as desired behaviour: see DECISION.md finding on domain-level MFA.
        $this->addToAssertionCount(1);
    }
}
