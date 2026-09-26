<?php

namespace App\Domain\Delivery;

use App\Domain\Commerce\Models\Order;
use App\Domain\Delivery\Models\TestFulfillmentActivation;
use Illuminate\Database\QueryException;
use Throwable;

/** Historical proof only. Ownership authorization belongs to the caller; no current flags or private file access. */
final class ReadTestFulfillmentActivation
{
    public function forOrder(Order $order): ?TestFulfillmentActivation
    {
        $activation = TestFulfillmentActivation::where('order_id', $order->id)->first();
        if (! $activation) { return null; }
        try {
            $evidence = app(ActivationEvidence::class);
            $evidence->verify($activation, $evidence->source($order));
            return $activation;
        } catch (QueryException $error) { throw $error; }
        catch (Throwable) { throw new DeliveryException('changed'); }
    }
}
