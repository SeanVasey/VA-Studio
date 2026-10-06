<?php

namespace Tests\Feature;

use App\Domain\Inquiries\InquiryAdministration;
use App\Domain\Inquiries\InquiryConversation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use RuntimeException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\InquiryConversationFixtures as Fixture;
use Tests\TestCase;

class InquiryMessageMigrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_10_06_000043_inquiry_messages.php');
    }

    public function test_empty_rollback_and_each_installation_prefix_resume_without_changing_parent_evidence(): void
    {
        $fixture = Fixture::create();
        $parent = $fixture['inquiry']->getAttributes();
        $migration = $this->migration();
        $migration->down();
        $this->assertFalse(Schema::hasTable('inquiry_messages'));
        $ddl = [];
        $active = true;
        $stop = null;
        DB::listen(function ($event) use (&$ddl, &$active, &$stop): void {
            if ($active && preg_match('/\A(?:create|alter|drop)\b/i', $event->sql)) {
                $ddl[] = $event->sql;
                if ($stop === count($ddl)) {
                    throw new RuntimeException('Synthetic interruption after committed DDL.');
                }
            }
        });
        $migration->up();
        $total = count($ddl);
        $this->assertGreaterThan(4, $total);
        for ($prefix = 1; $prefix <= $total; $prefix++) {
            $active = false;
            $migration->down();
            $ddl = [];
            $stop = $prefix;
            $active = true;
            try {
                $migration->up();
                $this->fail('DDL interruption did not execute.');
            } catch (RuntimeException) {
            }
            $active = false;
            $migration->up();
            $migration->up();
            $this->assertTrue(Schema::hasTable('inquiry_messages'));
            $this->assertSame($parent, $fixture['inquiry']->fresh()->getAttributes());
        }
        $result = app(InquiryConversation::class)->reply($fixture['inquiry']->id, Fixture::message(), $fixture['actor']);
        $this->assertGreaterThan(0, $result['messageId']);
    }

    public function test_sql_updates_deletes_replace_and_archived_inserts_cannot_rewrite_messages(): void
    {
        ['actor' => $actor, 'inquiry' => $inquiry] = Fixture::create();
        app(InquiryConversation::class)->reply($inquiry->id, Fixture::message(), $actor);
        $row = (array) DB::table('inquiry_messages')->sole();
        $this->rejected(fn () => DB::table('inquiry_messages')->where('id', $row['id'])->update(['body' => 'changed']));
        $this->rejected(fn () => DB::table('inquiry_messages')->where('id', $row['id'])->delete());
        $columns = array_keys($row);
        $grammar = DB::connection()->getQueryGrammar();
        $replace = (DB::getDriverName() === 'sqlite' ? 'INSERT OR REPLACE' : 'REPLACE').' INTO inquiry_messages ('
            .implode(', ', array_map($grammar->wrap(...), $columns)).') VALUES ('.implode(', ', array_fill(0, count($row), '?')).')';
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA recursive_triggers = OFF');
        }
        $this->rejected(fn () => DB::statement($replace, array_values($row)));
        $this->assertSame($row, (array) DB::table('inquiry_messages')->sole());
        app(InquiryAdministration::class)->transition($inquiry->id, 'archived', 0, $actor);
        unset($row['id']);
        $row['request_key'] = Fixture::message()['requestKey'];
        $this->rejected(fn () => DB::table('inquiry_messages')->insert($row));
        $this->assertDatabaseCount('inquiry_messages', 1);
        $this->expectException(LogicException::class);
        $this->migration()->down();
    }

    public function test_foreign_schema_or_trigger_cannot_be_adopted_or_destroyed(): void
    {
        $this->migration()->down();
        Schema::create('inquiry_messages', fn ($table) => $table->id());
        $before = Schema::getColumns('inquiry_messages');
        foreach (['up', 'down'] as $operation) {
            try {
                $this->migration()->{$operation}();
                $this->fail('Foreign table was accepted.');
            } catch (LogicException) {
            }
            $this->assertSame($before, Schema::getColumns('inquiry_messages'));
        }
    }

    public function test_retained_unprotected_messages_are_not_retroactively_adopted(): void
    {
        ['actor' => $actor, 'inquiry' => $inquiry] = Fixture::create();
        app(InquiryConversation::class)->reply($inquiry->id, Fixture::message(), $actor);
        $before = DB::table('inquiry_messages')->get()->toJson();
        DB::unprepared('DROP TRIGGER inquiry_messages_update');
        try {
            $this->migration()->up();
            $this->fail('Missing retained guard was silently restored.');
        } catch (LogicException) {
        }
        $this->assertSame($before, DB::table('inquiry_messages')->get()->toJson());
    }

    private function rejected(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Immutable message SQL was accepted.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
