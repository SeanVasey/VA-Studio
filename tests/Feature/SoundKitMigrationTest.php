<?php

namespace Tests\Feature;

use App\Domain\SoundKits\Models\SoundKitRevision;
use App\Domain\SoundKits\SoundKitDrafts;
use App\Domain\SoundKits\SoundKitIntake;
use App\Domain\SoundKits\SoundKitProcessor;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\Support\StemsFixtures;
use Tests\TestCase;

class SoundKitMigrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_06_000044_sound_kit_intake.php');
    }

    private function clearEmpty(): void
    {
        // Disposable fixture reset: remove the transport child before its retained kit parents.
        // Operational rollback still retains these tables and is exercised separately below.
        foreach (['sound_kit_upload_sessions', 'sound_kit_revisions', 'sound_kit_drafts'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
            Schema::drop($table);
        }
    }

    private function refused(): void
    {
        $ddl = [];
        $watch = true;
        DB::listen(function ($query) use (&$ddl, &$watch): void {
            if ($watch && preg_match('/\A\s*(create|alter|drop|truncate|rename)\b/i', $query->sql)) {
                $ddl[] = $query->sql;
            }
        });
        try {
            $this->migration()->up();
            $this->fail('Existing schema was adopted');
        } catch (\LogicException $error) {
            $this->assertStringContainsString('inspection', $error->getMessage());
        } finally {
            $watch = false;
        }
        $this->assertSame([], $ddl);
    }

    private function denied(callable $write): void
    {
        try {
            $write();
            $this->fail('Retained evidence was mutated');
        } catch (QueryException $error) {
            $this->assertStringContainsString('Retain sound-kit', $error->getMessage());
        }
    }

    public function test_sql_identity_terminal_delete_replace_and_upsert_guards_preserve_exact_rows(): void
    {
        $this->fakePrivateMediaStorage();
        MediaFixtures::configure();
        $actor = LicenseFixtures::admin();
        $draft = app(SoundKitDrafts::class)->save(null, ['title' => 'Synthetic retention', 'provenance' => 'Synthetic source'], $actor);
        $revision = app(SoundKitIntake::class)->handle($draft->id, UploadedFile::fake()->createWithContent('kit.zip', StemsFixtures::zip([['name' => 'Kick.wav']])), 1, $actor);
        $this->denied(fn () => DB::table('sound_kit_revisions')->where('id', $revision->id)->update(['source_sha256' => str_repeat('a', 64)]));
        // The production collation is case-insensitive; immutable source evidence is byte-sensitive.
        $this->denied(fn () => DB::table('sound_kit_revisions')->where('id', $revision->id)->update(['original_name' => 'KIT.ZIP']));
        $this->denied(fn () => DB::table('sound_kit_revisions')->where('id', $revision->id)->update(['source_sha256' => strtoupper($revision->source_sha256)]));
        $this->denied(fn () => DB::table('sound_kit_drafts')->where('id', $draft->id)->update(['public_id' => strtoupper($draft->public_id)]));
        $ready = app(SoundKitProcessor::class)->handle($revision->id)->fresh();
        $this->assertSame('ready', $ready->status);
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA recursive_triggers = OFF');
        }
        foreach (['sound_kit_drafts' => $draft->fresh()->getAttributes(), 'sound_kit_revisions' => $ready->getAttributes()] as $table => $row) {
            $before = (array) DB::table($table)->where('id', $row['id'])->first();
            $this->denied(fn () => DB::table($table)->where('id', $row['id'])->delete());
            $this->denied(fn () => DB::table($table)->where('id', $row['id'])->update(['public_id' => (string) Str::uuid()]));
            $grammar = DB::connection()->getQueryGrammar();
            $columns = implode(', ', array_map($grammar->wrap(...), array_keys($row)));
            $values = implode(', ', array_fill(0, count($row), '?'));
            $this->denied(fn () => DB::statement('REPLACE INTO '.$grammar->wrapTable($table)." ({$columns}) VALUES ({$values})", array_values($row)));
            $this->denied(fn () => DB::table($table)->upsert([$row], ['id'], ['updated_at']));
            $this->assertSame($before, (array) DB::table($table)->where('id', $row['id'])->first());
        }
        $this->denied(fn () => DB::table('sound_kit_revisions')->where('id', $ready->id)->update(['status' => 'quarantined']));
        $this->migration()->down();
        $this->refused();
        $this->assertSame($ready->getAttributes(), SoundKitRevision::sole()->getAttributes());
    }

    public function test_replacement_by_secondary_unique_identity_is_also_refused(): void
    {
        $actor = LicenseFixtures::admin();
        $draft = app(SoundKitDrafts::class)->save(null, ['title' => 'Synthetic retained draft', 'provenance' => 'Test source'], $actor);
        $row = $draft->getAttributes();
        $row['id'] += 1000;
        $grammar = DB::connection()->getQueryGrammar();
        $columns = implode(', ', array_map($grammar->wrap(...), array_keys($row)));
        $values = implode(', ', array_fill(0, count($row), '?'));
        $this->denied(fn () => DB::statement("REPLACE INTO sound_kit_drafts ({$columns}) VALUES ({$values})", array_values($row)));
        $this->assertSame($draft->public_id, DB::table('sound_kit_drafts')->where('id', $draft->id)->value('public_id'));
    }

    public function test_interrupted_prefix_is_preserved_before_any_ddl(): void
    {
        $this->clearEmpty();
        Schema::create('sound_kit_drafts', fn (Blueprint $table) => $table->string('evidence'));
        DB::table('sound_kit_drafts')->insert(['evidence' => 'Retained foreign object']);
        $this->refused();
        $this->assertSame('Retained foreign object', DB::table('sound_kit_drafts')->value('evidence'));
        $this->assertFalse(Schema::hasTable('sound_kit_revisions'));
    }

    public function test_temporary_shadow_is_preserved_before_permanent_creation(): void
    {
        $this->clearEmpty();
        DB::statement('CREATE TEMPORARY TABLE sound_kit_revisions (evidence VARCHAR(100))');
        try {
            DB::table('sound_kit_revisions')->insert(['evidence' => 'Temporary source evidence']);
            $this->refused();
            $this->assertSame('Temporary source evidence', DB::table('sound_kit_revisions')->value('evidence'));
            $this->assertFalse(Schema::hasTable('sound_kit_drafts'));
        } finally {
            DB::statement('DROP TABLE sound_kit_revisions');
        }
    }

    public function test_foreign_view_and_trigger_names_are_not_adopted_or_removed(): void
    {
        $this->clearEmpty();
        DB::statement("CREATE VIEW sound_kit_revisions AS SELECT 'Retained view' AS evidence");
        try {
            $this->refused();
            $this->assertSame('Retained view', DB::table('sound_kit_revisions')->value('evidence'));
        } finally {
            DB::statement('DROP VIEW sound_kit_revisions');
        }
        Schema::create('synthetic_kit_owner', fn (Blueprint $table) => $table->id());
        $sql = DB::getDriverName() === 'sqlite'
            ? 'CREATE TRIGGER sound_kit_revisions_identity BEFORE DELETE ON synthetic_kit_owner BEGIN SELECT 1; END'
            : 'CREATE TRIGGER sound_kit_revisions_identity BEFORE DELETE ON synthetic_kit_owner FOR EACH ROW SET @kit_seen = 1';
        DB::unprepared($sql);
        try {
            $this->refused();
            $this->assertTrue(Schema::hasTable('synthetic_kit_owner'));
        } finally {
            Schema::drop('synthetic_kit_owner');
        }
    }
}
