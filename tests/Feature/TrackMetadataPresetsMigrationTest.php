<?php

namespace Tests\Feature;

use App\Domain\Catalog\TrackMetadataPresets;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

class TrackMetadataPresetsMigrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const TABLE = 'track_metadata_presets';

    private const INDEX = 'track_metadata_presets_archived_at_index';

    private function migration(): object
    {
        return require database_path('migrations/2026_10_02_000033_track_metadata_presets.php');
    }

    private function rows(): string
    {
        return DB::table(self::TABLE)->orderBy('id')->get()->toJson();
    }

    private function ddlDuring(callable $operation): array
    {
        $statements = [];
        $inspect = true;
        DB::listen(function ($query) use (&$statements, &$inspect): void {
            if ($inspect && preg_match('/\A\s*(?:create|alter|drop|truncate|rename)\b/i', $query->sql)) {
                $statements[] = $query->sql;
            }
        });
        try {
            $operation();
        } finally {
            $inspect = false;
        }

        return $statements;
    }

    private function rejectedRetry(): array
    {
        return $this->ddlDuring(function (): void {
            try {
                $this->migration()->up();
                $this->fail('Migration replaced or repaired an unowned schema object.');
            } catch (LogicException $error) {
                $this->assertStringContainsString('existing schema and data are unchanged', $error->getMessage());
            }
        });
    }

    public function test_populated_retry_and_operational_rollback_preserve_rows_audits_and_schema_without_ddl(): void
    {
        $actor = LicenseFixtures::admin();
        $command = app(TrackMetadataPresets::class);
        $active = $command->handle(null, ['name' => 'Retained active', 'tags' => ['Literal order', 'Second']], $actor);
        $archived = $command->handle(null, ['name' => 'Retained archived'], $actor);
        $command->archive($archived, 1, $actor);
        $before = [$this->rows(), DB::table('audit_events')->orderBy('id')->get()->toJson(), Schema::getColumns(self::TABLE), Schema::getIndexes(self::TABLE)];
        $this->assertSame([], $this->ddlDuring(function (): void {
            $this->migration()->up();
            $this->migration()->down();
            $this->migration()->up();
        }));
        $this->assertSame($before, [$this->rows(), DB::table('audit_events')->orderBy('id')->get()->toJson(), Schema::getColumns(self::TABLE), Schema::getIndexes(self::TABLE)]);
        $this->assertSame(['Literal order', 'Second'], $command->snapshot($active->id, $actor)['metadata']['tags']);
    }

    public function test_interrupted_table_creation_resumes_only_the_missing_owned_index_and_keeps_retained_data(): void
    {
        $actor = LicenseFixtures::admin();
        app(TrackMetadataPresets::class)->handle(null, ['name' => 'Retained interrupted row'], $actor);
        Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropIndex(self::INDEX));
        $before = $this->rows();
        $ddl = $this->ddlDuring(fn () => $this->migration()->up());
        $this->assertCount(1, $ddl);
        $this->assertStringContainsString(self::INDEX, $ddl[0]);
        $this->assertSame($before, $this->rows());
        $index = collect(Schema::getIndexes(self::TABLE))->firstWhere('name', self::INDEX);
        $this->assertNotNull($index);
        $this->assertSame(['archived_at'], $index['columns']);
        $this->assertFalse($index['unique']);
        $this->assertSame([], $this->ddlDuring(fn () => $this->migration()->up()));
    }

    public function test_extra_index_is_refused_before_repairing_a_missing_owned_index(): void
    {
        app(TrackMetadataPresets::class)->handle(null, ['name' => 'Retained foreign-index row'], LicenseFixtures::admin());
        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
            $table->index('name', 'synthetic_unowned_preset_index');
        });
        $before = [$this->rows(), Schema::getColumns(self::TABLE), Schema::getIndexes(self::TABLE)];
        $this->assertSame([], $this->rejectedRetry());
        $this->assertSame($before, [$this->rows(), Schema::getColumns(self::TABLE), Schema::getIndexes(self::TABLE)]);
    }

    public function test_extra_column_is_refused_without_rewriting_retained_metadata(): void
    {
        app(TrackMetadataPresets::class)->handle(null, ['name' => 'Retained drift row'], LicenseFixtures::admin());
        Schema::table(self::TABLE, fn (Blueprint $table) => $table->string('synthetic_unowned_column')->nullable());
        $before = [$this->rows(), Schema::getColumns(self::TABLE), Schema::getIndexes(self::TABLE)];
        $this->assertSame([], $this->rejectedRetry());
        $this->assertSame($before, [$this->rows(), Schema::getColumns(self::TABLE), Schema::getIndexes(self::TABLE)]);
    }

    public function test_temporary_table_shadow_is_refused_before_touching_permanent_rows_or_schema(): void
    {
        app(TrackMetadataPresets::class)->handle(null, ['name' => 'Retained permanent row'], LicenseFixtures::admin());
        $before = [$this->rows(), Schema::getColumns(self::TABLE), Schema::getIndexes(self::TABLE)];
        DB::statement('CREATE TEMPORARY TABLE track_metadata_presets (synthetic_unowned_column INTEGER)');
        try {
            $this->assertSame([], $this->rejectedRetry());
        } finally {
            DB::statement(DB::getDriverName() === 'mysql' ? 'DROP TEMPORARY TABLE track_metadata_presets' : 'DROP TABLE temp.track_metadata_presets');
        }
        $this->assertSame($before, [$this->rows(), Schema::getColumns(self::TABLE), Schema::getIndexes(self::TABLE)]);
    }
}
