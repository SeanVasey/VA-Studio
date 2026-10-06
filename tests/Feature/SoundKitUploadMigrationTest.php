<?php

namespace Tests\Feature;

use App\Domain\SoundKits\Models\SoundKitUploadSession;
use App\Domain\SoundKits\SoundKitDrafts;
use App\Domain\SoundKits\SoundKitUploads;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\Support\StemsFixtures;
use Tests\TestCase;

class SoundKitUploadMigrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_06_000046_sound_kit_upload_sessions.php');
    }

    private function refused(callable $operation): void
    {
        try {
            $operation();
            $this->fail('A retained identity mutation was allowed.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    private function fixture(): array
    {
        $this->fakePrivateMediaStorage();
        MediaFixtures::configure();
        $actor = LicenseFixtures::admin();
        $draft = app(SoundKitDrafts::class)->save(null, ['title' => 'Synthetic transport guards', 'provenance' => 'Synthetic test source'], $actor);
        $bytes = StemsFixtures::zip([['name' => 'Sample.wav']]);
        $session = app(SoundKitUploads::class)->start($draft, 1, strlen($bytes), hash('sha256', $bytes), 'KIT.zip', $actor);

        return [$actor, $session, $bytes];
    }

    public function test_sql_byte_exact_identity_replacement_and_deletion_guards_preserve_retained_upload(): void
    {
        [$actor, $session] = $this->fixture();
        $before = (array) DB::table('sound_kit_upload_sessions')->sole();
        foreach (['id' => $before['id'] + 10, 'public_id' => strtoupper($before['public_id']), 'expected_version' => 2,
            'profile_sha256' => strtoupper($before['profile_sha256']), 'sha256' => strtoupper($before['sha256']),
            'original_name' => 'kit.zip', 'size_bytes' => $before['size_bytes'] + 1, 'expires_at' => '2030-01-01 00:00:00'] as $field => $value) {
            $this->refused(fn () => DB::table('sound_kit_upload_sessions')->where('id', $before['id'])->update([$field => $value]));
            $this->assertSame($before, (array) DB::table('sound_kit_upload_sessions')->sole());
        }
        $this->refused(fn () => DB::table('sound_kit_upload_sessions')->where('id', $before['id'])->delete());
        $replace = DB::getDriverName() === 'mysql' ? 'REPLACE' : 'INSERT OR REPLACE';
        $columns = implode(', ', array_map(fn ($name) => DB::connection()->getQueryGrammar()->wrap($name), array_keys($before)));
        $values = implode(', ', array_fill(0, count($before), '?'));
        $this->refused(fn () => DB::statement("{$replace} INTO sound_kit_upload_sessions ({$columns}) VALUES ({$values})", array_values($before)));
        $this->refused(fn () => DB::table('sound_kit_upload_sessions')->upsert([$before], ['public_id'], ['original_name']));
        $this->migration()->down();
        $this->assertSame($before, (array) DB::table('sound_kit_upload_sessions')->sole());
        $this->assertSame($session, app(SoundKitUploads::class)->inspect($session['id'], $actor));
    }

    public function test_terminal_result_is_immutable_while_acknowledged_cleanup_remains_valid(): void
    {
        [$actor, $session, $bytes] = $this->fixture();
        app(SoundKitUploads::class)->append($session['id'], 0, UploadedFile::fake()->createWithContent('part.bin', $bytes), $actor);
        $revision = app(SoundKitUploads::class)->complete($session['id'], $actor);
        $before = SoundKitUploadSession::sole()->getAttributes();
        foreach (['status' => 'uploading', 'revision_id' => null, 'received_bytes' => 0, 'parts' => '[]'] as $field => $value) {
            $this->refused(fn () => DB::table('sound_kit_upload_sessions')->update([$field => $value]));
        }
        $this->assertSame($before, SoundKitUploadSession::sole()->getAttributes());
        $this->assertSame($revision->id, app(SoundKitUploads::class)->complete($session['id'], $actor)->id);
        $this->assertNotNull(SoundKitUploadSession::sole()->cleaned_at);
        $this->assertCount(3, Schema::getForeignKeys('sound_kit_upload_sessions'));
    }

    public function test_existing_table_or_temporary_shadow_is_preserved_before_migration_writes(): void
    {
        $this->fixture();
        $before = (array) DB::table('sound_kit_upload_sessions')->sole();
        try {
            $this->migration()->up();
            $this->fail('Existing retained transport schema was adopted.');
        } catch (LogicException) {
        }
        $this->assertSame($before, (array) DB::table('sound_kit_upload_sessions')->sole());
        DB::statement('CREATE TEMPORARY TABLE sound_kit_upload_sessions (sentinel INTEGER)');
        try {
            DB::table('sound_kit_upload_sessions')->insert(['sentinel' => 67]);
            try {
                $this->migration()->up();
                $this->fail('Temporary shadow was adopted.');
            } catch (LogicException) {
            }
            $this->assertSame(67, (int) DB::table('sound_kit_upload_sessions')->value('sentinel'));
        } finally {
            DB::statement(DB::getDriverName() === 'mysql' ? 'DROP TEMPORARY TABLE sound_kit_upload_sessions' : 'DROP TABLE temp.sound_kit_upload_sessions');
        }
        $this->assertSame($before, (array) DB::table('sound_kit_upload_sessions')->sole());
    }

    public function test_unjournaled_partial_prefix_or_foreign_trigger_is_not_adopted(): void
    {
        Schema::drop('sound_kit_upload_sessions');
        Schema::create('sound_kit_upload_sessions', fn ($table) => $table->integer('sentinel'));
        DB::table('sound_kit_upload_sessions')->insert(['sentinel' => 91]);
        try {
            $this->migration()->up();
            $this->fail('Interrupted table was adopted.');
        } catch (LogicException) {
        }
        $this->assertSame(91, (int) DB::table('sound_kit_upload_sessions')->value('sentinel'));
        Schema::drop('sound_kit_upload_sessions');
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('CREATE TRIGGER sound_kit_upload_identity BEFORE INSERT ON sound_kit_drafts FOR EACH ROW SET NEW.version = NEW.version');
        } else {
            DB::unprepared('CREATE TRIGGER sound_kit_upload_identity BEFORE INSERT ON sound_kit_drafts BEGIN SELECT 1; END');
        }
        try {
            $this->migration()->up();
            $this->fail('Foreign trigger identity was adopted.');
        } catch (LogicException) {
        }
        $this->assertFalse(Schema::hasTable('sound_kit_upload_sessions'));
        $this->assertTrue(DB::getDriverName() === 'mysql'
            ? DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->where('TRIGGER_NAME', 'sound_kit_upload_identity')->exists()
            : DB::table('sqlite_master')->where('name', 'sound_kit_upload_identity')->exists());
    }
}
