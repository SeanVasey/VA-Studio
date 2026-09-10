<?php

namespace Tests\Feature;

use App\Domain\Rights\Models\LicenseVersion;
use App\Domain\Rights\VerifiedLicense;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\LicenseFixtures;
use Tests\Support\TypedLicenseFixtures;
use Tests\TestCase;

class TypedLicenseMigrationTest extends TestCase
{
    public function test_guard_upgrade_preserves_existing_review_evidence_and_never_infers_typed_rights(): void
    {
        $original = DB::getDefaultConnection();
        config(['database.connections.typed_history_fixture' => array_replace(config('database.connections.sqlite'), ['database' => ':memory:', 'url' => null])]);
        DB::setDefaultConnection('typed_history_fixture');
        Schema::clearResolvedInstance('db.schema');
        try {
            foreach (['0001_01_01_000000_create_users_table.php', '2026_09_04_000001_create_catalog_foundation.php', '2026_09_04_000002_add_admin_mfa_and_immutable_evidence.php', '2026_09_04_000004_license_evidence.php'] as $file) {
                (require database_path('migrations/'.$file))->up();
            }
            $actor = LicenseFixtures::admin();
            $legacy = LicenseFixtures::published($actor);
            $before = $legacy->fresh()->getAttributes();
            $reviewBefore = $legacy->reviewEvidence()->sole()->getAttributes();
            $migration = require database_path('migrations/2026_09_10_000010_typed_license_terms.php');
            $migration->up();
            $this->assertSame($before, $legacy->fresh()->getAttributes());
            $this->assertSame($reviewBefore, $legacy->reviewEvidence()->sole()->getAttributes());
            $this->assertSame(1, $legacy->fresh()->terms_schema_version);
            $this->assertTrue(app(VerifiedLicense::class)->available($legacy));
            $typed = LicenseFixtures::published($actor, terms: TypedLicenseFixtures::terms(), content: ['authored_source' => TypedLicenseFixtures::source()]);
            $this->assertTrue(app(VerifiedLicense::class)->available($typed));
            $this->assertSame(2, $typed->terms_schema_version);
            foreach ([$legacy, $typed] as $version) {
                try {
                    DB::table('license_versions')->where('id', $version->id)->update(['status' => 'draft']);
                    $this->fail('Migration weakened published-state immutability.');
                } catch (QueryException) {
                }
                try {
                    DB::table('license_versions')->where('id', $version->id)->delete();
                    $this->fail('Migration removed historical deletion protection.');
                } catch (QueryException) {
                }
            }
            $this->assertSame(2, LicenseVersion::count());
        } finally {
            DB::purge('typed_history_fixture');
            DB::setDefaultConnection($original);
            Schema::clearResolvedInstance('db.schema');
        }
    }
}
