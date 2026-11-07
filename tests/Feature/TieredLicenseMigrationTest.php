<?php

namespace Tests\Feature;

use App\Domain\Rights\LicensePreview;
use App\Domain\Rights\Models\LicenseVersion;
use App\Domain\Rights\ReviewLicense;
use App\Domain\Rights\VerifiedLicense;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\EconomicLicenseFixtures;
use Tests\Support\LicenseFixtures;
use Tests\Support\ScopedLicenseFixtures;
use Tests\Support\TieredLicenseFixtures;
use Tests\Support\TypedLicenseFixtures;
use Tests\TestCase;

class TieredLicenseMigrationTest extends TestCase
{
    private const MIGRATIONS = ['0001_01_01_000000_create_users_table.php', '2026_09_04_000001_create_catalog_foundation.php',
        '2026_09_04_000002_add_admin_mfa_and_immutable_evidence.php', '2026_09_04_000004_license_evidence.php',
        '2026_09_10_000010_typed_license_terms.php', '2026_09_11_000011_license_scope_terms.php', '2026_09_12_000012_license_economic_terms.php'];

    public function test_tier_guard_upgrade_retains_v1_to_v4_evidence_and_rollback_restores_the_exact_v4_guard(): void
    {
        $original = DB::getDefaultConnection();
        config(['database.connections.tier_history_fixture' => array_replace(config('database.connections.sqlite'), ['database' => ':memory:', 'url' => null])]);
        DB::setDefaultConnection('tier_history_fixture');
        Schema::clearResolvedInstance('db.schema');
        try {
            foreach (self::MIGRATIONS as $file) {
                (require database_path('migrations/'.$file))->up();
            }
            $v4Guard = $this->guards();
            $this->assertSame(['license_review_state_guard_v4'], array_keys($v4Guard));
            $actor = LicenseFixtures::admin();
            $retained = [];
            foreach ([[], [TypedLicenseFixtures::class], [ScopedLicenseFixtures::class], [EconomicLicenseFixtures::class]] as $fixture) {
                $version = $fixture === [] ? LicenseFixtures::published($actor)
                    : LicenseFixtures::published($actor, terms: $fixture[0]::terms(), content: ['authored_source' => $fixture[0]::source()]);
                $retained[] = [$version, $version->fresh()->getAttributes(), $version->reviewEvidence()->sole()->getAttributes(), app(LicensePreview::class)->render($version)];
            }
            // Before the upgrade, the v4 guard refuses any v5 submission.
            $early = TieredLicenseFixtures::draft($actor);
            try {
                app(ReviewLicense::class)->submit($early, $actor);
                $this->fail('The v4 guard accepted schema 5 review evidence.');
            } catch (QueryException) {
                $this->assertSame('draft', $early->fresh()->status);
            }
            $migration = require database_path('migrations/2026_11_07_270100_license_tier_usage_terms.php');
            $migration->up();
            $this->assertSame(['license_review_state_guard_v5'], array_keys($this->guards()));
            foreach ($retained as [$version, $attributes, $evidence, $preview]) {
                $this->assertSame($attributes, $version->fresh()->getAttributes());
                $this->assertSame($evidence, $version->reviewEvidence()->sole()->getAttributes());
                $this->assertSame($preview, app(LicensePreview::class)->render($version->fresh()));
                $this->assertTrue(app(VerifiedLicense::class)->available($version));
            }
            $tiered = LicenseFixtures::published($actor, terms: TieredLicenseFixtures::terms(), content: ['authored_source' => TieredLicenseFixtures::source()]);
            $this->assertSame(5, $tiered->terms_schema_version);
            $this->assertTrue(app(VerifiedLicense::class)->available($tiered));
            foreach ([...array_column($retained, 0), $tiered] as $version) {
                foreach ([['status' => 'draft'], ['authored_source' => 'Changed retained source'], ['submission_hash' => str_repeat('0', 64)]] as $change) {
                    try {
                        DB::table('license_versions')->where('id', $version->id)->update($change);
                        $this->fail('The tier migration weakened immutable content or evidence.');
                    } catch (QueryException) {
                    }
                }
            }
            $this->assertSame(6, LicenseVersion::count());
            $migration->down();
            $this->assertSame($v4Guard, $this->guards());
            $this->assertTrue(app(VerifiedLicense::class)->available($tiered));
            $late = TieredLicenseFixtures::draft($actor);
            try {
                app(ReviewLicense::class)->submit($late, $actor);
                $this->fail('The restored v4 guard accepted schema 5 review evidence.');
            } catch (QueryException) {
                $this->assertSame('draft', $late->fresh()->status);
            }
        } finally {
            DB::purge('tier_history_fixture');
            DB::setDefaultConnection($original);
            Schema::clearResolvedInstance('db.schema');
        }
    }

    /** @return array<string, string> the installed review-state guard SQL, keyed by trigger name */
    private function guards(): array
    {
        $rows = DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'trigger' AND name LIKE 'license_review_state_guard%' ORDER BY name");

        return array_column(array_map(fn ($row) => (array) $row, $rows), 'sql', 'name');
    }
}
