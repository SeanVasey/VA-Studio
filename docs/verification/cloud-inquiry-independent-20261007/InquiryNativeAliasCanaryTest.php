<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

final class InquiryNativeAliasCanaryTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public function test_native_reserved_trigger_alias_is_refused_before_any_schema_write(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Native identifier dictionary semantics require MySQL.');
        }
        $migration = require base_path('database/migrations/2026_10_07_243000_inquiry_notification_intents.php');
        $migration->down();
        DB::unprepared('CREATE TRIGGER inquiry_notification_intents_insért BEFORE INSERT ON users FOR EACH ROW BEGIN SET @inquiry_alias_probe = 1; END');
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^(?:create|alter|drop|insert|update|delete)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        try {
            $migration->up();
            $this->fail('A foreign trigger alias was adopted.');
        } catch (LogicException) {
            $this->assertSame([], $writes, 'Reserved dictionary aliases must be refused before partial DDL.');
            $this->assertSame(0, (int) DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', 'inquiry_notification_intents')->count());
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS inquiry_notification_intents_insért');
        }
    }
}
