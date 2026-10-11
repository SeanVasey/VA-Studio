<?php

namespace Tests\Feature;

use App\Domain\Commerce\Models\PromotionCampaign;
use App\Domain\Commerce\PromotionAdministration;
use App\Domain\Staff\StaffWriteAuthority;
use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\LicenseFixtures;
use Tests\Support\PromotionFixtures;
use Tests\TestCase;

/**
 * #68 review condition C2: promotion administration must apply the admin panel's staff multi-factor rule
 * inside the domain service, so a caller below the panel cannot write for an administrator who has not
 * enrolled where the panel requires it (staging, production). Local and testing keep enrollment optional.
 */
class PromotionAdministrationStaffMfaTest extends TestCase
{
    use RefreshDatabase;

    /** RefreshDatabase wraps each test in a transaction; refusals must leave that level untouched. */
    private int $restingLevel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        $this->restingLevel = DB::transactionLevel();
        config(['commerce.test_promotions' => null, 'commerce.test_pricing_policy' => null]);
    }

    private function in(string $environment, Closure $probe): mixed
    {
        $this->app->detectEnvironment(fn () => $environment);
        try {
            return $probe();
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }
    }

    /** An administrator enrolled in the panel's app (TOTP) factor with a synthetic, test-only secret. */
    private function enrolled(): User
    {
        $admin = LicenseFixtures::admin();
        $admin->saveAppAuthenticationSecret(Filament::getPanel('admin')->getMultiFactorAuthenticationProviders()['app']->generateSecret());

        return $admin->fresh();
    }

    private function policy(string $suffix): array
    {
        return PromotionFixtures::policy(['key' => 'synthetic-'.$suffix, 'code' => 'SYN'.strtoupper(substr(md5($suffix), 0, 8))]);
    }

    private function evidence(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(),
            ['promotion_campaigns', 'promotion_availabilities', 'promotion_availability_revisions', 'audit_events']);
    }

    private function refused(Closure $operation, ?string $message = null): void
    {
        try {
            $operation();
            $this->fail('The promotion operation was admitted.');
        } catch (AuthorizationException $exception) {
            if ($message !== null) {
                $this->assertSame($message, $exception->getMessage());
            }
        }
        $this->assertSame($this->restingLevel, DB::transactionLevel());
    }

    public function test_staging_refuses_writes_and_reads_for_an_admin_without_the_panel_factor(): void
    {
        $service = app(PromotionAdministration::class);
        $campaign = $service->create($this->policy('seed'), $this->enrolled());
        $unenrolled = LicenseFixtures::admin();
        $before = $this->evidence();

        $this->in('staging', function () use ($service, $campaign, $unenrolled): void {
            $this->refused(fn () => $service->create($this->policy('staging'), $unenrolled), StaffWriteAuthority::REFUSAL);
            $this->refused(fn () => $service->setAvailability($campaign->id, 1, true, $unenrolled), StaffWriteAuthority::REFUSAL);
            $this->refused(fn () => $service->campaigns($unenrolled), StaffWriteAuthority::REFUSAL);
            $this->refused(fn () => $service->detail($campaign->id, $unenrolled), StaffWriteAuthority::REFUSAL);
        });

        $this->assertSame($before, $this->evidence());
        $this->assertFalse(PromotionCampaign::where('policy_key', 'synthetic-staging')->exists());
    }

    public function test_staging_admits_an_enrolled_admin(): void
    {
        $service = app(PromotionAdministration::class);
        $enrolled = $this->enrolled();

        $this->in('staging', function () use ($service, $enrolled): void {
            $campaign = $service->create($this->policy('staging'), $enrolled);
            $availability = $service->setAvailability($campaign->id, 1, true, $enrolled);
            $this->assertTrue($availability->enabled);
            $this->assertSame(2, $availability->revision);
            $this->assertSame('active', $service->detail($campaign->id, $enrolled)['status']);
            $this->assertSame(1, $service->campaigns($enrolled)->count());
        });

        $this->assertSame([$enrolled->id, $enrolled->id], DB::table('audit_events')
            ->whereIn('action', ['commerce.promotion.created', 'commerce.promotion.enabled'])
            ->orderBy('id')->pluck('actor_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_staging_refuses_a_stale_enrolled_actor_object_after_enrollment_was_withdrawn(): void
    {
        $service = app(PromotionAdministration::class);
        $stale = $this->enrolled();
        $campaign = $service->create($this->policy('seed'), $stale);
        User::findOrFail($stale->id)->saveAppAuthenticationSecret(null);
        $this->assertNotNull($stale->getAppAuthenticationSecret(), 'The caller still holds the enrolled object.');
        $this->assertNull(User::findOrFail($stale->id)->getAppAuthenticationSecret(), 'The persisted row is withdrawn.');
        $before = $this->evidence();

        $this->in('staging', function () use ($service, $campaign, $stale): void {
            $this->refused(fn () => $service->create($this->policy('stale'), $stale), StaffWriteAuthority::REFUSAL);
            $this->refused(fn () => $service->setAvailability($campaign->id, 1, true, $stale), StaffWriteAuthority::REFUSAL);
            $this->refused(fn () => $service->campaigns($stale), StaffWriteAuthority::REFUSAL);
            $this->refused(fn () => $service->detail($campaign->id, $stale), StaffWriteAuthority::REFUSAL);
        });

        $this->assertSame($before, $this->evidence());
        $this->assertFalse(PromotionCampaign::where('policy_key', 'synthetic-stale')->exists());
    }

    public function test_production_still_refuses_every_admin_before_the_factor_check(): void
    {
        $service = app(PromotionAdministration::class);
        $campaign = $service->create($this->policy('seed'), $this->enrolled());
        $before = $this->evidence();

        foreach ([$this->enrolled(), LicenseFixtures::admin()] as $admin) {
            $this->in('production', function () use ($service, $campaign, $admin): void {
                // The environment refusal precedes the factor check: its message is the framework default.
                $this->refused(fn () => $service->create($this->policy('production'), $admin), (new AuthorizationException)->getMessage());
                $this->refused(fn () => $service->setAvailability($campaign->id, 1, true, $admin), (new AuthorizationException)->getMessage());
            });
        }

        $this->assertSame($before, $this->evidence());
    }

    public function test_non_staff_users_are_refused_in_every_environment_even_when_enrolled(): void
    {
        $service = app(PromotionAdministration::class);
        $campaign = $service->create($this->policy('seed'), $this->enrolled());
        $customer = User::factory()->create();
        $customer->saveAppAuthenticationSecret(Filament::getPanel('admin')->getMultiFactorAuthenticationProviders()['app']->generateSecret());
        $before = $this->evidence();

        foreach (['local', 'testing', 'staging', 'production'] as $environment) {
            $this->in($environment, function () use ($service, $campaign, $customer): void {
                $this->refused(fn () => $service->create($this->policy('customer'), $customer));
                $this->refused(fn () => $service->setAvailability($campaign->id, 1, true, $customer));
            });
        }

        $this->assertSame($before, $this->evidence());
    }

    public function test_local_and_testing_keep_enrollment_optional(): void
    {
        $service = app(PromotionAdministration::class);
        $unenrolled = LicenseFixtures::admin();

        foreach (['local', 'testing'] as $environment) {
            $this->in($environment, function () use ($service, $unenrolled, $environment): void {
                $campaign = $service->create($this->policy($environment), $unenrolled);
                $this->assertTrue($service->setAvailability($campaign->id, 1, true, $unenrolled)->enabled);
                $this->assertSame('active', $service->detail($campaign->id, $unenrolled)['status']);
            });
        }

        $this->assertSame(2, PromotionCampaign::count());
    }
}
