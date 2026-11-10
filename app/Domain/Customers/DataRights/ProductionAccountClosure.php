<?php

namespace App\Domain\Customers\DataRights;

use App\Domain\Customers\Models\CustomerAccount;
use App\Domain\Customers\ProductionFeatures\Listening\ProductionListeningState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * What closing a PRODUCTION customer account (BL-44, DD-47 to DD-49) does beyond the shared workflow, and what stops it. Every
 * method runs inside the caller's data-rights transaction, after it holds the customer user, account and request rows.
 *
 * Erased: the encrypted contents of the listening library. Kept: every financial, license, contract and consent family, the
 * production identity evidence, and the two retained-store rows the database refuses to remove (see `eraseLibrary()`).
 */
final class ProductionAccountClosure
{
    /** Refund statuses and dispute statuses that still need a provider outcome (RefundDisputeState). */
    private const OPEN_STATUSES = ['pending', 'requires_action', 'warning_needs_response', 'warning_under_review', 'needs_response', 'under_review'];

    /** Observation kind that proves a V1 provider session ended unpaid (RetentionSchedule::PRODUCTION_ABANDONED_KIND). */
    private const EXPIRED = RetentionSchedule::PRODUCTION_ABANDONED_KIND;

    /** Version of the closure acknowledgment text a customer agrees to (Sean, 2026-11-10); sealed into the request's `requested` event. */
    public const ACKNOWLEDGMENT_VERSION = 'closure-download-forfeiture-v1';

    /**
     * Seconds past a V2 (tax chain) session's `provider_expires_at` during which closure still holds: a payment event delivered at
     * the very end of the session lifetime may be recorded a little later. An operational margin chosen here, not a provider value.
     */
    public const TAX_SESSION_RECONCILE_GRACE_SECONDS = 3600;

    /** Whether the account is a production customer account: it has its one production identity origin. */
    public static function isProduction(CustomerAccount $account): bool
    {
        return ProductionCheckoutExportSection::origin($account->id) !== null;
    }

    /**
     * Reason codes for commerce still in flight, empty when closing is safe. Closing withdraws the sign-in that downloads and
     * order history depend on, so an unpaid session that may still complete, or a refund or dispute that is not settled, makes
     * approval refuse until staff can retry. Nothing here reads a provider; it fails closed on what the store has recorded.
     *
     * The V2 tax checkout chain is covered by timing: its paid record is `production_tax_checkout_reviewed_sessions`, so an order with
     * a request, no reviewed session, and a `provider_expires_at` (plus `TAX_SESSION_RECONCILE_GRACE_SECONDS`) not yet passed may
     * still be paid and holds as `unpaid_tax_checkout_session`. Known gap: after that window an unpaid V2 session is treated as
     * abandoned without a provider read (nothing here reads a provider); a late payment would surface through the refund ledger.
     *
     * @return list<string>
     */
    public function holds(CustomerAccount $account): array
    {
        $holds = [];
        $origin = ProductionCheckoutExportSection::origin($account->id);
        if ($origin !== null && $this->unpaidSession($origin)) {
            $holds[] = 'unpaid_checkout_session';
        }
        if ($origin !== null && $this->unpaidTaxSession($origin)) {
            $holds[] = 'unpaid_tax_checkout_session';
        }
        $orders = $this->orderIds($account);
        if ($orders !== [] && $this->openRefundOrDispute($orders)) {
            $holds[] = 'refund_or_dispute_open';
        }

        return $holds;
    }

