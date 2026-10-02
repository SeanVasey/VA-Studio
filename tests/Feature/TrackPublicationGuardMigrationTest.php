<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\SaveTrackMetadata;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

class TrackPublicationGuardMigrationTest extends TestCase
{
    use DatabaseMigrations;

    private const GUARDS = ['tracks_publication_version_insert', 'tracks_publication_version_update'];

    private function migration(): object
    {
        return require database_path('migrations/2026_10_02_000034_track_publication_version.php');
    }

    private function dropGuards(): void
    {
        foreach (self::GUARDS as $guard) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$guard);
        }
    }

    private function rows(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), ['tracks', 'audit_events']);
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
                $this->fail('Publication migration accepted unowned or invalid retained schema.');
            } catch (LogicException) {
            }
        });
    }

    private function draft(): Track
    {
        return app(SaveTrackMetadata::class)->handle(null, ['title' => 'Retained migration fixture', 'slug' => 'retained-migration-fixture'], LicenseFixtures::admin());
    }

    public function test_legacy_rows_receive_zero_without_inventing_publication_history_or_rewriting_any_other_field(): void
    {
        $track = $this->draft();
        DB::table('tracks')->where('id', $track->id)->update(['published_slug' => $track->slug, 'published_at' => '2026-08-01 12:00:00']);
        $this->dropGuards();
        Schema::table('tracks', fn (Blueprint $table) => $table->dropColumn('publication_version'));
        $before = $this->rows();
        $columns = Schema::getColumns('tracks');
        $this->migration()->up();
        $after = DB::table('tracks')->orderBy('id')->get()->map(function ($row) {
            $this->assertSame(0, $row->publication_version);
            unset($row->publication_version);

            return $row;
        })->toJson();
        $this->assertSame($before[0], $after);
        $this->assertSame($before[1], $this->rows()[1]);
        $this->assertCount(count($columns) + 1, Schema::getColumns('tracks'));
        $this->assertSame('2026-08-01 12:00:00', DB::table('tracks')->where('id', $track->id)->value('published_at'));
        $this->assertSame($track->slug, DB::table('tracks')->where('id', $track->id)->value('published_slug'));
    }

    public function test_complete_retry_and_operational_down_preserve_nonzero_rows_audits_and_guards_without_ddl(): void
    {
        $track = $this->draft();
        DB::table('tracks')->where('id', $track->id)->update(['publication_version' => 17]);
        $before = [$this->rows(), Schema::getColumns('tracks'), Schema::getIndexes('tracks')];
        $this->assertSame([], $this->ddlDuring(function (): void {
            $this->migration()->up();
            $this->migration()->down();
            $this->migration()->up();
        }));
        $this->assertSame($before, [$this->rows(), Schema::getColumns('tracks'), Schema::getIndexes('tracks')]);
        $this->assertSame(17, $track->fresh()->publication_version);
        try {
            DB::table('tracks')->where('id', $track->id)->update(['publication_version' => 16]);
            $this->fail('Operational down removed the retained monotonic guard.');
        } catch (QueryException) {
        }
        $this->assertSame($before[0], $this->rows());
    }

    public function test_only_the_missing_owned_guard_suffix_is_restored_after_an_interrupted_install(): void
    {
        $track = $this->draft();
        DB::table('tracks')->where('id', $track->id)->update(['publication_version' => 9]);
        $before = $this->rows();
        DB::unprepared('DROP TRIGGER '.self::GUARDS[1]);
        $ddl = $this->ddlDuring(fn () => $this->migration()->up());
        $this->assertCount(1, $ddl);
        $this->assertStringContainsString(self::GUARDS[1], $ddl[0]);
        $this->assertSame($before, $this->rows());
        $this->dropGuards();
        $ddl = $this->ddlDuring(fn () => $this->migration()->up());
        $this->assertCount(2, $ddl);
        foreach (self::GUARDS as $index => $guard) {
            $this->assertStringContainsString($guard, $ddl[$index]);
        }
        $this->assertSame($before, $this->rows());
        $this->assertSame([], $this->ddlDuring(fn () => $this->migration()->up()));
    }

    public function test_sql_range_integer_null_and_monotonic_guards_refuse_invalid_rows_without_changing_retained_data(): void
    {
        $track = $this->draft();
        DB::table('tracks')->where('id', $track->id)->update(['publication_version' => 2147483647]);
        $before = $this->rows();
        foreach ([-1, 2147483648, null] as $value) {
            foreach (['insert', 'update'] as $operation) {
                try {
                    if ($operation === 'insert') {
                        DB::table('tracks')->insert(['title' => 'Invalid counter', 'slug' => 'invalid-counter', 'status' => 'draft', 'publication_version' => $value]);
                    } else {
                        DB::table('tracks')->where('id', $track->id)->update(['publication_version' => $value]);
                    }
                    $this->fail('SQL accepted an invalid publication counter.');
                } catch (QueryException) {
                }
            }
        }
        try {
            DB::table('tracks')->where('id', $track->id)->update(['publication_version' => 2147483646]);
            $this->fail('SQL publication revision moved backwards.');
        } catch (QueryException) {
        }
        $this->assertSame($before, $this->rows());
    }

    public function test_foreign_definition_at_an_owned_guard_name_is_refused_before_any_repair_ddl(): void
    {
        $this->draft();
        $this->dropGuards();
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('CREATE TRIGGER tracks_publication_version_insert BEFORE INSERT ON tracks BEGIN SELECT 1; END');
        } else {
            DB::unprepared('CREATE TRIGGER tracks_publication_version_insert BEFORE INSERT ON tracks FOR EACH ROW SET @synthetic_publication_guard = 1');
        }
        $before = [$this->rows(), Schema::getColumns('tracks')];
        $this->assertSame([], $this->rejectedRetry());
        $this->assertSame($before, [$this->rows(), Schema::getColumns('tracks')]);
        $this->dropGuards();
        $this->migration()->up();
    }

    public function test_incompatible_column_is_refused_before_creating_guards_and_retained_rows_stay_exact(): void
    {
        $this->draft();
        $this->dropGuards();
        Schema::table('tracks', fn (Blueprint $table) => $table->dropColumn('publication_version'));
        Schema::table('tracks', fn (Blueprint $table) => $table->bigInteger('publication_version')->nullable());
        $before = [$this->rows(), Schema::getColumns('tracks')];
        $this->assertSame([], $this->rejectedRetry());
        $this->assertSame($before, [$this->rows(), Schema::getColumns('tracks')]);
        Schema::table('tracks', fn (Blueprint $table) => $table->dropColumn('publication_version'));
        $this->migration()->up();
    }

    public function test_invalid_values_left_by_an_interrupted_install_are_refused_before_any_guard_ddl(): void
    {
        $track = $this->draft();
        $this->dropGuards();
        DB::table('tracks')->where('id', $track->id)->update(['publication_version' => -1]);
        $before = [$this->rows(), Schema::getColumns('tracks')];
        $this->assertSame([], $this->rejectedRetry());
        $this->assertSame($before, [$this->rows(), Schema::getColumns('tracks')]);
        DB::table('tracks')->where('id', $track->id)->update(['publication_version' => 0]);
        $this->migration()->up();
    }

    public function test_temporary_table_shadow_is_refused_without_touching_permanent_rows_or_schema(): void
    {
        $this->draft();
        $before = [$this->rows(), Schema::getColumns('tracks')];
        DB::statement('CREATE TEMPORARY TABLE tracks (synthetic_unowned_column INTEGER)');
        try {
            $this->assertSame([], $this->rejectedRetry());
        } finally {
            DB::statement(DB::getDriverName() === 'mysql' ? 'DROP TEMPORARY TABLE tracks' : 'DROP TABLE temp.tracks');
        }
        $this->assertSame($before, [$this->rows(), Schema::getColumns('tracks')]);
    }

    public function test_update_only_installation_is_refused_instead_of_repairing_an_impossible_creation_prefix(): void
    {
        $this->draft();
        DB::unprepared('DROP TRIGGER '.self::GUARDS[0]);
        $before = [$this->rows(), Schema::getColumns('tracks')];
        $this->assertSame([], $this->rejectedRetry());
        $this->assertSame($before, [$this->rows(), Schema::getColumns('tracks')]);
        $this->dropGuards();
        $this->migration()->up();
    }

    public function test_unowned_index_on_the_revision_column_is_refused_before_restoring_a_missing_guard(): void
    {
        $this->draft();
        DB::unprepared('DROP TRIGGER '.self::GUARDS[1]);
        Schema::table('tracks', fn (Blueprint $table) => $table->index('publication_version', 'synthetic_publication_counter_index'));
        $before = [$this->rows(), Schema::getColumns('tracks'), Schema::getIndexes('tracks')];
        $this->assertSame([], $this->rejectedRetry());
        $this->assertSame($before, [$this->rows(), Schema::getColumns('tracks'), Schema::getIndexes('tracks')]);
        Schema::table('tracks', fn (Blueprint $table) => $table->dropIndex('synthetic_publication_counter_index'));
        $this->migration()->up();
    }
}
