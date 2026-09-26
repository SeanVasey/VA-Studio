<?php

namespace App\Domain\Commerce\Operations;

use App\Domain\Commerce\Models\LicenseGrant;
use App\Domain\Commerce\Models\OrderFinalization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/** Staff ledger metadata only. Never decrypt, render, inspect files, call a provider or authorize delivery. */
final class ReadTestCommerceOperations
{
    public const EXCEPTION_REASONS = [
        'late_confirmation' => 'Confirmation observed too late',
        'inventory_blocked' => 'Inventory blocked', 'inventory_unavailable' => 'Inventory unavailable',
        'asset_unavailable' => 'Purchased asset unavailable', 'rights_unavailable' => 'Rights unavailable',
    ];

    public const STATES = [
        'missing_request' => 'Not requested', 'pending' => 'Waiting', 'processing' => 'Processing',
        'retry' => 'Retry scheduled', 'quarantined' => 'Needs attention',
        'recorded' => 'Original recorded', 'attention' => 'Evidence needs attention',
    ];

    public const WORK_REASONS = [
        'render_failed' => 'Rendering failed', 'storage_failed' => 'Storage failed',
        'evidence_changed' => 'Evidence changed', 'profile_changed' => 'Profile changed',
        'unsupported_input' => 'Unsupported input', 'invalid_pdf' => 'Invalid PDF',
        'retry_exhausted' => 'Attempts exhausted', 'original_unavailable' => 'Original unavailable',
    ];

    public function exceptions(): Builder
    {
        $account = $this->account();

        return OrderFinalization::query()
            ->join('orders as o', 'o.id', '=', 'order_finalizations.order_id')
            ->join('verified_payments as p', 'p.id', '=', 'order_finalizations.verified_payment_id')
            ->where('order_finalizations.mode', 'test')->where('order_finalizations.outcome', 'paid_exception')
            ->where('p.mode', 'test')->where('p.account_id', $account)->when($account === null, fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->whereColumn('p.order_id', 'o.id')->whereColumn('p.order_attempt_id', 'order_finalizations.order_attempt_id')
            ->select(['order_finalizations.id', 'order_finalizations.public_id', 'o.public_id as order_public_id',
                'order_finalizations.confirmed_at', 'order_finalizations.finalized_at'])
            ->selectRaw("CASE WHEN order_finalizations.reason IN ('late_confirmation', 'inventory_blocked', 'inventory_unavailable',"
                ." 'asset_unavailable', 'rights_unavailable') THEN order_finalizations.reason ELSE 'evidence_changed' END AS reason");
    }

    public function contracts(): Builder
    {
        $account = $this->account(); $state = self::contractStateSql();

        return LicenseGrant::query()
            ->join('order_finalizations as f', 'f.id', '=', 'license_grants.order_finalization_id')
            ->join('orders as o', 'o.id', '=', 'f.order_id')
            ->join('verified_payments as p', 'p.id', '=', 'f.verified_payment_id')
            ->leftJoin('contract_render_requests as r', 'r.license_grant_id', '=', 'license_grants.id')
            ->leftJoin('contract_render_work as w', 'w.contract_render_request_id', '=', 'r.id')
            ->leftJoin('fulfillment_outbox as b', 'b.id', '=', 'r.fulfillment_outbox_id')
            ->leftJoin('grant_contracts as d', 'd.license_grant_id', '=', 'license_grants.id')
            ->leftJoin('grant_contracts as dr', 'dr.contract_render_request_id', '=', 'r.id')
            ->where('f.mode', 'test')->where('f.outcome', 'paid')
            ->where('p.mode', 'test')->where('p.account_id', $account)->when($account === null, fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->whereColumn('p.order_id', 'o.id')->whereColumn('p.order_attempt_id', 'f.order_attempt_id')
            ->select(['license_grants.id', 'license_grants.public_id', 'o.public_id as order_public_id',
                'license_grants.created_at', 'r.public_id as request_public_id', 'w.attempts',
                'w.next_attempt_at', 'w.lease_expires_at', 'w.updated_at as work_updated_at'])
            ->selectRaw("({$state}) AS issuance_state")
            ->selectRaw("CASE WHEN ({$state}) = 'recorded' THEN d.public_id ELSE NULL END AS document_public_id")
            ->selectRaw("CASE WHEN ({$state}) = 'recorded' THEN d.issued_at ELSE NULL END AS issued_at")
            ->selectRaw("CASE WHEN w.reason IN ('render_failed', 'storage_failed', 'evidence_changed', 'profile_changed',"
                ." 'unsupported_input', 'invalid_pdf', 'retry_exhausted', 'original_unavailable') THEN w.reason ELSE NULL END AS work_reason");
    }

    public static function filterContracts(Builder $query, mixed $state): Builder
    {
        if ($state === null || $state === '') { return $query; }
        if (! is_string($state) || ! array_key_exists($state, self::STATES)) { return $query->whereRaw('1 = 0'); }

        return $query->whereRaw('('.self::contractStateSql().') = ?', [$state]);
    }

    /** Structural ledger checks only. A recorded original is not a full evidence or physical durability check. */
    private static function contractStateSql(): string
    {
        return "CASE WHEN r.id IS NULL THEN CASE WHEN d.id IS NULL THEN 'missing_request' ELSE 'attention' END"
            ." WHEN w.id IS NULL OR COALESCE((r.input_hash = license_grants.render_input_hash"
            ." AND b.license_grant_id = license_grants.id AND b.order_finalization_id = f.id"
            ." AND b.state = 'pending' AND b.kind = 'render_test_contract_v1'), 0) = 0 THEN 'attention'"
            ." WHEN d.id IS NULL AND dr.id IS NOT NULL THEN 'attention'"
            ." WHEN d.id IS NOT NULL THEN CASE WHEN w.state = 'completed' AND dr.id = d.id"
            ." AND d.contract_render_request_id = r.id AND d.public_id = r.document_public_id"
            ." AND d.input_hash = r.input_hash AND d.profile_hash = r.profile_hash"
            ." AND d.issued_at >= r.created_at AND d.issued_at <= w.updated_at"
            ." THEN 'recorded' ELSE 'attention' END"
            ." WHEN w.state IN ('pending', 'processing', 'retry', 'quarantined') THEN w.state ELSE 'attention' END";
    }

    private function account(): ?string
    {
        Gate::authorize('administer-catalog');
        $account = config('payments.stripe.account_id');

        // A missing/invalid configured account produces an empty scope, never a cross-account fallback.
        return is_string($account) && preg_match('/\Aacct_[A-Za-z0-9]{1,64}\z/D', $account) ? $account : null;
    }
}