    /** An order of the origin with a provider intent, no payment, and an intent whose latest observation is not an expired session. */
    private function unpaidSession(string $origin): bool
    {
        return DB::table('production_checkout_orders as o')->join('production_checkout_provider_intents as i', 'i.order_id', '=', 'o.id')
            ->where('o.buyer_origin_id', $origin)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('production_checkout_payments as p')->whereColumn('p.order_id', 'o.id'))
            ->where(function ($q): void {
                $q->whereNotExists(fn ($s) => $s->selectRaw('1')->from('production_checkout_observations as x')->whereColumn('x.intent_id', 'i.id'))
                    ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('production_checkout_observations as x')->whereColumn('x.intent_id', 'i.id')
                        ->where('x.kind', '<>', self::EXPIRED)
                        ->whereNotExists(fn ($n) => $n->selectRaw('1')->from('production_checkout_observations as y')
                            ->whereColumn('y.intent_id', 'x.intent_id')->whereColumn('y.sequence', '>', 'x.sequence')));
            })->exists();
    }

    /** A V2 order of the origin with a session request, no reviewed (paid) session, whose provider session may still complete. */
    private function unpaidTaxSession(string $origin): bool
    {
        $cutoff = CarbonImmutable::now('UTC')->subSeconds(self::TAX_SESSION_RECONCILE_GRACE_SECONDS)->format('Y-m-d\TH:i:s\Z');

        return DB::table('production_tax_checkout_orders as o')->join('production_tax_checkout_requests as r', 'r.order_id', '=', 'o.id')
            ->where('o.buyer_origin_id', $origin)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('production_tax_checkout_reviewed_sessions as s')->whereColumn('s.order_id', 'o.id'))
            ->where('r.provider_expires_at', '>', $cutoff)->exists();
    }

    /** @return list<int> the account's orders: by owner key, or by a saved purchase claim */
    private function orderIds(CustomerAccount $account): array
    {
        return DB::table('orders')->where('owner_key', $account->owner_key)
            ->orWhereIn('id', DB::table('customer_purchase_claims')->where('account_id', $account->id)->select('order_id'))->pluck('id')->all();
    }

    /** An unreleased download hold, or a refund or dispute whose latest observed status still awaits an outcome. */
    private function openRefundOrDispute(array $orders): bool
    {
        if (DB::table('refund_dispute_holds')->whereIn('order_id', $orders)->whereNull('released_at')->exists()) {
            return true;
        }

        return DB::table('refund_dispute_objects as o')->whereIn('o.order_id', $orders)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('refund_dispute_statuses as s')->whereColumn('s.refund_dispute_object_id', 'o.id')
                ->whereIn('s.status', self::OPEN_STATUSES)
                ->whereNotExists(fn ($n) => $n->selectRaw('1')->from('refund_dispute_statuses as t')
                    ->whereColumn('t.refund_dispute_object_id', 's.refund_dispute_object_id')->whereColumn('t.id', '>', 's.id')))
            ->exists();
    }

    /**
     * DD-48: the consent clock starts at deletion approval only for an account that no retained order still ties to the address.
     * Returns the facts for the `deletion_completed` event; the retention report derives the same answer from the same holders.
     *
     * @return array{starts_at: ?string, basis: string}
     */
    public function consentClock(CustomerAccount $account, CarbonImmutable $at): array
    {
        return RetainedOrders::exist($account->id)
            ? ['starts_at' => null, 'basis' => 'held_with_order']
            : ['starts_at' => $at->toIso8601ZuluString(), 'basis' => 'deletion_approval'];
    }

    /**
     * Erases the contents of the account's listening library by writing the library's own empty state over the encrypted payload
     * (the same change as its `clear-library`), which the database admits as the next version of the row. The row itself, and the
     * account's feature binding it hangs from, stay: both tables refuse every DELETE (`production_*_retain_delete` triggers), the
     * binding refuses every UPDATE, and the binding is the key of the retained consent ledger (`production_consent_events`, which
     * references it with ON DELETE RESTRICT). They hold no listening content: ids, a hash and a sealed account binding.
     *
     * @return array{library: string, bindings: int} `contents_erased` or `none`, and how many feature bindings remain
     */
    public function eraseLibrary(CustomerAccount $account, CarbonImmutable $at): array
    {
        $bindings = DB::table('production_account_feature_bindings')->where('account_id', $account->id)->orderBy('id')->get(['id', 'feature']);
        $library = $bindings->firstWhere('feature', 'listening_library');
        $erased = 'none';
        if ($library !== null) {
            $row = DB::table('production_listening_libraries')->where('binding_id', $library->id)->lockForUpdate()->first();
            if ($row !== null) {
                try {
                    $empty = (new ProductionListeningState)->empty($account->id);
                    $empty['version'] = (int) $row->version + 1;
                    $payload = Crypt::encryptString(json_encode($empty, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                } catch (Throwable) {
                    throw new DataRightsException(503, 'library_erasure_failed');
                }
                // The update trigger needs updated_at not to go backwards; a row written in this same second keeps its value.
                $updated = max($at->format('Y-m-d H:i:s'), (string) $row->updated_at);
                if (DB::table('production_listening_libraries')->where('id', $row->id)->where('version', $row->version)
                    ->update(['payload' => $payload, 'version' => (int) $row->version + 1, 'updated_at' => $updated]) !== 1) {
                    throw new DataRightsException(503, 'library_erasure_failed');
                }
                $erased = 'contents_erased';
            }
        }

        return ['library' => $erased, 'bindings' => $bindings->count()];
    }
}
