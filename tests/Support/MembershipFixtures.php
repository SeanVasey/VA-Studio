<?php

namespace Tests\Support;

use App\Domain\Memberships\CreditLedger;
use App\Domain\Memberships\MembershipPlans;
use App\Domain\Memberships\Models\MembershipPlan;
use App\Domain\Memberships\Models\MembershipPlanVersion;
use App\Models\User;
use Filament\Facades\Filament;
use LogicException;

/** Explicit disposable noncommercial policy and account fixtures, never production seeds. */
final class MembershipFixtures
{
    public static function configure(): void
    {
        if (! app()->environment('testing')) {
            throw new LogicException('Membership fixtures require the isolated testing environment.');
        }
        CustomerFixtures::configure();
        config(['memberships.test_mode_enabled' => true]);
        $panel = Filament::getPanel('admin');
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
    }

    public static function operator(): User
    {
        self::configure();
        $actor = LicenseFixtures::admin();
        $actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

        return $actor;
    }

    public static function data(array $policy = [], string $title = 'NONCOMMERCIAL SYNTHETIC MEMBERSHIP PLAN'): array
    {
        return ['title' => $title, 'policy' => array_replace(['schema_version' => 1, 'unit' => 'synthetic_credit', 'allowance' => 3,
            'validity_seconds' => 3600, 'rollover' => 'none', 'reversal_allowed' => true], $policy)];
    }

    public static function plan(array $policy = [], ?User $operator = null): array
    {
        $operator ??= self::operator();
        $projection = app(MembershipPlans::class)->createDraft(self::data($policy), $operator);
        $plan = MembershipPlan::findOrFail($projection['plan_id']);
        $version = MembershipPlanVersion::findOrFail($projection['version_id']);

        return compact('operator', 'projection', 'plan', 'version');
    }

    public static function bucket(array $policy = [], string $source = 'synthetic:invoice_fixture_one'): array
    {
        $f = self::plan($policy) + CustomerFixtures::account();
        $grant = app(CreditLedger::class)->grantSynthetic($f['version'], $f['account'], $source, $f['operator']);

        return $f + compact('grant');
    }
}
