<?php

namespace App\Domain\Contracts;

use App\Domain\Commerce\Finalization\FinalizationEvidence;
use App\Domain\Commerce\Models\FulfillmentOutbox;
use App\Domain\Commerce\Models\LicenseGrant;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\Models\VerifiedPayment;
use App\Domain\Commerce\Orders\OrderRequest;
use App\Domain\Commerce\Orders\ReadOrder;
use App\Domain\Contracts\Models\ContractRenderRequest;
use App\Domain\Contracts\Models\ContractRenderWork;
use App\Support\CanonicalJson;
use Illuminate\Database\QueryException;
use Throwable;

/** Historical evidence only: no current catalog lookup, provider call or private-file access. */
final class ContractEvidence
{
    public function source(int $grantId, ?string $account = null): array
    {
        try {
            $grant = LicenseGrant::find($grantId);
            if (! $grant) { throw new ContractIssuanceException('unavailable'); }
            $finalization = OrderFinalization::findOrFail($grant->order_finalization_id);
            $payment = VerifiedPayment::findOrFail($finalization->verified_payment_id);
            if ($account !== null && $payment->account_id !== $account) { throw new ContractIssuanceException('unavailable'); }
            if ($finalization->mode !== 'test' || $finalization->outcome !== 'paid' || $payment->mode !== 'test') {
                throw new ContractIssuanceException('evidence_changed');
            }
            $order = Order::findOrFail($finalization->order_id);
            app(ReadOrder::class)->verify($order); // Includes every finalization child, not just this grant.
            $input = app(FinalizationEvidence::class)->decrypt($grant->render_input_ciphertext,
                $grant->render_input_hash, $grant->canonicalization_version);
            $outbox = FulfillmentOutbox::where('license_grant_id', $grant->id)->sole();
            if ($outbox->kind !== 'render_test_contract_v1' || $outbox->order_finalization_id !== $finalization->id
                || $outbox->state !== 'pending' || ($input['grant_id'] ?? null) !== $grant->public_id) {
                throw new ContractIssuanceException('evidence_changed');
            }

            return compact('grant', 'order', 'finalization', 'payment', 'input', 'outbox');
        } catch (ContractIssuanceException|QueryException $error) { throw $error; }
        catch (Throwable) { throw new ContractIssuanceException('evidence_changed'); }
    }

    public function request(ContractRenderRequest $request, ?string $account = null): array
    {
        try {
            $source = $this->source($request->license_grant_id, $account);
            $profile = app(ContractRenderProfile::class)->validate($request->profile);
            $work = ContractRenderWork::where('contract_render_request_id', $request->id)->sole();
            if (! OrderRequest::uuid($request->public_id) || ! OrderRequest::uuid($request->document_public_id)
                || $request->canonicalization_version !== CanonicalJson::VERSION
                || $request->input_hash !== $source['grant']->render_input_hash
                || $request->fulfillment_outbox_id !== $source['outbox']->id
                || $request->profile_hash !== CanonicalJson::hash($profile)
                || $request->created_at->lessThan($source['grant']->created_at)
                || $work->created_at->lessThan($request->created_at) || $work->updated_at->lessThan($work->created_at)) {
                throw new ContractIssuanceException('evidence_changed');
            }

            return $source + compact('profile', 'work');
        } catch (ContractIssuanceException|QueryException $error) { throw $error; }
        catch (Throwable) { throw new ContractIssuanceException('evidence_changed'); }
    }
}
