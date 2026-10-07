<?php
namespace Tests\Canary;
use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\ProductionPaidOrderLocatorV1;
use App\Domain\Commerce\ProductionCheckout\ProductionPaidOrderSourceV1;
use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ProductionCheckoutJourneyTest;
final class ProductionCheckoutUnresolvedConnectionCanaryTest extends ProductionCheckoutJourneyTest
{
    public function test_expired_source_never_resolves_uncached_connection_that_withdraws_buyer(): void
    {
        $f=$this->payable();$f['hosted']->initiate($f['buyer']['principal'],$f['buyer']['user'],$f['order']['orderId']);$f['gateway']->paid=true;$f['hosted']->reconcile($f['buyer']['principal'],$f['buyer']['user'],$f['order']['orderId']);
        $locator=ProductionPaidOrderLocatorV1::locate($f['order']['orderId']);$connection=DB::connection();$pdo=$connection->getPdo();$driver=DB::getDriverName();$reader=new CurrentRows($pdo,$driver);
        $source=DB::transaction(function()use($f,$locator,$reader){return ProductionPaidOrderSourceV1::lockedRead($locator,$reader,$f['access']->verifyHistoricalBinding($locator->historicalBuyerBinding(),$reader));});
        $f['access']->current($f['buyer']['principal'],$f['buyer']['user']);$called=false;$active=true;
        DB::extend($driver,function()use($connection,$pdo,$f,&$called,&$active){
            if($active){$called=true;$s=$pdo->prepare('UPDATE users SET password = ? WHERE id = ?');$s->execute(['WITHDRAWN_BY_CONNECTION_RESOLVER',$f['buyer']['user']->id]);}
            return $connection->setPdo($pdo);
        });
        DB::purge();
        try {
            try{$source->proveRetainedCurrent($reader);$this->fail('Expired source renewed through uncached connection.');}
            catch(CheckoutException $e){$this->assertSame('held_transaction',$e->reason);}
            $this->assertFalse($called,'Terminal source refusal invoked uncached connection resolver.');
        }finally{$active=false;DB::connection();}
    }
}
