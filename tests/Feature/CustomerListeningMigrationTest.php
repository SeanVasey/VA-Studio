<?php

namespace Tests\Feature;

use App\Domain\Customers\Listening\ListeningLibrary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\CustomerFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MembershipFixtures;
use Tests\TestCase;

class CustomerListeningMigrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_07_242000_customer_saved_tracks.php');
    }

    private function history(): array
    {
        return [DB::table('customer_accounts')->orderBy('id')->get()->toJson(),
            DB::table('membership_credit_events')->orderBy('id')->get()->toJson(),
            DB::table('customer_saved_tracks')->orderBy('id')->get()->toJson(),
            DB::table('migrations')->orderBy('id')->get()->toJson()];
    }

    public function test_repeated_complete_owned_install_preserves_customer_preferences_and_real_credit_history(): void
    {
        $customer = MembershipFixtures::bucket();
        app(ListeningLibrary::class)->change($customer['principal'], $customer['user'], ['action' => 'create-playlist', 'version' => 0, 'name' => 'Retained private list']);
        $before = $this->history();
        $this->migration()->up();
        $this->assertSame($before, $this->history());
        $this->assertSame('Retained private list', app(ListeningLibrary::class)->read($customer['principal'], $customer['user'])['playlists'][0]['name']);
    }

    public static function retainedStates(): array
    {
        return ['empty' => [false], 'populated' => [true]];
    }

    #[DataProvider('retainedStates')]
    public function test_rollback_always_refuses_before_query_and_retains_schema_history_and_repository_record(bool $populated): void
    {
        $customer = CustomerFixtures::account();
        if ($populated) {
            app(ListeningLibrary::class)->change($customer['principal'], $customer['user'], ['action' => 'create-playlist', 'version' => 0, 'name' => 'Retained list']);
        }
        $before = $this->history();
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        try {
            $this->migration()->down();
            $this->fail('Operational rollback accepted.');
        } catch (LogicException) {
            $this->assertSame(0, $queries, 'down must refuse without probing or touching any object.');
        }
        try {
            $this->artisan('migrate:rollback', ['--step' => 1])->run();
            $this->fail('Repository rollback accepted.');
        } catch (LogicException) {
            $this->assertSame($before, $this->history());
            $this->assertTrue(Schema::hasTable('customer_saved_tracks'));
        }
    }

    public static function drift(): array
    {
        return ['index' => ['CREATE INDEX listening_foreign_index ON customer_saved_tracks (version)'],
            'column' => ['ALTER TABLE customer_saved_tracks ADD COLUMN foreign_sentinel TEXT'],
            'trigger' => ["CREATE TRIGGER listening_foreign_trigger BEFORE DELETE ON customer_saved_tracks BEGIN SELECT RAISE(ABORT, 'Foreign retained object'); END"]];
    }

    #[DataProvider('drift')]
    public function test_foreign_or_drifted_objects_are_never_adopted_or_dropped(string $ddl): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite exact object drift cases; native metadata drift is independently verified.');
        }
        CustomerFixtures::account();
        DB::unprepared($ddl);
        $before = DB::select("SELECT * FROM sqlite_master WHERE tbl_name='customer_saved_tracks' ORDER BY name");
        $history = $this->history();
        foreach (['up', 'down'] as $method) {
            try {
                $this->migration()->$method();
                $this->fail('Drift was accepted.');
            } catch (LogicException) {
                $this->assertEquals($before, DB::select("SELECT * FROM sqlite_master WHERE tbl_name='customer_saved_tracks' ORDER BY name"));
                $this->assertSame($history, $this->history());
            }
        }
    }

    public function test_temporary_shadow_is_never_adopted_or_dropped(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite temporary catalog refusal is exercised on SQLite.');
        }
        DB::unprepared('CREATE TEMP TABLE customer_saved_tracks (private_sentinel TEXT)');
        DB::table('customer_saved_tracks')->insert(['private_sentinel' => 'SYNTHETIC PRIVATE']);
        try {
            foreach (['up', 'down'] as $method) {
                try {
                    $this->migration()->$method();
                    $this->fail('Temporary shadow was accepted.');
                } catch (LogicException) {
                    $this->assertSame('SYNTHETIC PRIVATE', DB::table('customer_saved_tracks')->value('private_sentinel'));
                }
            }
        } finally {
            DB::unprepared('DROP TABLE temp.customer_saved_tracks');
        }
        $this->assertTrue(Schema::hasTable('customer_saved_tracks'));
    }

    public static function interruptions(): array
    {
        return ['after complete up' => [false], 'after atomic CREATE event' => [true]];
    }

    #[DataProvider('interruptions')]
    public function test_real_migrator_restarts_an_exact_complete_unrecorded_install(bool $callback): void
    {
        MembershipFixtures::bucket();
        Schema::drop('customer_saved_tracks'); // Only this isolated disposable test table.
        DB::table('migrations')->where('migration', '2026_10_07_242000_customer_saved_tracks')->delete();
        $before = $this->historyWithoutListening();
        if ($callback) {
            $fired = false;
            DB::listen(function ($query) use (&$fired): void {
                if (! $fired && preg_match('/\bcreate\s+table\s+["`]?customer_saved_tracks\b/i', $query->sql)) {
                    $fired = true;
                    throw new RuntimeException('SYNTHETIC interrupted listening CREATE.');
                }
            });
            try {
                $this->artisan('migrate', ['--path' => 'database/migrations/2026_10_07_242000_customer_saved_tracks.php', '--force' => true])->run();
                $this->fail('Interrupted CREATE did not propagate.');
            } catch (RuntimeException $error) {
                $this->assertSame('SYNTHETIC interrupted listening CREATE.', $error->getMessage());
            }
            $this->assertTrue($fired);
        } else {
            $this->migration()->up();
        }
        $schema = [Schema::getColumns('customer_saved_tracks'), Schema::getIndexes('customer_saved_tracks'), Schema::getForeignKeys('customer_saved_tracks')];
        $this->assertSame(0, DB::table('migrations')->where('migration', '2026_10_07_242000_customer_saved_tracks')->count());
        $this->artisan('migrate', ['--path' => 'database/migrations/2026_10_07_242000_customer_saved_tracks.php', '--force' => true])->assertExitCode(0);
        $this->assertEquals($schema, [Schema::getColumns('customer_saved_tracks'), Schema::getIndexes('customer_saved_tracks'), Schema::getForeignKeys('customer_saved_tracks')]);
        $this->assertSame($before, $this->historyWithoutListening());
        $this->assertDatabaseCount('customer_saved_tracks', 0);
        $this->assertSame(1, DB::table('migrations')->where('migration', '2026_10_07_242000_customer_saved_tracks')->count());
    }

    private function historyWithoutListening(): array
    {
        return [DB::table('customer_accounts')->orderBy('id')->get()->toJson(), DB::table('membership_credit_events')->orderBy('id')->get()->toJson()];
    }
}
