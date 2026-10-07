<?php
namespace Tests\Canary;
use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\ProductionPaidOrderLocatorV1;
use App\Domain\Commerce\ProductionCheckout\ProductionPaidOrderSourceV1;
use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ProductionCheckoutJourneyTest;
final class ProductionCheckoutLazyPrimaryCanaryTest extends ProductionCheckoutJourneyTest
{
    public function test_expired_source_refusal_never_resolves_lazy_primary_or_withdraws_original_buyer(): void
    {
        $f=$this->payable();
        $f['hosted']->initiate($f['buyer']['principal'],$f['buyer']['user'],$f['order']['orderId']);
        $f['gateway']->paid=true;
        $f['hosted']->reconcile($f['buyer']['principal'],$f['buyer']['user'],$f['order']['orderId']);
        $locator=ProductionPaidOrderLocatorV1::locate($f['order']['orderId']);
        $connection=DB::connection();$pdo=$connection->getPdo();$reader=new CurrentRows($pdo,DB::getDriverName());
        $source=DB::transaction(function()use($f,$locator,$reader){
            $historical=$f['access']->verifyHistoricalBinding($locator->historicalBuyerBinding(),$reader);
            return ProductionPaidOrderSourceV1::lockedRead($locator,$reader,$historical);
        });
        $f['access']->current($f['buyer']['principal'],$f['buyer']['user']);
        $statement=$pdo->prepare('SELECT password FROM users WHERE id = ?');$statement->execute([$f['buyer']['user']->id]);$original=$statement->fetchColumn();
        $called=false;
        $connection->setPdo(function()use($pdo,$f,&$called){
            $called=true;$s=$pdo->prepare('UPDATE users SET password = ? WHERE id = ?');$s->execute(['WITHDRAWN_BY_LATE_PRIMARY_CALLBACK',$f['buyer']['user']->id]);return $pdo;
        });
        try {
            try {$source->proveRetainedCurrent($reader);$this->fail('Expired source renewed authority.');}
            catch(CheckoutException $e){$this->assertSame('held_transaction',$e->reason);}
            $this->assertFalse($called,'Terminal source refusal resolved a lazy primary callback.');
            $statement->execute([$f['buyer']['user']->id]);$this->assertSame($original,$statement->fetchColumn());
        } finally {$connection->setPdo($pdo);}
    }
}
