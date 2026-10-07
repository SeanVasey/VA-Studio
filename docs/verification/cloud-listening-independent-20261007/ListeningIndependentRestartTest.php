<?php

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\CustomerFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

class ListeningIndependentRestartTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    #[DataProvider('interruptionModes')]
    public function test_complete_unlogged_create_can_retry_without_replacing_owned_schema_or_customer_history(string $mode): void
    {
        $customer = CustomerFixtures::account();
        $historical = DB::table('customer_accounts')->orderBy('id')->get()->toJson();
        Schema::drop('customer_saved_tracks');
        DB::table('migrations')->where('migration', '2026_10_07_242000_customer_saved_tracks')->delete();
        if ($mode === 'complete') {
            // Simulate process loss after up() succeeds but before Laravel logs the migration.
            (require database_path('migrations/2026_10_07_242000_customer_saved_tracks.php'))->up();
        } else {
            $fired = false;
            DB::listen(function (QueryExecuted $query) use (&$fired): void {
                if (! $fired && preg_match('/\bcreate\s+table\s+["`]?customer_saved_tracks\b/i', $query->sql)) {
                    $fired = true;
                    throw new RuntimeException('Synthetic failure after successful listening CREATE statement.');
                }
            });
            try {
                $this->artisan('migrate', ['--path' => 'database/migrations/2026_10_07_242000_customer_saved_tracks.php', '--force' => true])->run();
                $this->fail('Synthetic post-CREATE failure did not propagate.');
            } catch (RuntimeException $error) {
                $this->assertSame('Synthetic failure after successful listening CREATE statement.', $error->getMessage());
            }
            $this->assertTrue($fired, 'Create callback was not exercised.');
        }
        $this->assertTrue(Schema::hasTable('customer_saved_tracks'));
        $this->assertSame(0, DB::table('migrations')->where('migration', '2026_10_07_242000_customer_saved_tracks')->count());
        $before = json_encode([
            'columns' => Schema::getColumns('customer_saved_tracks'),
            'indexes' => Schema::getIndexes('customer_saved_tracks'),
            'foreignKeys' => Schema::getForeignKeys('customer_saved_tracks'),
        ], JSON_THROW_ON_ERROR);
        $this->artisan('migrate', ['--path' => 'database/migrations/2026_10_07_242000_customer_saved_tracks.php', '--force' => true])->assertExitCode(0);
        $after = json_encode([
            'columns' => Schema::getColumns('customer_saved_tracks'),
            'indexes' => Schema::getIndexes('customer_saved_tracks'),
            'foreignKeys' => Schema::getForeignKeys('customer_saved_tracks'),
        ], JSON_THROW_ON_ERROR);
        $this->assertSame($before, $after);
        $this->assertSame(1, DB::table('migrations')->where('migration', '2026_10_07_242000_customer_saved_tracks')->count());
        $this->assertSame($historical, DB::table('customer_accounts')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('customer_saved_tracks', 0);
        $this->assertSame($customer['user']->id, $customer['account']->fresh()->user_id);
    }

    public static function interruptionModes(): array
    {
        return ['complete unlogged' => ['complete'], 'callback interrupted' => ['callback']];
    }
}
