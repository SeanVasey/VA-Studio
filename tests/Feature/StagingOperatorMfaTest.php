<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use Filament\Facades\Filament;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

/** Staging is reachable by staff over the network, so its panel requires TOTP enrollment exactly as production does. */
class StagingOperatorMfaTest extends TestCase
{
    use RefreshDatabase {
        migrateFreshUsing as private defaultMigrationOptions;
    }

    private const BOOT_ENVIRONMENTS = [
        'test_staging_panel_requires_enrollment_before_protected_catalog_access' => 'staging',
        'test_local_panel_keeps_enrollment_optional' => 'local',
    ];

    public function createApplication()
    {
        $environment = self::BOOT_ENVIRONMENTS[$this->name()] ?? null;
        if ($environment === null) {
            return parent::createApplication();
        }

        // Required-enrollment routes are registered at boot, so the environment must apply before providers.
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $this->traitsUsedByTest = class_uses_recursive(static::class);
        $app->beforeBootstrapping(RegisterProviders::class, function (Application $app) use ($environment): void {
            $app->detectEnvironment(fn (): string => $environment);
            $app->make('config')->set('app.env', $environment);
        });
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function migrateFreshUsing()
    {
        // This is still PHPUnit's isolated database; the route-boot tests change only application mode.
        return $this->defaultMigrationOptions() + ['--force' => true];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_staging_panel_requires_enrollment_before_protected_catalog_access(): void
    {
        $this->assertSame('staging', app()->environment());
        $panel = Filament::getPanel('admin');
        $this->assertTrue($panel->isMultiFactorAuthenticationRequired());
        $actor = LicenseFixtures::admin();
        $this->assertFalse(AdminMultiFactor::satisfiedBy($actor));

        $this->actingAs($actor)->get('/admin/tracks')->assertRedirect($panel->getSetUpRequiredMultiFactorAuthenticationUrl());
        $this->actingAs(User::factory()->create())->get('/admin/tracks')->assertForbidden();
        $this->assertDatabaseCount('tracks', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_local_panel_keeps_enrollment_optional(): void
    {
        $this->assertSame('local', app()->environment());
        $panel = Filament::getPanel('admin');
        $this->assertFalse($panel->isMultiFactorAuthenticationRequired());
        $this->assertTrue(AdminMultiFactor::satisfiedBy(LicenseFixtures::admin()));
    }

    public function test_requirement_follows_the_current_environment(): void
    {
        $panel = Filament::getPanel('admin');
        foreach (['production' => true, 'staging' => true, 'local' => false, 'testing' => false, 'preview' => false] as $environment => $required) {
            $this->app->detectEnvironment(fn () => $environment);
            $this->assertSame($required, $panel->isMultiFactorAuthenticationRequired(), $environment);
        }
        $this->app->detectEnvironment(fn () => 'testing');
    }
}
