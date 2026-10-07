<?php

namespace Tests\Review;

use App\Domain\Catalog\Discovery\CurrentEligibleTrackSnapshot;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

class CurrentReadIsolationCanaryTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new LogicException('Actual transaction isolation requires genuine disposable MySQL.');
        }
    }

    public function test_capture_uses_actual_read_committed_without_changing_repeatable_read_session_default(): void
    {
        $pdo = DB::connection()->getPdo();
        $pdo->exec("UPDATE performance_schema.setup_consumers SET ENABLED = 'YES' WHERE NAME = 'events_transactions_current'");
        $pdo->exec("UPDATE performance_schema.setup_instruments SET ENABLED = 'YES', TIMED = 'YES' WHERE NAME = 'transaction'");
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $before = $pdo->query('SELECT @@transaction_isolation')->fetchColumn();
        $this->assertSame('REPEATABLE-READ', $before);
        $witness = null;
        DB::listen(function ($query) use ($pdo, &$witness): void {
            if ($witness === null && preg_match('/from ["`]tracks["`].*limit 49/i', $query->sql)) {
                $witness = $pdo->query("SELECT t.ISOLATION_LEVEL, t.STATE, t.AUTOCOMMIT FROM performance_schema.events_transactions_current t JOIN performance_schema.threads r ON r.THREAD_ID = t.THREAD_ID WHERE r.PROCESSLIST_ID = CONNECTION_ID() AND t.STATE = 'ACTIVE'")->fetch(PDO::FETCH_ASSOC);
                $this->assertSame(1, DB::transactionLevel());
                $this->assertTrue($pdo->inTransaction());
            }
        });
        $this->assertSame([], app(CurrentEligibleTrackSnapshot::class)->capture()->paths());
        $this->assertSame(['ISOLATION_LEVEL' => 'READ COMMITTED', 'STATE' => 'ACTIVE', 'AUTOCOMMIT' => 'NO'], $witness);
        $this->assertSame($before, $pdo->query('SELECT @@transaction_isolation')->fetchColumn());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse($pdo->inTransaction());
        if (($path = getenv('VA_REVIEW_ISOLATION_WITNESS')) !== false) {
            file_put_contents($path, json_encode(['active_discovery_transaction' => $witness,
                'session_default_before_and_after' => $before, 'final_depth' => DB::transactionLevel()], JSON_THROW_ON_ERROR)."\n");
        }
    }
}
