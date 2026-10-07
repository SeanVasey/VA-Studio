<?php

namespace Tests\Feature;

use App\Domain\Customers\Listening\ListeningLibrary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
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

    public function test_empty_owned_rollback_and_reinstall_preserve_preexisting_customer_and_membership_history(): void
    {
        $customer = MembershipFixtures::bucket();
        $before = DB::table('customer_accounts')->get()->toJson();
        $creditHistory = DB::table('membership_credit_events')->get()->toJson();
        $membershipColumns = Schema::getColumnListing('membership_credit_events');
        $this->migration()->down();
        $this->assertFalse(Schema::hasTable('customer_saved_tracks'));
        $this->migration()->up();
        $this->assertSame($before, DB::table('customer_accounts')->get()->toJson());
        $this->assertSame($creditHistory, DB::table('membership_credit_events')->get()->toJson());
        $this->assertSame($membershipColumns, Schema::getColumnListing('membership_credit_events'));
        $this->assertSame([], app(ListeningLibrary::class)->read($customer['principal'], $customer['user'])['playlists']);
    }

    public function test_populated_rollback_refuses_before_touching_lists_or_migration_bookkeeping(): void
    {
        $customer = CustomerFixtures::account();
        app(ListeningLibrary::class)->change($customer['principal'], $customer['user'], ['action' => 'create-playlist', 'version' => 0, 'name' => 'Retained list']);
        $before = DB::table('customer_saved_tracks')->get()->toJson();
        $migration = DB::table('migrations')->where('migration', '2026_10_07_242000_customer_saved_tracks')->sole();
        try {
            $this->artisan('migrate:rollback', ['--step' => 1])->run();
            $this->fail('Populated rollback accepted.');
        } catch (LogicException) {
            $this->assertSame($before, DB::table('customer_saved_tracks')->get()->toJson());
            $this->assertEquals($migration, DB::table('migrations')->where('migration', '2026_10_07_242000_customer_saved_tracks')->sole());
        }
    }

    public function test_existing_and_temporary_objects_are_refused_without_overwriting_either(): void
    {
        try {
            $this->migration()->up();
            $this->fail('Existing table was accepted.');
        } catch (LogicException) {
            $this->assertTrue(Schema::hasTable('customer_saved_tracks'));
        }
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
}
