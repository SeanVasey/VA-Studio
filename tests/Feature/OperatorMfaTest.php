<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\Pages\EditProfile;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

class OperatorMfaTest extends TestCase
{
    use RefreshDatabase {
        migrateFreshUsing as private defaultMigrationOptions;
    }

    public function createApplication()
    {
        if ($this->name() !== 'test_production_panel_requires_enrollment_before_protected_catalog_access') {
            return parent::createApplication();
        }

        // Required-enrollment routes are registered at boot, so production must apply before providers.
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $this->traitsUsedByTest = class_uses_recursive(static::class);
        $app->beforeBootstrapping(RegisterProviders::class, function (Application $app): void {
            $app->detectEnvironment(fn (): string => 'production');
            $app->make('config')->set('app.env', 'production');
        });
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function migrateFreshUsing()
    {
        // This is still PHPUnit's isolated database; the route-boot test changes only application mode.
        return $this->defaultMigrationOptions() + ['--force' => true];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function provider(): AppAuthentication
    {
        $provider = Filament::getPanel('admin')->getMultiFactorAuthenticationProviders()['app'];
        $this->assertInstanceOf(AppAuthentication::class, $provider);
        $this->assertTrue($provider->isRecoverable());

        return $provider;
    }

    private function enrolled(): User
    {
        $actor = LicenseFixtures::admin();
        $actor->saveAppAuthenticationSecret($this->provider()->generateSecret());

        return $actor;
    }

    private function challenge(User $actor): Testable
    {
        $login = Livewire::test(Login::class)->fillForm(['email' => $actor->email, 'password' => 'password'])->call('authenticate');
        $login->assertHasNoErrors();
        $this->assertGuest();
        $this->assertNotNull($login->get('userUndertakingMultiFactorAuthentication'));

        return $login;
    }

    public function test_production_panel_requires_enrollment_before_protected_catalog_access(): void
    {
        $panel = Filament::getPanel('admin');
        $this->assertTrue($panel->isMultiFactorAuthenticationRequired());
        $this->provider();
        $actor = LicenseFixtures::admin();

        $this->actingAs($actor)->get('/admin/tracks')->assertRedirect($panel->getSetUpRequiredMultiFactorAuthenticationUrl());
        $this->actingAs(User::factory()->create())->get('/admin/tracks')->assertForbidden();
        $this->assertDatabaseCount('tracks', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_profile_enrollment_requires_current_password_and_retains_only_encrypted_secret_and_hashed_codes(): void
    {
        $actor = LicenseFixtures::admin();
        $provider = $this->provider();
        $this->actingAs($actor);
        $profile = Livewire::test(EditProfile::class)->mountAction(TestAction::make('setUpAppAuthentication')->schemaComponent('app', 'content'));
        $arguments = decrypt($profile->instance()->getMountedAction()->getArguments()['encrypted']);
        $code = $provider->getCurrentCode($actor, $arguments['secret']);

        $profile->setActionData(['password' => 'incorrect-password', 'code' => $code])->callMountedAction()->assertHasActionErrors(['password']);
        $this->assertNull($actor->fresh()->getAppAuthenticationSecret());
        $this->assertNull($actor->fresh()->getAppAuthenticationRecoveryCodes());
        $profile->setActionData(['password' => 'password', 'code' => $code])->callMountedAction()->assertHasNoActionErrors();

        $current = $actor->fresh();
        $this->assertSame($arguments['secret'], $current->getAppAuthenticationSecret());
        $storedCodes = $current->getAppAuthenticationRecoveryCodes();
        $this->assertCount($provider->getRecoveryCodeCount(), $storedCodes);
        foreach ($arguments['recoveryCodes'] as $index => $recoveryCode) {
            $this->assertTrue(Hash::check($recoveryCode, $storedCodes[$index]));
        }
        $raw = DB::table('users')->where('id', $actor->id)->first();
        $this->assertNotSame($arguments['secret'], $raw->app_authentication_secret);
        $this->assertStringNotContainsString($arguments['recoveryCodes'][0], $raw->app_authentication_recovery_codes);
        $this->assertArrayNotHasKey('app_authentication_secret', $current->toArray());
        $this->assertArrayNotHasKey('app_authentication_recovery_codes', $current->toArray());
    }

    public function test_password_alone_cannot_authenticate_enrolled_operator_and_accepted_totp_cannot_be_reused(): void
    {
        $actor = $this->enrolled();
        $code = $this->provider()->getCurrentCode($actor);
        $this->challenge($actor)->fillForm(['app' => ['code' => $code]], 'multiFactorChallengeForm')->call('authenticate')->assertHasNoErrors();
        $this->assertAuthenticatedAs($actor);
        Filament::auth()->logout();

        $this->challenge($actor)->fillForm(['app' => ['code' => $code]], 'multiFactorChallengeForm')->call('authenticate')->assertHasErrors(['data.multiFactor.app.code']);
        $this->assertGuest();
    }

    public function test_recovery_code_authenticates_once_and_replay_preserves_other_codes(): void
    {
        $actor = $this->enrolled();
        $provider = $this->provider();
        $codes = $provider->generateRecoveryCodes();
        $provider->saveRecoveryCodes($actor, $codes);

        $this->challenge($actor)->set('data.multiFactor.app.useRecoveryCode', true)
            ->fillForm(['app' => ['recoveryCode' => $codes[0]]], 'multiFactorChallengeForm')->call('authenticate')->assertHasNoErrors();
        $this->assertAuthenticatedAs($actor);
        $remaining = $actor->fresh()->getAppAuthenticationRecoveryCodes();
        $this->assertCount(count($codes) - 1, $remaining);
        $this->assertTrue(Hash::check($codes[1], $remaining[0]));
        Filament::auth()->logout();

        $this->challenge($actor)->set('data.multiFactor.app.useRecoveryCode', true)
            ->fillForm(['app' => ['recoveryCode' => $codes[0]]], 'multiFactorChallengeForm')->call('authenticate')->assertHasErrors(['data.multiFactor.app.recoveryCode']);
        $this->assertGuest();
        $this->assertSame($remaining, $actor->fresh()->getAppAuthenticationRecoveryCodes());
    }

    public function test_role_withdrawal_during_challenge_denies_login_before_consuming_a_valid_code(): void
    {
        $actor = $this->enrolled();
        $provider = $this->provider();
        $code = $provider->getCurrentCode($actor);
        $login = $this->challenge($actor);
        User::findOrFail($actor->id)->forceFill(['is_admin' => false])->save();

        $login->fillForm(['app' => ['code' => $code]], 'multiFactorChallengeForm')->call('authenticate')->assertHasErrors(['data.email']);
        $this->assertGuest();
        $this->assertTrue($provider->verifyCode($code, $actor->getAppAuthenticationSecret(), shouldPreventCodeReuse: true));
    }
}
