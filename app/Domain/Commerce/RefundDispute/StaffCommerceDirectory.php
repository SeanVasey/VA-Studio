<?php

namespace App\Domain\Commerce\RefundDispute;

use App\Domain\Commerce\Models\ExclusiveSale;
use App\Domain\Commerce\Models\LicenseGrant;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\Orders\ReadOrder;
use App\Domain\Commerce\RefundDispute\Models\RefundDisputeEffect;
use App\Domain\Commerce\RefundDispute\Models\RefundDisputeHold;
use App\Domain\Commerce\RefundDispute\Models\RefundDisputeObject;
use App\Domain\Commerce\RefundDispute\Models\RefundDisputeObservation;
use App\Domain\Commerce\RefundDispute\Models\RefundLineAllocation;
use App\Domain\Delivery\Models\TestDeliveryAuthorization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Read-mostly staff view of test customers and their orders. Staff authority and MFA are rechecked by the
 * caller's page on every request and again here for detail reads. The configured own test account scopes every
 * query; a missing account yields an empty view. Owner keys (guest bearer identities), provider identifiers,
 * ciphertexts and hashes are never projected; a customer is shown by account public ID or a keyed reference.
 */
final class StaffCommerceDirectory
{
    /** Paid and paid-exception test orders with their current refund/dispute summary. */
    public function orders(): Builder
    {
        $account = $this->account();
        $latest = 'SELECT %s FROM refund_dispute_observations r WHERE r.order_id = o.id AND r.outcome = \'observed\' ORDER BY r.sequence DESC LIMIT 1';

        return $this->scoped($account)
            ->leftJoin('customer_accounts as ca', 'ca.owner_key', '=', 'o.owner_key')
            ->select(['order_finalizations.id', 'order_finalizations.public_id', 'o.public_id as order_public_id', 'order_finalizations.outcome',
                'p.amount_minor', 'p.currency', 'order_finalizations.finalized_at', 'ca.public_id as customer_public_id'])
            ->selectRaw('('.sprintf($latest, 'r.refund_state').') AS refund_state')
            ->selectRaw('('.sprintf($latest, 'r.dispute_state').') AS dispute_state')
            ->selectRaw('(SELECT COUNT(*) FROM refund_dispute_holds h WHERE h.order_id = o.id AND h.released_at IS NULL) AS active_holds');
    }

    /** One row per test customer (quote owner) with at least one paid or paid-exception order. */
    public function customers(): Builder
    {
        return $this->scoped($this->account())
            ->leftJoin('customer_accounts as ca', 'ca.owner_key', '=', 'o.owner_key')
            ->groupBy('o.owner_key', 'ca.public_id')
            ->selectRaw('MIN(order_finalizations.id) AS id, ca.public_id AS customer_public_id, COUNT(*) AS order_count,'
                .' SUM(p.amount_minor) AS paid_minor, MAX(order_finalizations.finalized_at) AS last_finalized_at,'
                .' (SELECT COUNT(*) FROM refund_dispute_holds h JOIN orders ho ON ho.id = h.order_id WHERE ho.owner_key = o.owner_key AND h.released_at IS NULL) AS active_holds');
    }

    /** Detail for the customer who owns the given finalization: every scoped order with its evidence. */
    public function customer(int $finalizationId, ?User $actor): array
    {
        app(RefundDisputeAuthority::class)->staff($actor);
        $account = $this->account();
        $anchor = $this->scoped($account)->where('order_finalizations.id', $finalizationId)->select(['o.owner_key'])->first();
        if ($anchor === null) {
            return ['customer' => null, 'orders' => []];
        }
        $ownerKey = $anchor->getAttribute('owner_key');
        $account = DB::table('customer_accounts')->where('owner_key', $ownerKey)->first(['public_id', 'active']);
        $rows = $this->scoped($this->account())->where('o.owner_key', $ownerKey)->orderByDesc('order_finalizations.id')->limit(20)
            ->get(['order_finalizations.id']);

        return ['customer' => ['reference' => $account?->public_id ?? self::reference($ownerKey), 'account' => $account !== null,
            'active' => $account === null ? null : (bool) $account->active], 'orders' => $rows->map(fn ($row) => $this->order((int) $row->id))->all()];
    }

    /** One scoped order's evidence projection, after a fresh staff authority and MFA check. */
    public function orderFor(int $finalizationId, ?User $actor): array
    {
        app(RefundDisputeAuthority::class)->staff($actor);
        RefundDisputeException::require($this->scoped($this->account())->where('order_finalizations.id', $finalizationId)->exists(), 'not_found');

        return $this->order($finalizationId);
    }

