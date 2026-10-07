<?php
namespace Tests\Canary;
use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOStatement;
use Tests\TestCase;
final class WithdrawalStatement extends PDOStatement
{
    public static bool $called = false;
    protected function __construct(PDO $primary, string $table)
    {
        self::$called = true;
        $primary->exec('UPDATE '.$table.' SET marker=9133 WHERE id=1');
    }
}
final class CheckoutReadonlyStatementCallbackCanaryTest extends TestCase
{
    public function test_plain_receipt_reader_never_runs_statement_constructor_that_withdraws_committed_marker(): void
    {
        $primary=DB::connection()->getPdo();$driver=DB::getDriverName();$table='pco_statement_probe_'.bin2hex(random_bytes(8));
        $primary->exec('CREATE TABLE '.$table.' (id INTEGER PRIMARY KEY,marker INTEGER NOT NULL)');$primary->exec('INSERT INTO '.$table.' VALUES(1,9123)');
        $default=$primary->getAttribute(PDO::ATTR_STATEMENT_CLASS);WithdrawalStatement::$called=false;
        try {
            $primary->setAttribute(PDO::ATTR_STATEMENT_CLASS,[WithdrawalStatement::class,[$primary,$table]]);
            $reader=CurrentRows::committedReadOnly($primary,$driver);$observed=$reader->one($table,1);
            file_put_contents('/tmp/checkout-statement-probe-'.$driver.'.json',json_encode(['driver'=>$driver,'callback_ran'=>WithdrawalStatement::$called,'observed_marker'=>(int)$observed['marker'],'physical_transaction'=>$primary->inTransaction()]));
            $this->assertSame(9123,(int)$observed['marker'],'Plain receipt read executed a write-capable statement constructor.');
            $this->assertFalse(WithdrawalStatement::$called);
        } finally {
            $primary->setAttribute(PDO::ATTR_STATEMENT_CLASS,$default);$primary->exec('DROP TABLE '.$table);
        }
    }
}
