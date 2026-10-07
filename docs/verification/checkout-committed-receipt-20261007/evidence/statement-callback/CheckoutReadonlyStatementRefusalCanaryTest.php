<?php
namespace Tests\Canary;
use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOStatement;
use Tests\TestCase;
final class RefusalWithdrawalStatement extends PDOStatement
{
    public static bool $called = false;
    protected function __construct(PDO $primary, string $table)
    {
        self::$called = true;
        $primary->exec('UPDATE '.$table.' SET marker=9133 WHERE id=1');
    }
}
final class CheckoutReadonlyStatementRefusalCanaryTest extends TestCase
{
    public function test_plain_receipt_reader_never_runs_statement_constructor_that_withdraws_committed_marker(): void
    {
        $primary=DB::connection()->getPdo();$driver=DB::getDriverName();$table='pco_statement_probe_'.bin2hex(random_bytes(8));
        $primary->exec('CREATE TABLE '.$table.' (id INTEGER PRIMARY KEY,marker INTEGER NOT NULL)');$primary->exec('INSERT INTO '.$table.' VALUES(1,9123)');
        $default=$primary->getAttribute(PDO::ATTR_STATEMENT_CLASS);RefusalWithdrawalStatement::$called=false;
        try {
            $primary->setAttribute(PDO::ATTR_STATEMENT_CLASS,[RefusalWithdrawalStatement::class,[$primary,$table]]);
            $refused=false;
            try { $reader=CurrentRows::committedReadOnly($primary,$driver);$reader->one($table,1); }
            catch (\LogicException $error) { $this->assertSame('committed_read_frame',$error->getMessage());$refused=true; }
            $primary->setAttribute(PDO::ATTR_STATEMENT_CLASS,$default);
            $marker=(int)$primary->query('SELECT marker FROM '.$table.' WHERE id=1')->fetchColumn();
            file_put_contents('/tmp/checkout-statement-refusal-probe-'.$driver.'.json',json_encode(['driver'=>$driver,'callback_ran'=>RefusalWithdrawalStatement::$called,'observed_marker'=>$marker,'refused'=>$refused,'physical_transaction'=>$primary->inTransaction()]));
            $this->assertFalse(RefusalWithdrawalStatement::$called,'Plain receipt read invoked the statement constructor.');
            $this->assertSame(9123,$marker);

        } finally {
            $primary->setAttribute(PDO::ATTR_STATEMENT_CLASS,$default);$primary->exec('DROP TABLE '.$table);
        }
    }
}
