<?php

namespace App\Domain\Contracts;

use App\Domain\Commerce\Orders\OrderRequest;
use App\Domain\Contracts\Models\ContractRenderRequest;
use App\Domain\Contracts\Models\ContractRenderWork;
use App\Domain\Contracts\Models\GrantContract;
use Illuminate\Database\QueryException;
use Throwable;

/** Verifies the retained manifest. Physical original verification is a separate, unlocked operation. */
final class ReadGrantContract
{
    public function forRequest(ContractRenderRequest $request, ?string $account = null): ?GrantContract
    {
        try {
            $source = app(ContractEvidence::class)->request($request, $account);
            $work = $source['work'];
            $documents = GrantContract::where('license_grant_id', $request->license_grant_id)
                ->orWhere('contract_render_request_id', $request->id)->get();
            if ($documents->isEmpty()) {
                if ($work->state === 'completed') { throw new ContractIssuanceException('evidence_changed'); }
                return null;
            }
            $document = $documents->sole();
            // Original publication and completion commit together. Refresh after observing the
            // immutable document so ordinary polling cannot combine it with a pre-commit claim.
            $work = ContractRenderWork::where('contract_render_request_id', $request->id)->sole();
            if ($work->state !== 'completed' || $work->attempts < 1 || $work->attempts > 5
                || $work->claim_token !== null || $work->lease_expires_at !== null || $work->next_attempt_at !== null || $work->reason !== null
                || $document->license_grant_id !== $request->license_grant_id || $document->contract_render_request_id !== $request->id
                || $document->public_id !== $request->document_public_id || ! OrderRequest::uuid($document->claim_token)
                || $document->input_hash !== $request->input_hash || $document->profile_hash !== $request->profile_hash
                || $document->disk !== 'local' || $document->storage_path !== 'contracts/test/'.$request->public_id.'/'.$document->claim_token.'/original.pdf'
                || ! preg_match('/\A[a-f0-9]{64}\z/D', $document->pdf_hash)
                || $document->size_bytes < 32 || $document->size_bytes > ContractFiles::MAX_BYTES
                || $document->page_count < 1 || $document->page_count > 100
                || $document->issued_at->lessThan($request->created_at) || ! $document->issued_at->equalTo($work->updated_at)) {
                throw new ContractIssuanceException('evidence_changed');
            }

            return $document;
        } catch (ContractIssuanceException|QueryException $error) { throw $error; }
        catch (Throwable) { throw new ContractIssuanceException('evidence_changed'); }
    }
}
