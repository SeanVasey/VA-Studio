<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The application writes UTC wall-clock strings (BillingValues::utc(), ProductionFeatureShape) into TIMESTAMP columns while
 * PHP runs in UTC. MySQL converts TIMESTAMP values through the session time zone, so the connection must pin it.
 */
class MysqlConnectionTimezoneTest extends TestCase
{
    public function test_mysql_connection_pins_the_session_to_utc(): void
    {
        $this->assertSame('+00:00', config('database.connections.mysql.timezone'));
        $this->assertSame('UTC', config('app.timezone'));
    }

    public function test_native_session_is_utc_and_a_local_dst_gap_instant_round_trips_byte_identical(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Native MySQL session time zone and TIMESTAMP conversion; SQLite stores text.');
        }

        $this->assertSame('+00:00', DB::scalar('SELECT @@session.time_zone'));

        // 2026-03-29 01:30:00 does not exist in Europe/London (clocks jump 01:00 to 02:00); a session in that zone would
        // refuse it or move it by an hour.
        $instant = '2026-03-29 01:30:00';
        DB::statement('CREATE TEMPORARY TABLE timezone_pin_probe (id INT NOT NULL PRIMARY KEY, stamp TIMESTAMP NOT NULL)');
        try {
            DB::insert('INSERT INTO timezone_pin_probe (id, stamp) VALUES (?, ?)', [1, $instant]);
            $this->assertSame($instant, DB::scalar('SELECT CAST(stamp AS CHAR) FROM timezone_pin_probe WHERE id = 1'));
            $this->assertSame(gmmktime(1, 30, 0, 3, 29, 2026), (int) DB::scalar('SELECT UNIX_TIMESTAMP(stamp) FROM timezone_pin_probe WHERE id = 1'));
        } finally {
            DB::statement('DROP TEMPORARY TABLE IF EXISTS timezone_pin_probe');
        }
    }
}
