<?php

namespace Tests\Feature\CustomerComms;

use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

/** Raw guard evidence for the schema-v5 image insert successor (2026_10_24_268100); fixtures are not publishable content. */
class LegalSiteSchemaMigrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const PREDECESSOR = 'site_release_images_valid_insert_v4';

    private const SUCCESSOR = 'site_release_images_valid_insert_v5';

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = User::factory()->create();
    }

    public function test_migrated_schema_has_only_the_successor_and_it_admits_exactly_versions_three_to_five(): void
    {
        $this->assertNotNull($this->trigger(self::SUCCESSOR));
        $this->assertNull($this->trigger(self::PREDECESSOR));
        $this->assertNull($this->trigger('site_release_images_valid_insert'));
        $image = $this->readyImage('share');
        foreach ([3, 4, 5] as $version) {
            $this->insert($this->release($version), 'share', $image);
        }
        $this->assertDatabaseCount('site_release_images', 3);
        foreach ([1, 2, 6] as $version) {
            $this->refused(fn () => $this->insert($this->release($version), 'share', $image), "schema {$version}");
        }
        foreach ([
            'different image slot' => [$this->release(5), 'studio', $image],
            'missing image' => [$this->release(5), 'share', 999999],
            'case-only slot' => [$this->release(5), 'SHARE', $image],
        ] as $case => [$release, $slot, $id]) {
            $this->refused(fn () => $this->insert($release, $slot, $id), $case);
        }
        $this->assertDatabaseCount('site_release_images', 3);
    }

    public function test_published_or_scheduled_version_five_release_cannot_gain_a_late_image_reference(): void
    {
        $image = $this->readyImage('share');
        $release = $this->release(5);
        DB::table('site_publication_schedules')->insert([
            'release_id' => $release, 'release_content_hash' => DB::table('site_releases')->where('id', $release)->value('content_hash'),
            'publish_at' => '2030-01-01 12:30:00', 'expected_revision' => 0, 'created_by' => $this->actor->id,
            'created_at' => '2030-01-01 12:00:00', 'state' => 'pending', 'pending_slot' => 1,
        ]);
        $this->refused(fn () => $this->insert($release, 'share', $image), 'scheduled v5');
        $published = $this->release(5);
        DB::table('site_publication_revisions')->insert([
            'revision' => 1, 'release_id' => $published, 'previous_release_id' => null, 'operation' => 'publish',
            'content_hash' => DB::table('site_releases')->where('id', $published)->value('content_hash'),
            'actor_id' => $this->actor->id, 'created_at' => now(),
        ]);
        $this->refused(fn () => $this->insert($published, 'share', $image), 'published v5');
        $this->assertDatabaseCount('site_release_images', 0);
    }

    public function test_down_refuses_while_a_version_five_release_exists_and_otherwise_restores_the_predecessor_first(): void
    {
        $migration = $this->migration();
        $image = $this->readyImage('share');
        $five = $this->release(5);
        $this->insert($five, 'share', $image);
        try {
            $migration->down();
            $this->fail('Rollback with a retained version 5 release was accepted.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('Schema 5 site releases must be retained', $error->getMessage());
        }
        $this->assertNotNull($this->trigger(self::SUCCESSOR));
        $this->assertNull($this->trigger(self::PREDECESSOR));
        $this->assertDatabaseCount('site_release_images', 1);
    }

    public function test_safe_down_and_up_round_trip_and_resume_after_an_interrupted_upgrade(): void
    {
        $migration = $this->migration();
        $migration->down();
        $this->assertNotNull($this->trigger(self::PREDECESSOR));
        $this->assertNull($this->trigger(self::SUCCESSOR));
        $image = $this->readyImage('share');
        $this->refused(fn () => $this->insert($this->release(5), 'share', $image), 'v5 under the predecessor');
        $this->insert($this->release(4), 'share', $image);
        // Interrupted upgrade: successor created, predecessor still present. Resuming drops only the predecessor.
        $successor = (fn () => $this->insertGuard(self::SUCCESSOR))->call($migration);
        DB::unprepared($successor['statement']);
        $migration->up();
        $this->assertNotNull($this->trigger(self::SUCCESSOR));
        $this->assertNull($this->trigger(self::PREDECESSOR));
        $this->insert($this->release(5), 'share', $image);
        $this->assertDatabaseCount('site_release_images', 2);
    }

    public function test_foreign_or_missing_guards_are_refused_before_any_ddl(): void
    {
        $migration = $this->migration();
        $migration->down();
        DB::unprepared('DROP TRIGGER '.self::PREDECESSOR);
        // No guard at all: both directions refuse rather than adopting retained rows unguarded.
        foreach ([fn () => $migration->up(), fn () => $migration->down()] as $operation) {
            try {
                $operation();
                $this->fail('An unguarded image table was adopted.');
            } catch (LogicException $error) {
                $this->assertStringContainsString('insert protection is missing', $error->getMessage());
            }
        }
        // A foreign definition under the successor's name is never adopted or removed.
        $foreign = DB::getDriverName() === 'sqlite'
            ? 'CREATE TRIGGER '.self::SUCCESSOR.' BEFORE INSERT ON site_release_images BEGIN SELECT 1; END'
            : 'CREATE TRIGGER '.self::SUCCESSOR.' BEFORE INSERT ON site_release_images FOR EACH ROW BEGIN END';
        DB::unprepared($foreign);
        try {
            $migration->up();
            $this->fail('A foreign successor definition was adopted.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('Unexpected site release image guard definition', $error->getMessage());
        }
        $this->assertNotNull($this->trigger(self::SUCCESSOR));
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_24_268100_site_release_images_schema_v5.php');
    }

    private function release(int $version): int
    {
        $content = ['schema_version' => $version, 'images' => ['hero' => null, 'studio' => null, 'share' => null]];

        return DB::table('site_releases')->insertGetId([
            'label' => 'Synthetic raw guard fixture', 'schema_version' => $version,
            'content' => json_encode($content, JSON_THROW_ON_ERROR), 'content_hash' => CanonicalJson::hash($content),
            'canonicalization_version' => CanonicalJson::VERSION, 'created_by' => $this->actor->id, 'created_at' => now(),
        ]);
    }

    private function readyImage(string $slot): int
    {
        $id = DB::table('site_images')->insertGetId([
            'slot' => $slot, 'original_name' => 'synthetic.jpg', 'source_path' => 'site-images/quarantine/'.Str::uuid().'/source.upload',
            'source_sha256' => str_repeat('a', 64), 'size_bytes' => 10, 'mime_type' => 'image/jpeg', 'width' => 1440, 'height' => 630,
            'credit' => 'Synthetic raw guard fixture', 'rights_confirmed_at' => now(), 'uploaded_by' => $this->actor->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('site_images')->where('id', $id)->update([
            'status' => 'processing', 'claim_token' => (string) Str::uuid(), 'claimed_until' => now()->addMinutes(16), 'attempts' => 1,
        ]);
        foreach (range(1, $slot === 'share' ? 1 : 6) as $number) {
            DB::table('site_image_variants')->insert([
                'site_image_id' => $id, 'format' => 'jpeg', 'width' => $number * 100, 'height' => 100,
                'storage_path' => 'site-images/revisions/'.Str::uuid().'/synthetic.jpg', 'sha256' => str_repeat('b', 64),
                'size_bytes' => 10, 'created_at' => now(),
            ]);
        }
        DB::table('site_images')->where('id', $id)->update([
            'status' => 'ready', 'claim_token' => null, 'claimed_until' => null, 'profile_version' => 'synthetic-guard',
            'profile_fingerprint' => str_repeat('c', 64), 'evidence' => '{}', 'manifest_sha256' => str_repeat('d', 64), 'processed_at' => now(),
        ]);

        return $id;
    }

    private function insert(int $release, string $slot, int $image): int
    {
        return DB::table('site_release_images')->insertGetId([
            'site_release_id' => $release, 'slot' => $slot, 'site_image_id' => $image, 'created_at' => now(),
        ]);
    }

    private function refused(callable $statement, string $case): void
    {
        $before = DB::table('site_release_images')->count();
        try {
            $statement();
            $this->fail("The database accepted: {$case}");
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame($before, DB::table('site_release_images')->count(), $case);
    }

    private function trigger(string $name): ?string
    {
        return DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('type', 'trigger')->where('name', $name)->value('sql')
            : DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->where('TRIGGER_NAME', $name)->value('ACTION_STATEMENT');
    }
}
