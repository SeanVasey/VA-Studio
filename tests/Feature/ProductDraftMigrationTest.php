<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\ProductDrafts;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

class ProductDraftMigrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_06_000041_product_draft_versions.php');
    }

    private function clearOwnedEmptyFixtures(): void
    {
        foreach (['product_draft_members', 'product_draft_versions', 'product_drafts'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
            Schema::drop($table);
        }
    }

    private function refusedWithoutDdl(): void
    {
        $statements = [];
        $watch = true;
        DB::listen(function ($query) use (&$statements, &$watch): void {
            if ($watch && preg_match('/\A\s*(?:create|alter|drop|truncate|rename)\b/i', $query->sql)) {
                $statements[] = $query->sql;
            }
        });
        try {
            $this->migration()->up();
            $this->fail('An existing schema was silently adopted or changed.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('inspection', $error->getMessage());
        } finally {
            $watch = false;
        }
        $this->assertSame([], $statements);
    }

    public function test_populated_down_preserves_all_evidence_and_unjournaled_retry_refuses_before_ddl(): void
    {
        $actor = LicenseFixtures::admin();
        $track = Track::create(['title' => 'Synthetic retained draft', 'slug' => 'retained-draft']);
        $draft = app(ProductDrafts::class)->save(null, ['kind' => 'album', 'title' => 'Retained album', 'track_ids' => [$track->id]], $actor);
        $before = app(ProductDrafts::class)->snapshot($draft->id, $actor);
        $this->migration()->down();
        $this->refusedWithoutDdl();
        $this->assertSame($before, app(ProductDrafts::class)->snapshot($draft->id, $actor));
        $this->assertSame(1, DB::table('audit_events')->where('action', 'catalog.product_draft.created')->count());
    }

    public function test_interrupted_prefix_is_preserved_for_explicit_recovery(): void
    {
        $this->clearOwnedEmptyFixtures();
        Schema::create('product_drafts', fn (Blueprint $table) => $table->string('synthetic_foreign'));
        DB::table('product_drafts')->insert(['synthetic_foreign' => 'Keep this evidence']);
        $this->refusedWithoutDdl();
        $this->assertSame('Keep this evidence', DB::table('product_drafts')->value('synthetic_foreign'));
        $this->assertFalse(Schema::hasTable('product_draft_versions'));
        $this->assertFalse(Schema::hasTable('product_draft_members'));
    }

    public function test_foreign_view_identity_is_preserved(): void
    {
        $this->clearOwnedEmptyFixtures();
        DB::statement("CREATE VIEW product_draft_versions AS SELECT 'Retained view' AS evidence");
        try {
            $this->refusedWithoutDdl();
            $this->assertSame('Retained view', DB::table('product_draft_versions')->value('evidence'));
            $this->assertFalse(Schema::hasTable('product_drafts'));
        } finally {
            DB::statement('DROP VIEW product_draft_versions');
        }
    }

    public function test_temporary_shadow_prevents_any_permanent_creation(): void
    {
        $this->clearOwnedEmptyFixtures();
        DB::statement('CREATE TEMPORARY TABLE product_draft_members (evidence VARCHAR(100))');
        DB::table('product_draft_members')->insert(['evidence' => 'Retained temporary evidence']);
        try {
            $this->refusedWithoutDdl();
            $this->assertSame('Retained temporary evidence', DB::table('product_draft_members')->value('evidence'));
            $this->assertFalse(Schema::hasTable('product_drafts'));
        } finally {
            DB::statement('DROP TABLE product_draft_members');
        }
    }

    public function test_foreign_trigger_identity_is_preserved(): void
    {
        $this->clearOwnedEmptyFixtures();
        Schema::create('synthetic_product_owner', fn (Blueprint $table) => $table->id());
        $sql = DB::getDriverName() === 'sqlite'
            ? 'CREATE TRIGGER product_drafts_identity BEFORE DELETE ON synthetic_product_owner BEGIN SELECT 1; END'
            : 'CREATE TRIGGER product_drafts_identity BEFORE DELETE ON synthetic_product_owner FOR EACH ROW SET @synthetic_product_seen = 1';
        DB::unprepared($sql);
        try {
            $this->refusedWithoutDdl();
            $this->assertFalse(Schema::hasTable('product_drafts'));
            $this->assertTrue(Schema::hasTable('synthetic_product_owner'));
        } finally {
            Schema::drop('synthetic_product_owner');
        }
    }
}
