<?php

namespace App\Domain\Contracts;

use App\Domain\Commerce\Models\LicenseGrant;
use App\Domain\Commerce\Models\Order;
use App\Domain\Contracts\Models\ContractRenderRequest;
use App\Domain\Contracts\Models\ContractRenderWork;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RequestTestContract
{
    public function handle(int $grantId): ContractRenderRequest
    {
        $policy = app(ContractIssuancePolicy::class);
        $policy->current(); $account = $policy->account();
        ContractIssuancePolicy::outsideTransactions();
        $evidence = app(ContractEvidence::class);
        $source = $evidence->source($grantId, $account);
        $existing = ContractRenderRequest::where('license_grant_id', $grantId)->first();
        if ($existing) { $evidence->request($existing, $account); return $existing; }
        // Physical runtime/profile checks precede all locks. A retained request never changes profile.
        $profile = app(ContractRenderProfile::class)->current();

        return DB::transaction(function () use ($grantId, $account, $source, $profile, $evidence): ContractRenderRequest {
            Order::whereKey($source['order']->id)->lockForUpdate()->firstOrFail();
            LicenseGrant::whereKey($grantId)->lockForUpdate()->firstOrFail();
            $fresh = $evidence->source($grantId, $account);
            $existing = ContractRenderRequest::where('license_grant_id', $grantId)->lockForUpdate()->first();
            if ($existing) { $evidence->request($existing, $account); return $existing; }
            if ($fresh['grant']->render_input_hash !== $source['grant']->render_input_hash) {
                throw new ContractIssuanceException('evidence_changed');
            }
            $at = now()->toImmutable()->utc()->startOfSecond();
            if ($at->lessThan($fresh['grant']->created_at)) { throw new ContractIssuanceException('unavailable'); }
            $request = ContractRenderRequest::create(['public_id' => (string) Str::uuid(), 'license_grant_id' => $grantId,
                'fulfillment_outbox_id' => $fresh['outbox']->id, 'input_hash' => $fresh['grant']->render_input_hash,
                'profile' => $profile, 'profile_hash' => CanonicalJson::hash($profile),
                'canonicalization_version' => CanonicalJson::VERSION, 'document_public_id' => (string) Str::uuid(), 'created_at' => $at]);
            ContractRenderWork::create(['contract_render_request_id' => $request->id, 'state' => 'pending',
                'attempts' => 0, 'created_at' => $at, 'updated_at' => $at]);
            $evidence->request($request, $account);

            return $request;
        }, 5);
    }
}