    /** One order's evidence projection: lines, grants, refunds/disputes, allocations, holds, effects and download evidence. */
    private function order(int $finalizationId): array
    {
        $finalization = OrderFinalization::findOrFail($finalizationId);
        $order = Order::findOrFail($finalization->order_id);
        try {
            $original = app(ReadOrder::class)->verify($order);
            $evidence = 'verified';
        } catch (Throwable) {
            $original = null;
            $evidence = 'attention';
        }
        $grants = LicenseGrant::where('order_finalization_id', $finalization->id)->get()->keyBy('order_line_id');
        $exclusive = ExclusiveSale::where('order_finalization_id', $finalization->id)->pluck('order_line_id')->all();
        $allocated = RefundLineAllocation::where('order_id', $order->id)->get()->groupBy('order_line_id');
        $holds = RefundDisputeHold::where('order_id', $order->id)->orderBy('id')->get();
        $lines = [];
        foreach ($order->lines()->orderBy('position')->get() as $line) {
            $grant = $grants->get($line->id);
            $lines[] = ['position' => $line->position, 'title' => $original['lines'][$line->position]['selection']['offer_snapshot']['product']['title'] ?? null,
                'totalMinor' => $original['lines'][$line->position]['pricing']['total_minor'] ?? null, 'grant' => $grant?->public_id,
                'exclusive' => in_array($line->id, $exclusive, true), 'allocatedMinor' => (int) ($allocated->get($line->id)?->sum('amount_minor') ?? 0),
                'downloadsHeld' => $grant !== null && $holds->contains(fn ($h) => $h->released_at === null
                    && ($h->license_grant_id === null || $h->license_grant_id === $grant->id))];
        }
        $observation = RefundDisputeObservation::where('order_id', $order->id)->orderByDesc('sequence')->first();
        $authorizations = TestDeliveryAuthorization::where('order_id', $order->id);

        return ['order' => $order->public_id, 'finalization' => $finalization->public_id, 'outcome' => $finalization->outcome,
            'evidence' => $evidence, 'paidMinor' => $finalization->payment->amount_minor, 'currency' => $finalization->payment->currency,
            'finalizedAt' => $finalization->finalized_at->toIso8601ZuluString(), 'lines' => $lines,
            'latestObservation' => $observation === null ? null : ['sequence' => $observation->sequence, 'outcome' => $observation->outcome,
                'refundState' => $observation->refund_state, 'disputeState' => $observation->dispute_state,
                'succeededRefundMinor' => $observation->succeeded_refund_minor, 'pendingRefundMinor' => $observation->pending_refund_minor,
                'observedAt' => $observation->observed_at->toIso8601ZuluString()],
            'providerObjects' => RefundDisputeObject::where('order_id', $order->id)->orderBy('id')->get()->map(fn ($o) => ['reference' => $o->public_id,
                'kind' => $o->kind, 'amountMinor' => $o->amount_minor, 'currency' => $o->currency,
                'statuses' => DB::table('refund_dispute_statuses')->where('refund_dispute_object_id', $o->id)->orderBy('id')->pluck('status')->all()])->all(),
            'holds' => $holds->map(fn ($h) => ['reference' => $h->public_id, 'reason' => $h->reason,
                'grant' => $h->license_grant_id === null ? null : LicenseGrant::find($h->license_grant_id)?->public_id,
                'createdAt' => $h->created_at->toIso8601ZuluString(), 'releasedAt' => $h->released_at?->toIso8601ZuluString()])->all(),
            'effects' => RefundDisputeEffect::where('order_id', $order->id)->orderByDesc('id')->limit(30)->get()->map(fn ($e) => ['kind' => $e->kind,
                'sequence' => $e->sequence, 'staff' => $e->actor_id !== null, 'createdAt' => $e->created_at->toIso8601ZuluString()])->all(),
            'downloads' => ['authorizations' => (clone $authorizations)->count(),
                'redemptions' => DB::table('test_delivery_redemptions as d')->join('test_delivery_authorizations as a', 'a.id', '=', 'd.test_delivery_authorization_id')
                    ->where('a.order_id', $order->id)->count(),
                'lastIssuedAt' => (clone $authorizations)->max('issued_at')]];
    }

    /** A stable, keyed staff reference for a guest owner; the owner key itself is a bearer identity. */
    public static function reference(string $ownerKey): string
    {
        return 'guest-'.substr(hash_hmac('sha256', 'staff-customer-reference|'.$ownerKey, (string) config('app.key')), 0, 12);
    }

    private function scoped(?string $account): Builder
    {
        return OrderFinalization::query()
            ->join('orders as o', 'o.id', '=', 'order_finalizations.order_id')
            ->join('verified_payments as p', 'p.id', '=', 'order_finalizations.verified_payment_id')
            ->where('order_finalizations.mode', 'test')->whereIn('order_finalizations.outcome', ['paid', 'paid_exception'])
            ->where('p.mode', 'test')->where('p.account_id', $account)->when($account === null, fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->whereColumn('p.order_id', 'o.id');
    }

    private function account(): ?string
    {
        Gate::authorize('administer-catalog');
        $account = config('payments.stripe.account_id');

        return is_string($account) && preg_match('/\Aacct_[A-Za-z0-9]{1,64}\z/D', $account) === 1 ? $account : null;
    }
}
