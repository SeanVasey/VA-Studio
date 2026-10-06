<?php

namespace App\Domain\Commerce\RefundResolution;

use App\Domain\Commerce\Finalization\ReadFinalization;
use App\Domain\Commerce\Models\InventoryReservation;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\Models\PromotionUse;
use App\Domain\Commerce\Models\TestPaymentExceptionEvent;
use App\Domain\Commerce\Models\TestRefundResolution;
use App\Domain\Commerce\Orders\ReadOrder;
use App\Domain\Commerce\QuoteException;
use App\Domain\Customers\CustomerPrincipal;
use App\Domain\Customers\PurchaseAccess;
use RuntimeException;
use SensitiveParameter;

/** Historical database evidence only. No provider check, new resolution or access consequence. */
final class ReadOwnedTestRefundResolution
{
    public function handle(string $id, #[SensitiveParameter] string $owner, ?CustomerPrincipal $principal = null): array
    {
        return app(PurchaseAccess::class)->read($id, $owner, $principal,
            fn (#[SensitiveParameter] string $originalOwner): array => $this->project($id, $originalOwner));
    }

    private function project(string $id, #[SensitiveParameter] string $owner): array
    {
        $order = Order::where('public_id', $id)->where('owner_key', $owner)->first();
        if (! $order || ! hash_equals($order->public_id, $id) || ! hash_equals($order->owner_key, $owner)) {
            throw new QuoteException('ORDER_NOT_FOUND', 404);
        }
        $original = app(ReadOrder::class)->verify($order);
        $result = ['exceptionResolutionSchema' => 1, 'orderId' => $order->public_id, 'testOnly' => true, 'record' => null];
        $resolution = TestRefundResolution::where('order_id', $order->id)->first();
        if (! $resolution) {
            return $result;
        }

        // A resolution can commit after the initial original-graph read. Verify this exact selected
        // row and its complete finalization; a row's existence alone never establishes the result.
        $finalization = OrderFinalization::findOrFail($resolution->order_finalization_id);
        $attempt = $order->attempt()->sole();
        $reservation = InventoryReservation::findOrFail($attempt->inventory_reservation_id);
        $use = $attempt->promotion_use_id === null ? null : PromotionUse::findOrFail($attempt->promotion_use_id);
        app(RefundResolutionEvidence::class)->resourceDisposition($resolution, $finalization, $order, $attempt, $reservation, $use);
        app(ReadFinalization::class)->verify($finalization, $original);
        $event = TestPaymentExceptionEvent::findOrFail($resolution->observed_event_id);
        $at = now()->toImmutable()->utc();
        if ($event->observed_at === null || $event->observed_at->greaterThan($resolution->released_at)
            || $resolution->released_at->greaterThan($at)) {
            throw new RuntimeException('Retained test resolution changed.');
        }
        $result['record'] = ['kind' => 'full_refund_verified_resources_released',
            'observedAt' => $event->observed_at->utc()->toIso8601ZuluString(),
            'releasedAt' => $resolution->released_at->utc()->toIso8601ZuluString()];

        return $result;
    }
}
