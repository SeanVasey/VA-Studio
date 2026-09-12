<?php

namespace Tests\Feature;

use App\Domain\Rights\LicensePreview;
use App\Domain\Rights\Models\LicenseVersion;
use App\Domain\Rights\VerifiedLicense;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\EconomicLicenseFixtures;
use Tests\Support\LicenseFixtures;
use Tests\Support\ScopedLicenseFixtures;
use Tests\Support\TypedLicenseFixtures;
use Tests\TestCase;

class EconomicLicenseMigrationTest extends TestCase
{
    public function test_economic_guard_upgrade_retains_v1_v2_v3_records_evidence_and_preview_bytes(): void
    {
        $original = DB::getDefaultConnection();
        config(['database.connections.economic_history_fixture' => array_replace(config('database.connections.sqlite'), ['database' => ':memory:', 'url' => null])]);
        DB::setDefaultConnection('economic_history_fixture');
        Schema::clearResolvedInstance('db.schema');
        try {
            foreach (['0001_01_01_000000_create_users_table.php', '2026_09_04_000001_create_catalog_foundation.php', '2026_09_04_000002_add_admin_mfa_and_immutable_evidence.php', '2026_09_04_000004_license_evidence.php', '2026_09_10_000010_typed_license_terms.php', '2026_09_11_000011_license_scope_terms.php'] as $file) {
                (require database_path('migrations/'.$file))->up();
            }
            $actor = LicenseFixtures::admin();
            $v1 = LicenseFixtures::published($actor);
            $v2 = LicenseFixtures::published($actor, terms: TypedLicenseFixtures::terms(), content: ['authored_source' => TypedLicenseFixtures::source()]);
            $v3 = LicenseFixtures::published($actor, terms: ScopedLicenseFixtures::terms(), content: ['authored_source' => ScopedLicenseFixtures::source()]);
            $retained = [];
            foreach ([$v1, $v2, $v3] as $version) {
                $retained[] = [$version, $version->fresh()->getAttributes(), $version->reviewEvidence()->sole()->getAttributes(), app(LicensePreview::class)->render($version)];
            }
            $migration = require database_path('migrations/2026_09_12_000012_license_economic_terms.php');
            $migration->up();
            foreach ($retained as [$version, $attributes, $evidence, $preview]) {
                $this->assertSame($attributes, $version->fresh()->getAttributes());
                $this->assertSame($evidence, $version->reviewEvidence()->sole()->getAttributes());
                $this->assertSame($preview, app(LicensePreview::class)->render($version->fresh()));
                $this->assertTrue(app(VerifiedLicense::class)->available($version));
                foreach (['ownership', 'publishing_income', 'recording_royalty', 'policies'] as $newField) {
                    $this->assertArrayNotHasKey($newField, $version->structured_terms);
                }
            }
            $economic = LicenseFixtures::published($actor, terms: EconomicLicenseFixtures::terms(), content: ['authored_source' => EconomicLicenseFixtures::source()]);
            $this->assertSame(4, $economic->terms_schema_version);
            $this->assertTrue(app(VerifiedLicense::class)->available($economic));
            foreach ([$v1, $v2, $v3, $economic] as $version) {
                foreach ([['status' => 'draft'], ['authored_source' => 'Changed retained source'], ['submission_hash' => str_repeat('0', 64)]] as $change) {
                    try {
                        DB::table('license_versions')->where('id', $version->id)->update($change);
                        $this->fail('Economic migration weakened immutable content or evidence.');
                    } catch (QueryException) {
                    }
                }
                try {
                    DB::table('license_versions')->where('id', $version->id)->delete();
                    $this->fail('Economic migration removed historical deletion protection.');
                } catch (QueryException) {
                }
            }
            $this->assertSame(4, LicenseVersion::count());
        } finally {
            DB::purge('economic_history_fixture');
            DB::setDefaultConnection($original);
            Schema::clearResolvedInstance('db.schema');
        }
    }
}
