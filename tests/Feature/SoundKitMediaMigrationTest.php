<?php

namespace Tests\Feature;

use App\Domain\SoundKits\Products\SoundKitProduct;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\Support\SoundKitProductFixtures;
use Tests\TestCase;

/** Migrations 2026_11_11_000600 and 000601 (sound-kit demos and product media). Runs on both engines; the triggers are engine-specific SQL. */
class SoundKitMediaMigrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use SoundKitProductFixtures;

    private $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        MediaFixtures::configure();
        $this->actor = LicenseFixtures::admin();
        $this->actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
    }

    private function demos(): object
    {
        return require database_path('migrations/2026_11_11_000600_sound_kit_demos.php');
    }

    private function media(): object
    {
        return require database_path('migrations/2026_11_11_000601_sound_kit_product_media.php');
    }

    private function row(SoundKitProduct $product, string $status = 'quarantined', array $over = []): int
    {
        $id = (string) Str::uuid();

        return DB::table('sound_kit_demos')->insertGetId($over + ['public_id' => $id, 'sound_kit_product_id' => $product->id, 'original_name' => 'demo.wav',
            'source_path' => 'sound-kit-demos/quarantine/'.$id.'/source.upload', 'source_sha256' => str_repeat('a', 64), 'size_bytes' => 10, 'mime_type' => 'audio/wav',
            'status' => $status, 'attempts' => 0, 'uploaded_by' => $this->actor->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_demo_rows_are_never_deleted_and_a_processed_row_never_changes_under_the_database_triggers(): void
    {
        $product = $this->product('saved');
        $waiting = $this->row($product);
        $ready = $this->row($product, 'ready');
        $failed = $this->row($product, 'failed');
        // A waiting row may move on (claim, finish); processed rows are fixed; none is ever removed.
        $this->assertSame(1, DB::table('sound_kit_demos')->where('id', $waiting)->update(['status' => 'processing', 'attempts' => 1]));
        foreach ([$waiting, $ready, $failed] as $id) {
            try {
                DB::table('sound_kit_demos')->where('id', $id)->delete();
                $this->fail('A demo row was deleted.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach ([$ready, $failed] as $id) {
            try {
                DB::table('sound_kit_demos')->where('id', $id)->update(['original_name' => 'changed.wav']);
                $this->fail('A processed demo row changed.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(3, DB::table('sound_kit_demos')->count());
        $this->assertSame('demo.wav', DB::table('sound_kit_demos')->where('id', $ready)->value('original_name'));
    }

    public function test_the_migrations_roll_back_only_while_nothing_is_recorded_and_refuse_to_adopt_existing_objects(): void
    {
        $product = $this->product('saved');
        // Existing objects are never adopted.
        foreach ([fn () => $this->demos()->up(), fn () => $this->media()->up()] as $up) {
            try {
                $up();
                $this->fail('Existing objects were adopted.');
            } catch (\LogicException $error) {
                $this->assertStringContainsString('Unexpected', $error->getMessage());
            }
        }
        // A recorded demo, or a chosen cover or demo, is evidence that a rollback must not erase.
        $demo = $this->row($product, 'quarantined');
        DB::table('sound_kit_products')->where('id', $product->id)->update(['demo_id' => $demo]);
        try {
            $this->media()->down();
            $this->fail('A populated rollback was accepted.');
        } catch (\LogicException $error) {
            $this->assertStringContainsString('retained', $error->getMessage());
        }
        DB::table('sound_kit_products')->where('id', $product->id)->update(['demo_id' => null]);
        try {
            $this->demos()->down();
            $this->fail('A populated demo rollback was accepted.');
        } catch (\LogicException $error) {
            $this->assertStringContainsString('retained', $error->getMessage());
        }
        // Empty, both roll back in dependency order and apply again (rows removed from a disposable fixture only).
        DB::table('sound_kit_demos')->where('id', $demo)->update(['status' => 'processing']);
        DB::unprepared('DROP TRIGGER sound_kit_demos_retain');
        DB::table('sound_kit_demos')->where('id', $demo)->delete();
        $this->media()->down();
        $this->assertFalse(Schema::hasColumn('sound_kit_products', 'cover_image_id'));
        $this->demos()->down();
        $this->assertFalse(Schema::hasTable('sound_kit_demos'));
        $this->demos()->up();
        $this->media()->up();
        $this->assertTrue(Schema::hasColumns('sound_kit_products', ['cover_image_id', 'cover_alt', 'cover_manifest_sha256', 'cover_approval_hash', 'cover_approved_at', 'cover_approved_by', 'demo_id', 'demo_approval_hash', 'demo_approved_at', 'demo_approved_by']));
        $this->assertTrue(Schema::hasTable('sound_kit_demos'));
    }
}
