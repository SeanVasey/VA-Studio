<?php
namespace Tests\Canary;
use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\CommandTransaction;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ProductionCheckoutJourneyTest;
final class ProductionCheckoutLazyCommandCanaryTest extends ProductionCheckoutJourneyTest
{
    public function test_postcommit_lazy_primary_withdrawal_never_runs_or_returns_prepared_result(): void
    {
        $f=$this->payable();$connection=DB::connection();$pdo=$connection->getPdo();$called=false;$active=true;
        app('events')->listen(TransactionCommitted::class,function()use($connection,$pdo,$f,&$called,&$active){
            if(!$active)return;$active=false;
            $connection->setPdo(function()use($pdo,$f,&$called){$called=true;$s=$pdo->prepare('UPDATE users SET password = ? WHERE id = ?');$s->execute(['WITHDRAWN_BY_LATE_COMMAND_CALLBACK',$f['buyer']['user']->id]);return $pdo;});
        });
        try {
            try {CommandTransaction::run(static fn():string=>'PREPARED_RESULT_MUST_NOT_ESCAPE');$this->fail('Prepared result escaped through lazy primary callback.');}
            catch(CheckoutException $e){$this->assertSame('primary_changed',$e->reason);}
            $this->assertFalse($called,'Postcommit fence resolved a lazy primary callback.');
        }finally{$active=false;$connection->setPdo($pdo);}
    }
}
