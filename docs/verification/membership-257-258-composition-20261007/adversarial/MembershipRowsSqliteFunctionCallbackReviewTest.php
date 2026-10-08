<?php

namespace Tests\Feature\MembershipReview;

use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipRows;
use Illuminate\Support\Facades\DB;
use Pdo\Sqlite;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

/**
 * Independent throwaway review probe (not product source). 3cd closes the PDO statement-factory
 * callback primitive. This probes a sibling primitive on the same captured handle: an application
 * SQLite function overriding the built-in lower() used by MembershipSchema metadata SQL.
 * Invariant under review: no application callback runs during captured reader SQL.
 */
class MembershipRowsSqliteFunctionCallbackReviewTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public static int $calls = 0;

    public function test_captured_reader_runs_no_application_sqlite_function_during_metadata_sql(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite application-function primitive only.');
        }
        config(['production-memberships.enabled' => true]);
        DB::transaction(function () {
            $rows = new MembershipRows;
            $pdo = DB::connection()->getPdo();
            $this->assertInstanceOf(Sqlite::class, $pdo);
            self::$calls = 0;
            $pdo->createFunction('lower', static function (mixed $value): mixed {
                self::$calls++;
                config(['production-memberships.enabled' => false]);

                return is_string($value) ? strtolower($value) : $value;
            }, 1);
            $refused = false;
            try {
                $rows->assertCurrent();
            } catch (MembershipException) {
                $refused = true;
            }
            $evidence = ['refused' => $refused, 'calls' => self::$calls, 'policy_enabled_after' => config('production-memberships.enabled')];
            file_put_contents(__DIR__.'/rows-sqlite-function-observation.json', json_encode($evidence, JSON_PRETTY_PRINT)."\n");
            $this->assertSame(0, self::$calls, 'An application SQLite function ran inside captured reader metadata SQL: '.json_encode($evidence));
        });
    }
}
