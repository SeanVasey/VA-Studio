<?php

namespace App\Domain\Customers\DataRights;

use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Orders\ReadOrder;
use App\Domain\Customers\CustomerPrincipal;
use App\Domain\Customers\CustomerPurchaseClaimPolicy;
use App\Domain\Customers\Models\CustomerAccount;
use App\Domain\Customers\Models\CustomerPurchaseClaim;
use App\Domain\Customers\PurchaseAccess;
use App\Domain\Inquiries\Models\CustomerInquiry;
use App\Domain\Inquiries\Models\InquiryOrderContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The requester's own records, read inside the caller's locked transaction: account, orders (original or explicitly saved
 * purchases), license grants of those orders, consent ledger and order-linked inquiries. Every row is selected by the
 * locked principal's account or owner key, never by an identifier from the request.
 */
final class CustomerDataExportDocument
{
    public const SCHEMA = 1;

    public const MAX_ORDERS = 500;

    public const MAX_ROWS = 2000;

    public function build(CustomerPrincipal $principal, User $user, CustomerAccount $account, string $requestId, CarbonImmutable $at): array
    {
        if ($account->id !== $principal->accountId || $user->id !== $principal->userId || ! hash_equals($account->owner_key, $principal->ownerKey)) {
            throw new DataRightsException(403, 'principal_changed');
        }
        $orders = $this->orders($principal);
        $orderIds = array_keys($orders);

        return [
            'exportSchema' => self::SCHEMA, 'brand' => 'VASEY.AUDIO', 'requestId' => $requestId,
            'generatedAt' => $at->utc()->toIso8601ZuluString(), 'contact' => 'support@vasey.audio',
            'account' => [
                'id' => $account->public_id, 'name' => $user->name, 'email' => $user->email,
                'createdAt' => $account->created_at?->utc()->toIso8601ZuluString(),
            ],
            'orders' => array_values($orders),
            'licenseGrants' => $this->grants($orderIds),
            'consent' => $this->consent($account->id),
            'inquiries' => $this->inquiries($orderIds),
        ];
    }

    /** @return array<int, array> keyed by internal order id */
    private function orders(CustomerPrincipal $principal): array
    {
        $query = Order::where(function ($query) use ($principal): void {
            $query->where('owner_key', $principal->ownerKey);
            if (app(CustomerPurchaseClaimPolicy::class)->enabled()) {
                $query->orWhereIn('id', CustomerPurchaseClaim::where('account_id', $principal->accountId)->select('order_id'));
            }
        });
        $rows = $query->orderBy('id')->limit(self::MAX_ORDERS + 1)->get();
        if ($rows->count() > self::MAX_ORDERS) {
            throw new DataRightsException(413, 'export_too_large');
        }
        $orders = [];
        foreach ($rows as $order) {
            // Database collation alone must not decide ownership: reconstruct each frozen graph, as order history does.
            app(PurchaseAccess::class)->assertOrder($order, $principal->ownerKey, $principal);
            $entry = app(ReadOrder::class)->historyEntry($order);
            $orders[$order->id] = array_intersect_key($entry['order'], array_flip(['id', 'createdAt', 'testOnly', 'currency',
                'totalMinor', 'status', 'paymentStatus', 'finalizationStatus', 'contractStatus', 'fulfillmentStatus']))
                + ['itemCount' => $entry['preview']['itemCount'], 'firstItem' => $entry['preview']['firstItem']];
        }

        return $orders;
    }

    private function grants(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }
        $rows = DB::table('license_grants as g')->join('order_finalizations as f', 'f.id', '=', 'g.order_finalization_id')
            ->join('orders as o', 'o.id', '=', 'f.order_id')->whereIn('f.order_id', $orderIds)
            ->orderBy('g.id')->limit(self::MAX_ROWS + 1)->get(['g.public_id', 'o.public_id as order_public_id', 'g.created_at']);
        $this->bounded($rows->count());

        return $rows->map(fn ($row): array => ['id' => $row->public_id, 'orderId' => $row->order_public_id,
            'createdAt' => CarbonImmutable::parse($row->created_at, 'UTC')->toIso8601ZuluString()])->all();
    }

    private function consent(int $accountId): array
    {
        $rows = DB::table('customer_consent_events as e')->leftJoin('customer_consent_policies as p', 'p.id', '=', 'e.policy_id')
            ->where('e.customer_account_id', $accountId)->orderBy('e.id')->limit(self::MAX_ROWS + 1)
            ->get(['e.purpose', 'e.revision', 'e.status', 'e.source', 'e.affirmative', 'e.created_at', 'p.version as notice_version']);
        $this->bounded($rows->count());

        return $rows->map(fn ($row): array => ['purpose' => $row->purpose, 'revision' => (int) $row->revision, 'status' => $row->status,
            'source' => $row->source, 'affirmative' => (bool) $row->affirmative, 'noticeVersion' => $row->notice_version,
            'createdAt' => CarbonImmutable::parse($row->created_at, 'UTC')->toIso8601ZuluString()])->all();
    }

    private function inquiries(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }
        $contexts = InquiryOrderContext::whereIn('order_id', $orderIds)->orderBy('id')->limit(self::MAX_ROWS + 1)->get(['inquiry_id', 'order_id']);
        $this->bounded($contexts->count());
        $orders = Order::whereIn('id', $contexts->pluck('order_id'))->pluck('public_id', 'id');
        $inquiries = CustomerInquiry::whereIn('id', $contexts->pluck('inquiry_id'))->get()->keyBy('id');
        $result = [];
        foreach ($contexts as $context) {
            $inquiry = $inquiries->get($context->inquiry_id);
            if ($inquiry === null) {
                throw new DataRightsException(503, 'inquiry_unavailable');
            }
            $payload = is_array($inquiry->payload) ? $inquiry->payload : [];
            $result[] = ['receipt' => $inquiry->public_id, 'orderId' => $orders[$context->order_id] ?? null,
                'subject' => is_string($payload['subject'] ?? null) ? $payload['subject'] : null,
                'message' => is_string($payload['message'] ?? null) ? $payload['message'] : null,
                'state' => $inquiry->state, 'createdAt' => $inquiry->created_at?->utc()->toIso8601ZuluString()];
        }

        return $result;
    }

    private function bounded(int $count): void
    {
        if ($count > self::MAX_ROWS) {
            throw new DataRightsException(413, 'export_too_large');
        }
    }
}
