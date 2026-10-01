<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

/** Raw database guard evidence only: these fixtures do not establish manifests, scanner acceptance or publishable content. */
class SiteRelatedTrackImageMigrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const LEGACY_GUARD = 'site_release_images_valid_insert';

    private const RELATED_TRACKS_GUARD = 'site_release_images_valid_insert_v4';

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = User::factory()->create();
    }

    public function test_upgrade_installs_the_protective_successor_before_removing_only_the_old_insert_guard(): void
    {
        $migration = $this->migration();
        $migration->down();
        $before = $this->retainedStructure();
        $image = $this->readyImage('share');
        $legacy = $this->release(3);
        $index = $this->insert($legacy, 'share', $image);
        $next = $this->release(4);
        $this->refused(fn () => $this->insert($next, 'share', $image), 'v4 before upgrade');

        $ddl = $this->observeReplacement(self::RELATED_TRACKS_GUARD, self::LEGACY_GUARD,
            fn () => $this->insert($next, 'share', $image), fn () => $migration->up());

        $this->assertSame(['create', 'drop'], $ddl);
        $this->assertNotNull($this->trigger(self::RELATED_TRACKS_GUARD));
        $this->assertNull($this->trigger(self::LEGACY_GUARD));
        $this->assertSame($before, $this->retainedStructure());
        $this->assertSame($index, (int) DB::table('site_release_images')->where('site_release_id', $legacy)->value('id'));
        $this->insert($next, 'share', $image);
        $this->assertDatabaseCount('site_release_images', 2);
    }

    public function test_the_successor_accepts_exactly_schema_three_and_four_with_ready_same_slot_images(): void
    {
        foreach (['hero_desktop', 'hero_mobile', 'studio', 'share'] as $slot) {
            $image = $this->readyImage($slot);
            foreach ([3, 4] as $version) {
                $this->insert($this->release($version), $slot, $image);
            }
        }
        $this->assertDatabaseCount('site_release_images', 8);

        $share = $this->readyImage('share');
        foreach ([1, 2, 5] as $version) {
            $this->refused(fn () => $this->insert($this->release($version), 'share', $share), "schema {$version}");
        }
        $release = $this->release(4);
        $waiting = $this->quarantinedImage('share');
        foreach ([
            'unknown slot' => [$release, 'logo', $share],
            'case-only slot' => [$release, 'SHARE', $share],
            'trailing-space slot' => [$release, 'share ', $share],
            'different image slot' => [$release, 'studio', $share],
            'unready image' => [$release, 'share', $waiting],
            'missing image' => [$release, 'share', 999999],
            'missing release' => [999999, 'share', $share],
            'null release' => [null, 'share', $share],
            'null image' => [$release, 'share', null],
            'null slot' => [$release, null, $share],
        ] as $case => [$id, $slot, $image]) {
            $this->refused(fn () => $this->insert($id, $slot, $image), $case);
        }
        $this->refused(fn () => $this->release(null), 'null schema');
        $this->assertDatabaseCount('site_release_images', 8);
    }

    public function test_slot_and_status_spellings_are_byte_exact_even_when_an_image_row_is_corrupted(): void
    {
        $release = $this->release(4);
        $image = $this->readyImage('share');
        // Isolate this guard's checks from the separate image transition guard in this disposable test database.
        DB::unprepared('DROP TRIGGER site_images_transition');
        foreach ([['slot', 'SHARE'], ['slot', 'share '], ['status', 'READY'], ['status', 'ready ']] as [$column, $value]) {
            DB::table('site_images')->where('id', $image)->update([$column => $value]);
            $this->refused(fn () => $this->insert($release, 'share', $image), "corrupt {$column} '{$value}'");
            DB::table('site_images')->where('id', $image)->update([$column => $column === 'slot' ? 'share' : 'ready']);
        }
        $this->insert($release, 'share', $image);
        $this->assertDatabaseCount('site_release_images', 1);
    }

    public function test_any_prior_schedule_or_publication_history_prevents_a_late_image_reference(): void
    {
        $image = $this->readyImage('share');
        foreach ([3, 4] as $version) {
            $release = $this->release($version);
            $schedule = DB::table('site_publication_schedules')->insertGetId([
                'release_id' => $release, 'release_content_hash' => DB::table('site_releases')->where('id', $release)->value('content_hash'),
                'publish_at' => '2030-01-01 12:30:00', 'expected_revision' => 0, 'created_by' => $this->actor->id,
                'created_at' => '2030-01-01 12:00:00', 'state' => 'pending', 'pending_slot' => 1,
            ]);
            $this->refused(fn () => $this->insert($release, 'share', $image), "pending v{$version} schedule");
            DB::table('site_publication_schedules')->where('id', $schedule)->update([
                'state' => 'cancelled', 'pending_slot' => null, 'resolved_at' => '2030-01-01 12:01:00',
                'resolved_by' => $this->actor->id, 'outcome' => 'cancelled',
            ]);
            $this->refused(fn () => $this->insert($release, 'share', $image), "retained cancelled v{$version} schedule");
        }
        $published = [];
        foreach ([3, 4] as $version) {
            $release = $this->release($version);
            $pointer = DB::table('site_publications')->where('id', 1)->first();
            DB::table('site_publication_revisions')->insert([
                'revision' => $pointer->revision + 1, 'release_id' => $release, 'previous_release_id' => $pointer->active_release_id,
                'operation' => 'publish', 'content_hash' => DB::table('site_releases')->where('id', $release)->value('content_hash'),
                'actor_id' => $this->actor->id, 'created_at' => now(),
            ]);
            DB::table('site_publications')->where('id', 1)->update([
                'revision' => $pointer->revision + 1, 'active_release_id' => $release, 'updated_at' => now(),
            ]);
            $this->refused(fn () => $this->insert($release, 'share', $image), "published v{$version} release");
            $published[] = $release;
        }
        $this->refused(fn () => $this->insert($published[0], 'share', $image), 'previously published release after replacement');
        $this->assertDatabaseCount('site_release_images', 0);
        $this->assertDatabaseCount('site_publication_schedules', 2);
        $this->assertDatabaseCount('site_publication_revisions', 2);
    }

    public function test_existing_unique_restrictive_immutable_and_retention_guards_remain_in_force(): void
    {
        $release = $this->release(4);
        $image = $this->readyImage('share');
        $index = $this->insert($release, 'share', $image);
        $before = DB::table('site_release_images')->where('id', $index)->first();
        $this->refused(fn () => $this->insert($release, 'share', $image), 'duplicate slot');
        $this->refused(fn () => DB::table('site_release_images')->where('id', $index)->update(['slot' => 'share']), 'even an unchanged update');
        $this->refused(fn () => DB::table('site_release_images')->where('id', $index)->update(['site_image_id' => 999999]), 'changed image');
        $this->refused(fn () => DB::table('site_release_images')->where('id', $index)->delete(), 'deleted index');
        $this->refused(fn () => DB::table('site_releases')->where('id', $release)->delete(), 'deleted release');
        $this->refused(fn () => DB::table('site_images')->where('id', $image)->delete(), 'deleted image');
        $this->assertEquals($before, DB::table('site_release_images')->where('id', $index)->first());
        $foreign = Schema::getForeignKeys('site_release_images');
        $this->assertCount(2, $foreign);
        $this->assertEqualsCanonicalizing(['site_releases', 'site_images'], array_column($foreign, 'foreign_table'));
        foreach ($foreign as $key) {
            $this->assertSame('restrict', strtolower($key['on_delete']));
        }
    }

    #[DataProvider('retainedVersionFour')]
    public function test_down_refuses_any_retained_version_four_before_changing_guards_or_evidence(bool $indexed): void
    {
        $release = $this->release(4);
        $image = $this->readyImage('share');
        if ($indexed) {
            $this->insert($release, 'share', $image);
        }
        $before = [$this->allImageGuards(), $this->retainedStructure(), DB::table('site_releases')->get()->all(), DB::table('site_release_images')->get()->all()];
        try {
            $this->migration()->down();
            $this->fail('Rollback accepted retained schema 4 evidence.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('Schema 4 site releases must be retained', $exception->getMessage());
        }
        $this->assertEquals($before, [$this->allImageGuards(), $this->retainedStructure(), DB::table('site_releases')->get()->all(), DB::table('site_release_images')->get()->all()]);
        $this->assertNull($this->trigger(self::LEGACY_GUARD));
        $this->insert($this->release(4), 'share', $image);
        $this->assertDatabaseCount('site_release_images', $indexed ? 2 : 1);
    }

    public static function retainedVersionFour(): array
    {
        return ['image-free' => [false], 'indexed image' => [true]];
    }

    public function test_safe_down_restores_schema_three_before_removing_the_successor_and_keeps_existing_rows(): void
    {
        $migration = $this->migration();
        $image = $this->readyImage('share');
        $release = $this->release(3);
        $this->insert($release, 'share', $image);
        $before = [$this->retainedStructure(), DB::table('site_release_images')->get()->all()];
        $ddl = $this->observeReplacement(self::LEGACY_GUARD, self::RELATED_TRACKS_GUARD,
            fn () => $this->insert($this->release(5), 'share', $image), fn () => $migration->down());
        $this->assertSame(['create', 'drop'], $ddl);
        $this->assertNull($this->trigger(self::RELATED_TRACKS_GUARD));
        $this->assertNotNull($this->trigger(self::LEGACY_GUARD));
        $this->assertEquals($before, [$this->retainedStructure(), DB::table('site_release_images')->get()->all()]);
        $this->insert($this->release(3), 'share', $image);
        $next = $this->release(4);
        $this->refused(fn () => $this->insert($next, 'share', $image), 'schema 4 after down');
        $migration->up();
        $this->insert($next, 'share', $image);
        $this->assertDatabaseCount('site_release_images', 3);
    }

    public function test_failed_successor_creation_never_drops_the_existing_protective_guard(): void
    {
        $migration = $this->migration();
        $migration->down();
        $legacy = $this->trigger(self::LEGACY_GUARD);
        $this->collision(self::RELATED_TRACKS_GUARD);
        $this->refused(fn () => $migration->up(), 'successor name collision');
        $this->assertSame($legacy, $this->trigger(self::LEGACY_GUARD));
        $this->refused(fn () => $this->insert($this->release(4), 'share', $this->readyImage('share')), 'v4 behind retained predecessor');
    }

    public function test_failed_predecessor_creation_never_drops_the_existing_protective_successor(): void
    {
        $migration = $this->migration();
        $successor = $this->trigger(self::RELATED_TRACKS_GUARD);
        $this->collision(self::LEGACY_GUARD);
        $this->refused(fn () => $migration->down(), 'predecessor name collision');
        $this->assertSame($successor, $this->trigger(self::RELATED_TRACKS_GUARD));
        $this->refused(fn () => $this->insert($this->release(5), 'share', $this->readyImage('share')), 'unsupported schema behind retained successor');
        $this->insert($this->release(4), 'share', $this->readyImage('share'));
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_01_000032_editorial_related_tracks_images.php');
    }

    private function release(?int $version): int
    {
        $content = ['schema_version' => $version, 'images' => ['hero' => null, 'studio' => null, 'share' => null]];

        return DB::table('site_releases')->insertGetId([
            'label' => 'Synthetic raw guard fixture', 'schema_version' => $version,
            'content' => json_encode($content, JSON_THROW_ON_ERROR), 'content_hash' => CanonicalJson::hash($content),
            'canonicalization_version' => CanonicalJson::VERSION, 'created_by' => $this->actor->id, 'created_at' => now(),
        ]);
    }

    private function quarantinedImage(string $slot): int
    {
        return DB::table('site_images')->insertGetId([
            'slot' => $slot, 'original_name' => 'synthetic.jpg', 'source_path' => 'site-images/quarantine/'.Str::uuid().'/source.upload',
            'source_sha256' => str_repeat('a', 64), 'size_bytes' => 10, 'mime_type' => 'image/jpeg', 'width' => 1440, 'height' => 630,
            'credit' => 'Synthetic raw guard fixture', 'rights_confirmed_at' => now(), 'uploaded_by' => $this->actor->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function readyImage(string $slot): int
    {
        $id = $this->quarantinedImage($slot);
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

    private function insert(?int $release, ?string $slot, ?int $image): int
    {
        return DB::table('site_release_images')->insertGetId([
            'site_release_id' => $release, 'slot' => $slot, 'site_image_id' => $image, 'created_at' => now(),
        ]);
    }

    private function refused(callable $statement, string $case): void
    {
        $before = DB::table('site_release_images')->get()->all();
        try {
            $statement();
            $this->fail("The database accepted: {$case}");
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
        $this->assertEquals($before, DB::table('site_release_images')->get()->all(), $case);
    }

    private function trigger(string $name): ?string
    {
        return DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('type', 'trigger')->where('name', $name)->value('sql')
            : DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->where('TRIGGER_NAME', $name)->value('ACTION_STATEMENT');
    }

    private function allImageGuards(): array
    {
        $names = DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', 'like', 'site%image%')->orderBy('name')->pluck('name')->all()
            : DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())
                ->where('EVENT_OBJECT_TABLE', 'like', 'site%image%')->orderBy('TRIGGER_NAME')->pluck('TRIGGER_NAME')->all();

        return array_combine($names, array_map($this->trigger(...), $names));
    }

    private function retainedStructure(): array
    {
        return [
            $this->trigger('site_release_images_retain'), $this->trigger('site_release_images_immutable'),
            Schema::getIndexes('site_release_images'), Schema::getForeignKeys('site_release_images'),
        ];
    }

    /** Observe actual driver DDL and both installed guards during the overlap, including a rejected raw insert. */
    private function observeReplacement(string $created, string $dropped, callable $invalidInsert, callable $operation): array
    {
        $active = true;
        $ddl = [];
        DB::listen(function (QueryExecuted $query) use (&$active, &$ddl, $created, $dropped, $invalidInsert): void {
            if (! $active) {
                return;
            }
            if (str_starts_with($query->sql, 'CREATE TRIGGER '.$created.' ')) {
                $ddl[] = 'create';
                $this->assertNotNull($this->trigger($created));
                $this->assertNotNull($this->trigger($dropped));
                $this->refused($invalidInsert, 'during protective guard overlap');
                $this->assertStringContainsString(DB::getDriverName() === 'sqlite' ? 'RAISE(ABORT' : "SIGNAL SQLSTATE '45000'", $query->sql);
                if (DB::getDriverName() === 'mysql') {
                    foreach (['NEW.slot', 'i.slot', 'i.status'] as $column) {
                        $this->assertStringContainsString('CAST('.$column.' AS BINARY)', $query->sql);
                    }
                }
            } elseif ($query->sql === 'DROP TRIGGER '.$dropped) {
                $ddl[] = 'drop';
                $this->assertNotNull($this->trigger($created));
                $this->assertNull($this->trigger($dropped));
            }
        });
        try {
            $operation();
        } finally {
            $active = false;
        }

        return $ddl;
    }

    private function collision(string $name): void
    {
        DB::unprepared(DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER {$name} BEFORE INSERT ON site_release_images BEGIN SELECT 1; END"
            : "CREATE TRIGGER {$name} BEFORE INSERT ON site_release_images FOR EACH ROW BEGIN IF 1 = 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Synthetic collision'; END IF; END");
    }
}
