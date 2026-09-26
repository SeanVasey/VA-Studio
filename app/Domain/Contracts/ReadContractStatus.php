<?php

namespace App\Domain\Contracts;

use App\Domain\Commerce\Models\LicenseGrant;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\Orders\ReadOrder;
use App\Domain\Commerce\QuoteException;
use App\Domain\Contracts\Models\ContractRenderRequest;
use App\Domain\Contracts\Models\GrantContract;
use Illuminate\Database\QueryException;
use Throwable;

/** Owner authorization belongs to the calling order/checkout reader. No issuing, file reads or current flags. */
final class ReadContractStatus
{
    public function forOrder(Order $order): array
    {
        try {
            $finalization = OrderFinalization::where('order_id', $order->id)->first();
            if (! $finalization) { return ['contractStatus' => 'not_started', 'fulfillmentStatus' => 'not_started']; }
            app(ReadOrder::class)->verify($order);
            if ($finalization->outcome === 'paid_exception') { return ['contractStatus' => 'blocked', 'fulfillmentStatus' => 'blocked']; }
            $grants = LicenseGrant::where('order_finalization_id', $finalization->id)->get();
            if ($grants->isEmpty()) { throw new ContractIssuanceException('evidence_changed'); }
            $issued = 0; $attention = false;
            foreach ($grants as $grant) {
                $request = ContractRenderRequest::where('license_grant_id', $grant->id)->first();
                if (! $request) {
                    if (GrantContract::where('license_grant_id', $grant->id)->exists()) { throw new ContractIssuanceException('evidence_changed'); }
                    continue;
                }
                $document = app(ReadGrantContract::class)->forRequest($request);
                if ($document) { $issued++; }
                else { $attention = $attention || $request->work()->sole()->state === 'quarantined'; }
            }
            if ($attention) { return ['contractStatus' => 'attention', 'fulfillmentStatus' => 'blocked']; }

            return $issued === $grants->count()
                ? ['contractStatus' => 'issued', 'fulfillmentStatus' => 'pending_activation']
                : ['contractStatus' => 'pending', 'fulfillmentStatus' => 'pending_contracts'];
        } catch (QueryException $error) { throw $error; }
        catch (Throwable) { throw new QuoteException('ORDER_CHANGED', 409); }
    }
}
