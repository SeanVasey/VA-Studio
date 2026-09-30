<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

/** Rollback drops tables, which MySQL commits at once, so this runs on a freshly migrated database rather than inside a test transaction. */
class SiteImageMigrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public function test_empty_tables_roll_back_and_the_migration_runs_again_with_its_guards(): void
    {
        $migration = require database_path('migrations/2026_09_30_000027_site_images.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('site_image_variants'));
        $this->assertFalse(Schema::hasTable('site_images'));

        $migration->up();
        $uploader = User::factory()->create()->id;
        $row = fn (array $overrides = []): array => $overrides + [
            'slot' => 'studio', 'original_name' => 'raw.jpg', 'source_path' => 'site-images/quarantine/'.Str::uuid().'/source.upload',
            'source_sha256' => str_repeat('a', 64), 'size_bytes' => 10, 'mime_type' => 'image/jpeg', 'width' => 1440, 'height' => 630,
            'credit' => 'Raw', 'rights_confirmed_at' => now(), 'uploaded_by' => $uploader, 'created_at' => now(), 'updated_at' => now(),
        ];
        $id = DB::table('site_images')->insertGetId($row());
        $this->assertSame(['quarantined', 0], [DB::table('site_images')->where('id', $id)->value('status'), (int) DB::table('site_images')->where('id', $id)->value('attempts')]);
        foreach ([
            'delete' => fn () => DB::table('site_images')->where('id', $id)->delete(),
            'case-only credit change' => fn () => DB::table('site_images')->where('id', $id)->update(['credit' => DB::raw('UPPER(credit)'), 'status' => 'processing',
                'claim_token' => (string) Str::uuid(), 'claimed_until' => now(), 'attempts' => 1]),
            'ready insert' => fn () => DB::table('site_images')->insert($row(['status' => 'ready'])),
        ] as $case => $statement) {
            try {
                $statement();
                $this->fail("The re-run migration accepted: {$case}");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
