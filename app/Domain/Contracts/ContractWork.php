<?php

namespace App\Domain\Contracts;

use App\Domain\Commerce\Models\LicenseGrant;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\Models\VerifiedPayment;
use App\Domain\Contracts\Models\ContractRenderRequest;
use App\Domain\Contracts\Models\ContractRenderWork;
use App\Domain\Contracts\Models\GrantContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Short database claims only; rendering and file writes never run under these locks. */
final class ContractWork
{
    public function claim(int $requestId): ?array
    {
        return $this->locked($requestId, function ($request, $work): ?array {
            $at = now()->toImmutable()->utc()->startOfSecond();
            if (in_array($work->state, ['completed', 'quarantined'], true)
                || ($work->state === 'processing' && $work->lease_expires_at->greaterThan($at))
                || ($work->state === 'retry' && $work->next_attempt_at->greaterThan($at))
                || $at->lessThan($work->updated_at)) { return null; }
            if (GrantContract::where('contract_render_request_id', $request->id)->exists()) {
                throw new ContractIssuanceException('evidence_changed');
            }
            if ($work->attempts >= 5) {
                $work->forceFill(['state' => 'quarantined', 'reason' => 'retry_exhausted',
                    'claim_token' => null, 'lease_expires_at' => null, 'next_attempt_at' => null, 'updated_at' => $at])->save();
                return null;
            }
            $token = (string) Str::uuid(); $lease = $at->addSeconds(300);
            $work->forceFill(['state' => 'processing', 'attempts' => $work->attempts + 1, 'claim_token' => $token,
                'lease_expires_at' => $lease, 'next_attempt_at' => null, 'reason' => null, 'updated_at' => $at])->save();

            return ['id' => $request->id, 'token' => $token, 'leaseExpiresAt' => $lease, 'attempt' => $work->attempts];
        });
    }

    public function fail(int $requestId, string $token, string $reason, bool $permanent = false): string
    {
        if (! in_array($reason, ContractRenderWork::REASONS, true)) { $reason = 'render_failed'; }
        return $this->locked($requestId, function ($request, $work) use ($token, $reason, $permanent): string {
            $at = now()->toImmutable()->utc()->startOfSecond();
            if (! $this->owns($work, $token, $at)) { return 'stale'; }
            if (GrantContract::where('contract_render_request_id', $request->id)->exists()) {
                throw new ContractIssuanceException('evidence_changed');
            }
            $exhausted = $work->attempts >= 5;
            $quarantine = $permanent || $exhausted || ! in_array($reason, ['render_failed', 'storage_failed'], true);
            $work->forceFill(['state' => $quarantine ? 'quarantined' : 'retry',
                'reason' => $exhausted && ! $permanent ? 'retry_exhausted' : $reason,
                'claim_token' => null, 'lease_expires_at' => null,
                'next_attempt_at' => $quarantine ? null : $at->addSeconds(60), 'updated_at' => $at])->save();

            return $work->state;
        });
    }

    public function owns(ContractRenderWork $work, string $token, $at): bool
    {
        return $work->state === 'processing' && $work->claim_token === $token
            && ! $at->lessThan($work->updated_at) && $work->lease_expires_at->greaterThan($at);
    }

    /** Shared ordering for publication/failure: order -> grant -> request -> work. No external I/O in callback. */
    public function locked(int $requestId, callable $callback): mixed
    {
        $policy = app(ContractIssuancePolicy::class); $policy->current(); $account = $policy->account();
        ContractIssuancePolicy::outsideTransactions();
        $request = ContractRenderRequest::find($requestId);
        if (! $request) { throw new ContractIssuanceException('unavailable'); }
        $grant = LicenseGrant::findOrFail($request->license_grant_id);
        $finalization = OrderFinalization::findOrFail($grant->order_finalization_id);

        return DB::transaction(function () use ($requestId, $grant, $finalization, $account, $callback) {
            Order::whereKey($finalization->order_id)->lockForUpdate()->firstOrFail();
            $lockedGrant = LicenseGrant::whereKey($grant->id)->lockForUpdate()->firstOrFail();
            $request = ContractRenderRequest::whereKey($requestId)->lockForUpdate()->firstOrFail();
            $work = ContractRenderWork::where('contract_render_request_id', $request->id)->lockForUpdate()->sole();
            $payment = VerifiedPayment::findOrFail($finalization->verified_payment_id);
            if ($payment->account_id !== $account || $payment->mode !== 'test' || $finalization->mode !== 'test'
                || $finalization->outcome !== 'paid' || $lockedGrant->order_finalization_id !== $finalization->id
                || $request->license_grant_id !== $lockedGrant->id) { throw new ContractIssuanceException('unavailable'); }

            return $callback($request, $work);
        }, 5);
    }
}
