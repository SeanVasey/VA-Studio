<?php

namespace Tests\Feature;

use App\Domain\Rights\CreateLicenseDraft;
use App\Domain\Rights\LicenseDiff;
use App\Domain\Rights\Models\LicenseVersion;
use App\Domain\Rights\PublishLicense;
use App\Domain\Rights\VerifiedLicense;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LicenseEvidenceMigrationTest extends TestCase
{
    public function test_additive_migration_preserves_historical_approvals_without_backfilling_evidence_in_sqlite(): void
    {
        // Isolated old-schema database: independent from the suite's SQLite/MySQL connection.
        $original = DB::getDefaultConnection();
        config(['database.connections.license_history_fixture' => array_replace(config('database.connections.sqlite'), ['database' => ':memory:', 'url' => null])]);
        DB::setDefaultConnection('license_history_fixture');
        Schema::clearResolvedInstance('db.schema');
        try {
            foreach (['0001_01_01_000000_create_users_table.php', '2026_09_04_000001_create_catalog_foundation.php', '2026_09_04_000002_add_admin_mfa_and_immutable_evidence.php'] as $filename) {
                (require database_path('migrations/'.$filename))->up();
            }
            $author = User::factory()->create();
            $author->is_admin = true;
            $author->save();
            $reviewer = User::factory()->create();
            $templateId = DB::table('license_templates')->insertGetId(['name' => 'Historical synthetic fixture', 'slug' => 'historical', 'type' => 'non-exclusive']);
            $source = 'NONBINDING HISTORICAL TEST ONLY';
            $terms = ['features' => ['Historical test'], 'required_asset_roles' => ['master_wav']];
            $common = [
                'license_template_id' => $templateId, 'authored_source' => $source, 'structured_terms' => json_encode($terms),
                'author_id' => $author->id, 'approved_by' => $reviewer->id, 'approved_at' => '2026-08-01 00:00:00',
                'approval_reference' => 'ORIGINAL-HISTORICAL-REFERENCE', 'source_hash' => hash('sha256', $source),
                'model_hash' => hash('sha256', json_encode($terms)), 'renderer_version' => 'historic-renderer', 'render_fixture_hash' => str_repeat('a', 64),
            ];
            $approvedId = DB::table('license_versions')->insertGetId($common + ['status' => 'approved', 'version' => 1]);
            $publishedId = DB::table('license_versions')->insertGetId($common + ['status' => 'published', 'version' => 2, 'published_at' => '2026-08-02 00:00:00']);
            $before = (array) DB::table('license_versions')->where('id', $publishedId)->first();
            (require database_path('migrations/2026_09_04_000004_license_evidence.php'))->up();
            $after = (array) DB::table('license_versions')->where('id', $publishedId)->first();
            foreach ($before as $key => $value) {
                $this->assertSame($value, $after[$key], 'Historical field changed: '.$key);
            }
            $published = LicenseVersion::findOrFail($publishedId);
            $this->assertNull($published->submission_hash);
            $this->assertNull($published->content_author_ids);
            $this->assertSame(0, DB::table('license_review_evidence')->count());
            $this->assertFalse(app(VerifiedLicense::class)->available($published));
            try {
                app(PublishLicense::class)->handle(LicenseVersion::findOrFail($approvedId), $author);
                $this->fail('Historical approval was silently upgraded.');
            } catch (ValidationException) {
                $this->assertSame('approved', LicenseVersion::findOrFail($approvedId)->status);
            }
            try {
                DB::table('license_versions')->where('id', $approvedId)->update(['approval_reference' => 'REPLACED']);
                $this->fail('Historical approval evidence was editable.');
            } catch (QueryException) {
                $this->assertSame('ORIGINAL-HISTORICAL-REFERENCE', LicenseVersion::findOrFail($approvedId)->approval_reference);
            }
            $successor = app(CreateLicenseDraft::class)->handle($published->template, [
                'authored_source' => $source, 'structured_terms' => ['schema_version' => 1] + $terms,
            ], $author, $published);
            $this->assertSame(3, $successor->version);
            $this->assertSame(['structured_terms'], array_keys(app(LicenseDiff::class)->between($published, $successor)));
        } finally {
            DB::purge('license_history_fixture');
            DB::setDefaultConnection($original);
            Schema::clearResolvedInstance('db.schema');
        }
    }
}
