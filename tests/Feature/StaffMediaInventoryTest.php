<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Track;
use App\Domain\Commerce\Models\PendingEntitlement;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\ReadStaffMediaInventory;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\Support\ContractFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

class StaffMediaInventoryTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private function asset(int $bytes = 10, string $role = 'artwork', string $status = 'quarantined', ?int $parent = null): MediaAsset
    {
        $track = Track::firstOrCreate(['slug' => 'synthetic-inventory'], ['title' => 'Synthetic inventory']);

        return MediaAsset::create(['track_id' => $track->id, 'role' => $role, 'status' => $status,
            'disk' => 'local', 'storage_path' => 'SYNTHETIC_PRIVATE_PATH_'.bin2hex(random_bytes(12)),
            'original_name' => 'SYNTHETIC_PRIVATE_NAME', 'size_bytes' => $bytes,
            'technical_metadata' => ['private' => 'SYNTHETIC_TECHNICAL_METADATA'], 'parent_asset_id' => $parent]);
    }

    public function test_page_totals_are_role_status_recorded_bytes_and_exclude_private_metadata(): void
    {
        $actor = LicenseFixtures::admin();
        $one = $this->asset(10);
        $two = $this->asset(20);
        $three = $this->asset(30, 'master_wav', 'failed');
        $this->asset(900);
        $report = app(ReadStaffMediaInventory::class)->handle($actor, limit: 3);

        $this->assertSame('asset_page', $report['scope']);
        $this->assertSame([$one->id, $two->id, $three->id], array_column($report['assets'], 'id'));
        $this->assertSame([
            ['role' => 'artwork', 'status' => 'quarantined', 'asset_count' => 2, 'recorded_bytes' => 30],
            ['role' => 'master_wav', 'status' => 'failed', 'asset_count' => 1, 'recorded_bytes' => 30],
        ], $report['bytes_by_role_status']);
        $this->assertTrue($report['has_more']);
        $this->assertSame($three->id, $report['next_after_id']);
        $json = json_encode($report, JSON_THROW_ON_ERROR);
        foreach (['SYNTHETIC_PRIVATE_PATH', 'SYNTHETIC_PRIVATE_NAME', 'SYNTHETIC_TECHNICAL_METADATA', 'storage_path', 'url'] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
    }

    public function test_current_staff_authority_is_required_before_any_media_read(): void
    {
        $actor = LicenseFixtures::admin();
        $this->asset();
        User::findOrFail($actor->id)->forceFill(['is_admin' => false])->save();
        $mediaReads = [];
        DB::listen(function (QueryExecuted $query) use (&$mediaReads): void {
            if (str_contains($query->sql, 'media_assets')) {
                $mediaReads[] = $query->sql;
            }
        });
        foreach ([$actor, User::factory()->create(), new User] as $denied) {
            try {
                app(ReadStaffMediaInventory::class)->handle($denied);
                $this->fail('Stale, customer or unsaved staff must not read media inventory.');
            } catch (AuthorizationException) {
                $this->assertSame([], $mediaReads);
            }
        }
    }

    public function test_required_mfa_is_rechecked_from_current_enrollment(): void
    {
        $actor = LicenseFixtures::admin();
        $this->asset();
        $panel = Filament::getPanel('admin');
        $wasRequired = $panel->isMultiFactorAuthenticationRequired();
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        try {
            try {
                app(ReadStaffMediaInventory::class)->handle($actor);
                $this->fail('Required MFA must be enrolled.');
            } catch (AuthorizationException) {
                // The positive call below must succeed only after real enrollment.
            }
            $actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
            $this->assertCount(1, app(ReadStaffMediaInventory::class)->handle($actor)['assets']);
            User::findOrFail($actor->id)->saveAppAuthenticationSecret(null);
            $this->expectException(AuthorizationException::class);
            app(ReadStaffMediaInventory::class)->handle($actor);
        } finally {
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $wasRequired);
        }
    }

    public function test_invalid_bounds_are_refused_and_keyset_pages_do_not_repeat_assets(): void
    {
        $actor = LicenseFixtures::admin();
        $one = $this->asset();
        $two = $this->asset();
        foreach ([[-1, 1], [0, 0], [0, 101]] as [$cursor, $limit]) {
            try {
                app(ReadStaffMediaInventory::class)->handle($actor, $cursor, $limit);
                $this->fail('An invalid bound must not be silently clamped.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('inventory', $exception->errors());
            }
        }
        $report = app(ReadStaffMediaInventory::class)->handle($actor, $one->id, 1);
        $this->assertSame([$two->id], array_column($report['assets'], 'id'));
        $this->assertFalse($report['has_more']);
        $this->assertNull($report['next_after_id']);
    }

    public function test_an_older_revision_is_still_a_known_parent_reference_without_claiming_full_coverage(): void
    {
        $actor = LicenseFixtures::admin();
        $old = $this->asset();
        $this->asset(parent: $old->id);
        $row = app(ReadStaffMediaInventory::class)->handle($actor)['assets'][0];
        $this->assertSame('known_reference', $row['reference_state']);
        $this->assertSame(['media_assets.parent_asset_id'], $row['known_reference_kinds']);
        $this->assertSame('incomplete', $row['reference_coverage']);
    }

    public function test_a_real_retained_paid_grant_is_reported_without_reading_or_mutating_its_private_payload(): void
    {
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        ContractFixtures::configure();
        $gateway = PaymentFixtures::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $gateway);
        $this->app->instance(StripePaymentGateway::class, $gateway);
        ContractFixtures::paid($gateway);
        $actor = LicenseFixtures::admin();
        $before = ContractFixtures::retained();
        $assetId = PendingEntitlement::orderBy('id')->firstOrFail()->media_asset_id;
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $report = app(ReadStaffMediaInventory::class)->handle($actor, $assetId - 1, 1);
        $this->assertSame($assetId, $report['assets'][0]['id']);
        $this->assertContains('pending_entitlements.media_asset_id', $report['assets'][0]['known_reference_kinds']);
        $this->assertSame('incomplete', $report['reference_coverage']);
        $this->assertStringNotContainsString('license_grants', implode('\n', $queries));
        $this->assertStringNotContainsString('offer_revisions', implode('\n', $queries));
        $this->assertSame($before, ContractFixtures::retained());
    }

    public function test_reference_beyond_the_fixed_window_remains_unknown_and_does_not_trigger_a_history_scan(): void
    {
        $actor = LicenseFixtures::admin();
        $old = $this->asset();
        for ($i = 0; $i < 200; $i++) {
            $this->asset();
        }
        $this->asset(parent: $old->id);
        $reads = [];
        DB::listen(function (QueryExecuted $query) use (&$reads): void {
            if (str_starts_with(strtolower($query->sql), 'select') && ! str_contains($query->sql, 'users')) {
                $reads[] = $query->sql;
            }
        });
        $report = app(ReadStaffMediaInventory::class)->handle($actor, limit: 1);
        $this->assertSame('unknown', $report['assets'][0]['reference_state']);
        $this->assertSame([], $report['assets'][0]['known_reference_kinds']);
        $this->assertSame(['rows_observed' => 200, 'has_more' => true], $report['reference_windows']['media_assets']);
        $this->assertSame('incomplete', $report['reference_coverage']);
        $this->assertCount(6, $reads);
        foreach ($reads as $query) {
            $this->assertMatchesRegularExpression('/order by ["`]?id["`]? asc limit (2|201)\b/i', $query);
        }
    }

    public function test_even_an_empty_reference_prefix_cannot_establish_safe_deletion(): void
    {
        $actor = LicenseFixtures::admin();
        $this->asset();
        $report = app(ReadStaffMediaInventory::class)->handle($actor);
        $this->assertSame('unknown', $report['assets'][0]['reference_state']);
        $this->assertContains('retained_json_and_encrypted_references_not_inspected', $report['unknown_reasons']);
        $this->assertArrayNotHasKey('safe_to_delete', $report['assets'][0]);
        $this->assertArrayNotHasKey('unreferenced', $report['assets'][0]);
        // Even with every window exhausted, unknown never becomes complete coverage (an unreferenced reading).
        $this->assertSame('incomplete', $report['assets'][0]['reference_coverage']);
        $this->assertSame('incomplete', $report['reference_coverage']);
        foreach ($report['reference_windows'] as $window) {
            $this->assertFalse($window['has_more']);
        }
    }

    public function test_missing_recorded_size_does_not_become_zero_bytes(): void
    {
        $actor = LicenseFixtures::admin();
        $asset = $this->asset();
        DB::table('media_assets')->where('id', $asset->id)->update(['size_bytes' => null]);
        $report = app(ReadStaffMediaInventory::class)->handle($actor);
        $this->assertNull($report['assets'][0]['recorded_bytes']);
        $this->assertNull($report['bytes_by_role_status'][0]['recorded_bytes']);
    }

    public function test_group_totals_become_unknown_on_overflow_or_any_missing_size(): void
    {
        $actor = LicenseFixtures::admin();
        $this->asset(PHP_INT_MAX, 'artwork', 'quarantined');
        $this->asset(1, 'artwork', 'quarantined');
        $mixed = $this->asset(5, 'preview', 'quarantined');
        $this->asset(7, 'preview', 'quarantined');
        DB::table('media_assets')->where('id', $mixed->id)->update(['size_bytes' => null]);
        $groups = [];
        foreach (app(ReadStaffMediaInventory::class)->handle($actor)['bytes_by_role_status'] as $group) {
            $groups[$group['role']] = $group;
        }
        $this->assertSame(2, $groups['artwork']['asset_count']);
        $this->assertNull($groups['artwork']['recorded_bytes'], 'An overflowing sum is unknown, never a float or a wrapped total.');
        $this->assertSame(2, $groups['preview']['asset_count']);
        $this->assertNull($groups['preview']['recorded_bytes'], 'One missing size makes the group total unknown.');
    }

    public function test_an_existing_transaction_cannot_supply_a_stale_inventory_view(): void
    {
        $actor = LicenseFixtures::admin();
        DB::beginTransaction();
        try {
            $this->expectException(LogicException::class);
            app(ReadStaffMediaInventory::class)->handle($actor);
        } finally {
            DB::rollBack();
        }
    }
}
