<?php

namespace Tests\Feature;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;
use PDOException;
use PDOStatement;
use Tests\TestCase;

/** Real PDO admission and native row-lock distinction for the explicit frozen-receipt reader. */
class ProductionCheckoutCommittedRowsTest extends TestCase
{
    private PDO $primary;

    private string $driver;

    private string $table;

    protected function setUp(): void
    {
        parent::setUp();
        $this->primary = DB::connection()->getPdo();
        $this->driver = DB::getDriverName();
        $this->table = 'pco_closed_probe_'.bin2hex(random_bytes(8));
        $this->primary->exec('CREATE TABLE '.$this->table.' (id INTEGER PRIMARY KEY, marker INTEGER NOT NULL)');
        $this->primary->exec('INSERT INTO '.$this->table.' VALUES (1, 9123)');
    }

    protected function tearDown(): void
    {
        if ($this->primary->inTransaction()) {
            $this->primary->rollBack();
        }
        $this->primary->exec('DROP TABLE '.$this->table);
        parent::tearDown();
    }

    public function test_explicit_reader_reads_original_committed_rows_without_opening_a_transaction(): void
    {
        $reader = CurrentRows::committedReadOnly($this->primary, $this->driver);
        $this->assertSame(['id' => 1, 'marker' => 9123], array_map('intval', $reader->one($this->table, 1)));
        $this->assertFalse($this->primary->inTransaction());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_capturing_readonly_mode_inside_physical_transaction_is_refused(): void
    {
        $this->primary->beginTransaction();
        try {
            CurrentRows::committedReadOnly($this->primary, $this->driver);
            $this->fail('Read-only committed mode accepted an active transaction.');
        } catch (LogicException $error) {
            $this->assertSame('committed_read_frame', $error->getMessage());
            $this->assertTrue($this->primary->inTransaction());
        }
    }

    public function test_original_readonly_reader_refuses_later_physical_transaction(): void
    {
        $reader = CurrentRows::committedReadOnly($this->primary, $this->driver);
        $this->primary->beginTransaction();
        try {
            $reader->one($this->table, 1);
            $this->fail('Original read-only reader accepted a replacement transaction.');
        } catch (LogicException $error) {
            $this->assertSame('committed_read_frame', $error->getMessage());
            $this->assertTrue($this->primary->inTransaction());
        }
    }

    public function test_explicit_reader_refuses_wrong_driver_claim_for_actual_core_pdo(): void
    {
        try {
            CurrentRows::committedReadOnly($this->primary, $this->driver === 'sqlite' ? 'mysql' : 'sqlite');
            $this->fail('Plain reader adopted a different claimed driver.');
        } catch (LogicException $error) {
            $this->assertSame('committed_read_frame', $error->getMessage());
            $this->assertFalse($this->primary->inTransaction());
        }
    }

    public function test_factory_refuses_statement_constructor_that_writes_original_committed_row(): void
    {
        $this->refuseWritingStatement(fn () => CurrentRows::committedReadOnly($this->primary, $this->driver));
    }

    public function test_original_plain_reader_refuses_later_statement_constructor_before_prepare(): void
    {
        $reader = CurrentRows::committedReadOnly($this->primary, $this->driver);
        $this->refuseWritingStatement(fn () => $reader->one($this->table, 1));
    }

    private function refuseWritingStatement(\Closure $attempt): void
    {
        $default = $this->primary->getAttribute(PDO::ATTR_STATEMENT_CLASS);
        CommittedRowsWritingStatement::$called = false;
        try {
            $this->primary->setAttribute(PDO::ATTR_STATEMENT_CLASS, [CommittedRowsWritingStatement::class, [$this->primary, $this->table]]);
            try {
                $attempt();
                $this->fail('Plain reader admitted a write-capable statement constructor.');
            } catch (LogicException $error) {
                $this->assertSame('committed_read_frame', $error->getMessage());
            }
        } finally {
            $this->primary->setAttribute(PDO::ATTR_STATEMENT_CLASS, $default);
        }
        $this->assertFalse(CommittedRowsWritingStatement::$called);
        $this->assertSame(9123, (int) $this->primary->query('SELECT marker FROM '.$this->table.' WHERE id=1')->fetchColumn());
        $this->assertFalse($this->primary->inTransaction());
    }

    public function test_native_plain_reader_does_not_wait_for_foreign_row_lock_while_default_reader_still_locks(): void
    {
        if ($this->driver !== 'mysql') {
            $this->markTestSkipped('Native MySQL row-lock semantics required.');
        }
        $other = DB::build(DB::connection()->getConfig())->getPdo();
        $timeout = (int) $this->primary->query('SELECT @@SESSION.innodb_lock_wait_timeout')->fetchColumn();
        $this->primary->exec('SET SESSION innodb_lock_wait_timeout=1');
        $other->beginTransaction();
        try {
            $other->query('SELECT * FROM '.$this->table.' WHERE id=1 FOR UPDATE')->fetchAll();
            $reader = CurrentRows::committedReadOnly($this->primary, $this->driver);
            $this->assertSame(9123, (int) $reader->one($this->table, 1)['marker']);
            $this->assertFalse($this->primary->inTransaction());
            try {
                (new CurrentRows($this->primary, $this->driver))->one($this->table, 1);
                $this->fail('Default reader unexpectedly stopped acquiring native row locks.');
            } catch (PDOException $error) {
                $this->assertSame(1205, (int) ($error->errorInfo[1] ?? 0));
            }
        } finally {
            $other->rollBack();
            $this->primary->exec('SET SESSION innodb_lock_wait_timeout='.$timeout);
        }
        $this->assertSame(9123, (int) $this->primary->query('SELECT marker FROM '.$this->table.' WHERE id=1')->fetchColumn());
    }
}

/** Actual PDO extension hook used only to reproduce the discovered write during plain prepare(). */
final class CommittedRowsWritingStatement extends PDOStatement
{
    public static bool $called = false;

    protected function __construct(PDO $primary, string $table)
    {
        self::$called = true;
        $primary->exec('UPDATE '.$table.' SET marker=9133 WHERE id=1');
    }
}
