<?php

namespace Tests\Feature;

use App\Domain\ProductAuthoring\PrivateDraftSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\PrivateProductDraftFixtures as Fixtures;
use Tests\TestCase;

class PrivateProductDraftSchemaTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public static function families(): array
    {
        return Fixtures::families();
    }

    #[DataProvider('families')]
    public function test_prefixed_schema_owns_only_its_tables_guards_indexes_and_foreign_keys(string $kind): void
    {
        $connection = DB::connection();
        $oldPrefix = $connection->getTablePrefix();
        $originalTables = [$kind.'_drafts', $kind.'_draft_versions'];
        $this->assertTrue(Schema::hasTable($originalTables[0]));
        $this->assertTrue(Schema::hasTable($originalTables[1]));
        $connection->setTablePrefix('private_');
        try {
            Schema::create('users', function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->id();
            });
            PrivateDraftSchema::install($kind);
            $this->assertTrue(Schema::hasTable($kind.'_drafts'));
            $this->assertTrue(Schema::hasTable($kind.'_draft_versions'));
            $driver = DB::getDriverName();
            $names = $driver === 'sqlite'
                ? DB::select("SELECT name FROM sqlite_master WHERE type = 'trigger' AND name LIKE ?", ['private_'.$kind.'%'])
                : DB::select('SELECT TRIGGER_NAME AS name FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? AND TRIGGER_NAME LIKE ?', [DB::getDatabaseName(), 'private_'.$kind.'%']);
            $this->assertCount(6, $names);
            try {
                PrivateDraftSchema::install($kind);
                $this->fail('Owned prefix schemas cannot be adopted on a second up().');
            } catch (LogicException) {
                $this->assertTrue(Schema::hasTable($kind.'_draft_versions'));
            }
        } finally {
            Schema::dropIfExists($kind.'_draft_versions');
            Schema::dropIfExists($kind.'_drafts');
            Schema::dropIfExists('users');
            $connection->setTablePrefix($oldPrefix);
        }
        foreach ($originalTables as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
    }

    #[DataProvider('families')]
    public function test_temporary_prefix_collision_refuses_before_any_owned_ddl_and_preserves_the_foreign_object(string $kind): void
    {
        $connection = DB::connection();
        $oldPrefix = $connection->getTablePrefix();
        $connection->setTablePrefix('Private_');
        $physical = $connection->getQueryGrammar()->wrap('Private_'.$kind.'_drafts');
        try {
            DB::statement('CREATE TEMPORARY TABLE '.$physical.' (marker INTEGER)');
            DB::statement('INSERT INTO '.$physical.' (marker) VALUES (314)');
            try {
                PrivateDraftSchema::install($kind);
                $this->fail('Foreign temporary objects must never be adopted or overwritten.');
            } catch (LogicException) {
                $this->assertSame(314, (int) DB::selectOne('SELECT marker FROM '.$physical)->marker);
                $this->assertFalse(Schema::hasTable($kind.'_draft_versions'));
            }
        } finally {
            DB::statement('DROP TABLE IF EXISTS '.$physical);
            $connection->setTablePrefix($oldPrefix);
        }
    }
}
