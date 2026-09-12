<?php

namespace Tests\Feature;

use App\Domain\Rights\LicensePreview;
use App\Domain\Rights\Models\LicenseVersion;
use App\Domain\Rights\VerifiedLicense;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\LicenseFixtures;
use Tests\Support\ScopedLicenseFixtures;
use Tests\Support\TypedLicenseFixtures;
use Tests\TestCase;

class ScopedLicenseMigrationTest extends TestCase
{
    public function test_scope_guard_upgrade_retains_existing_v1_and_v2_records_evidence_and_preview_bytes(): void
    {
        $original = DB::getDefaultConnection();
        config(['database.connections.scope_history_fixture' => array_replace(config('database.connections.sqlite'), ['database' => ':memory:', 'url' => null])]);
        DB::setDefaultConnection('scope_history_fixture');
        Schema::clearResolvedInstance('db.schema');
        try {
            foreach (['0001_01_01_000000_create_users_table.php', '2026_09_04_000001_create_catalog_foundation.php', '2026_09_04_000002_add_admin_mfa_and_immutable_evidence.php', '2026_09_04_000004_license_evidence.php', '2026_09_10_000010_typed_license_terms.php'] as $file) {
                (require database_path('migrations/'.$file))->up();
            }
            $actor = LicenseFixtures::admin();
            $v1 = LicenseFixtures::published($actor);
            $v2 = LicenseFixtures::published($actor, terms: TypedLicenseFixtures::terms(), content: ['authored_source' => TypedLicenseFixtures::source()]);
            $retained = [];
            foreach ([$v1, $v2] as $version) {
                $retained[] = [$version, $version->fresh()->getAttributes(), $version->reviewEvidence()->sole()->getAttributes(), app(LicensePreview::class)->render($version)];
            }
            $migration = require database_path('migrations/2026_09_11_000011_license_scope_terms.php');
            $migration->up();
            foreach ($retained as [$version, $attributes, $evidence, $preview]) {
                $this->assertSame($attributes, $version->fresh()->getAttributes());
                $this->assertSame($evidence, $version->reviewEvidence()->sole()->getAttributes());
                $this->assertSame($preview, app(LicensePreview::class)->render($version->fresh()));
                $this->assertTrue(app(VerifiedLicense::class)->available($version));
                $this->assertArrayNotHasKey('territory', $version->structured_terms);
                $this->assertArrayNotHasKey('duration', $version->structured_terms);
            }
            $scoped = LicenseFixtures::published($actor, terms: ScopedLicenseFixtures::terms(), content: ['authored_source' => ScopedLicenseFixtures::source()]);
            $this->assertSame(3, $scoped->terms_schema_version);
            $this->assertTrue(app(VerifiedLicense::class)->available($scoped));
            foreach ([$v1, $v2, $scoped] as $version) {
                foreach ([['status' => 'draft'], ['authored_source' => 'Changed retained source'], ['submission_hash' => str_repeat('0', 64)]] as $change) {
                    try {
                        DB::table('license_versions')->where('id', $version->id)->update($change);
                        $this->fail('Scope migration weakened immutable content or evidence.');
                    } catch (QueryException) {
                    }
                }
                try {
                    DB::table('license_versions')->where('id', $version->id)->delete();
                    $this->fail('Scope migration removed historical deletion protection.');
                } catch (QueryException) {
                }
            }
            $this->assertSame(3, LicenseVersion::count());
        } finally {
            DB::purge('scope_history_fixture');
            DB::setDefaultConnection($original);
            Schema::clearResolvedInstance('db.schema');
        }
    }
}
