<?php

namespace Tests\Feature;

use App\Domain\Catalog\PublishTrack;
use App\Domain\Catalog\SaveTrackMetadata;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\Audit\AuditEvent;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

class OperatorAuthorityTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('authorityWithdrawals')]
    public function test_retained_operator_cannot_mutate_catalog_after_persisted_authority_withdrawal(string $field, mixed $value): void
    {
        $actor = LicenseFixtures::admin();
        $track = app(SaveTrackMetadata::class)->handle(null, ['title' => 'Synthetic draft', 'slug' => 'synthetic-draft'], $actor);
        User::findOrFail($actor->id)->forceFill([$field => $value])->save();
        $outcomes = [];

        foreach ([
            fn () => app(SaveTrackMetadata::class)->handle(null, ['title' => 'Forbidden create', 'slug' => 'forbidden-create'], $actor),
            fn () => app(SaveTrackMetadata::class)->handle($track, ['title' => 'Forbidden edit', 'metadata_version' => 1], $actor),
            fn () => app(PublishTrack::class)->unpublish($track, $actor),
        ] as $command) {
            try {
                $command();
                $outcomes[] = 'allowed';
            } catch (AuthorizationException) {
                $outcomes[] = 'denied';
            }
        }

        $this->assertSame(['denied', 'denied', 'denied'], $outcomes);
        $this->assertFalse(Gate::forUser($actor)->allows('administer-catalog'));
        $this->assertFalse($actor->canAccessPanel(Filament::getPanel('admin')));
        $this->assertDatabaseCount('tracks', 1);
        $this->assertSame('Synthetic draft', $track->fresh()->title);
        $this->assertSame(1, $track->fresh()->metadata_version);
        $this->assertDatabaseCount('audit_events', 1);
    }

    public static function authorityWithdrawals(): array
    {
        return ['staff role' => ['is_admin', false], 'email verification' => ['email_verified_at', null]];
    }

    #[DataProvider('locallyElevatedActors')]
    public function test_local_actor_fields_cannot_supply_missing_persisted_authority(string $state): void
    {
        $actor = match ($state) {
            'customer' => User::factory()->create(),
            'unverified staff' => User::factory()->create(['is_admin' => true, 'email_verified_at' => null]),
            'unsaved staff' => new User,
        };
        $actor->forceFill(['is_admin' => true, 'email_verified_at' => now()]);

        $this->assertFalse(Gate::forUser($actor)->allows('administer-catalog'));
        $this->assertFalse($actor->canAccessPanel(Filament::getPanel('admin')));
        $this->assertDatabaseCount('tracks', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public static function locallyElevatedActors(): array
    {
        return ['customer' => ['customer'], 'unverified staff' => ['unverified staff'], 'unsaved staff' => ['unsaved staff']];
    }

    public function test_deleted_operator_cannot_keep_gate_or_panel_authority(): void
    {
        $actor = LicenseFixtures::admin();
        User::findOrFail($actor->id)->delete();

        $this->assertFalse(Gate::forUser($actor)->allows('administer-catalog'));
        $this->assertFalse($actor->canAccessPanel(Filament::getPanel('admin')));
        $this->assertDatabaseCount('tracks', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_persisted_current_authority_controls_access_and_valid_commands_continue(): void
    {
        $actor = LicenseFixtures::admin();
        $actor->forceFill(['is_admin' => false, 'email_verified_at' => null]);

        $this->assertTrue(Gate::forUser($actor)->allows('administer-catalog'));
        $this->assertTrue($actor->canAccessPanel(Filament::getPanel('admin')));
        $track = app(SaveTrackMetadata::class)->handle(null, ['title' => 'Synthetic draft', 'slug' => 'synthetic-draft'], $actor);
        $updated = app(SaveTrackMetadata::class)->handle($track, ['title' => 'Current operator edit', 'metadata_version' => 1], $actor);
        $this->assertSame('Current operator edit', $updated->title);
        $this->assertSame(2, $updated->metadata_version);
        $this->assertDatabaseCount('audit_events', 2);
        $this->assertSame($actor->id, AuditEvent::latest('id')->firstOrFail()->actor_id);
    }

    public function test_required_mfa_enrollment_uses_current_persisted_secret_in_both_directions(): void
    {
        $panel = Filament::getPanel('admin');
        $wasRequired = $panel->isMultiFactorAuthenticationRequired();
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);

        try {
            $actor = LicenseFixtures::admin();
            $actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
            $this->assertTrue(AdminMultiFactor::satisfiedBy($actor, $panel));
            $current = User::findOrFail($actor->id);
            $current->saveAppAuthenticationSecret(null);
            $this->assertFalse(AdminMultiFactor::satisfiedBy($actor, $panel));
            $current->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
            $actor->app_authentication_secret = null;
            $this->assertTrue(AdminMultiFactor::satisfiedBy($actor, $panel));
            $this->assertDatabaseCount('audit_events', 0);
        } finally {
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $wasRequired);
        }
    }

    public function test_deleted_user_never_satisfies_optional_or_required_mfa_enrollment(): void
    {
        $panel = Filament::getPanel('admin');
        $wasRequired = $panel->isMultiFactorAuthenticationRequired();

        try {
            $actor = LicenseFixtures::admin();
            $actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
            User::findOrFail($actor->id)->delete();
            foreach ([false, true] as $required) {
                $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $required);
                $this->assertFalse(AdminMultiFactor::satisfiedBy($actor, $panel));
            }
        } finally {
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $wasRequired);
        }
    }
}
