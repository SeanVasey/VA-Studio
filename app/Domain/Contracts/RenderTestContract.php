<?php

namespace App\Domain\Contracts;

use App\Domain\Contracts\Models\ContractRenderRequest;
use App\Domain\Contracts\Models\ContractRenderWork;
use App\Domain\Contracts\Models\GrantContract;
use App\Support\Audit\AuditEvent;
use Throwable;

final class RenderTestContract
{
    /** ID-only trusted worker/console entry. No active entitlement or public document is created. */
    public function handle(int $requestId): string
    {
        $claim = null;
        try {
            $policy = app(ContractIssuancePolicy::class); $policy->current(); $account = $policy->account();
            ContractIssuancePolicy::outsideTransactions();
            $request = ContractRenderRequest::find($requestId);
            if (! $request) { return 'unavailable'; }
            $document = app(ReadGrantContract::class)->forRequest($request, $account);
            if ($document) {
                app(ContractFiles::class)->verify($document);
                return 'ready'; // A missing original is restore-only, never a fresh render request.
            }
            $workService = app(ContractWork::class);
            $claim = $workService->claim($requestId);
            if ($claim === null) {
                $source = app(ContractEvidence::class)->request($request, $account);
                if ($source['work']->state === 'completed') {
                    $winner = app(ReadGrantContract::class)->forRequest($request->fresh(), $account);
                    app(ContractFiles::class)->verify($winner);
                    return 'ready';
                }
                return match ($source['work']->state) {
                    'quarantined' => 'quarantined', 'retry', 'pending' => 'pending',
                    default => 'busy',
                };
            }
            $source = app(ContractEvidence::class)->request($request->fresh(), $account);
            $rendered = app(ContractRenderer::class)->render($source['input'], $source['profile']);
            $expectedText = app(ContractText::class)->build($source['input'])['text_digest'];
            if ($rendered->profileHash !== $request->profile_hash || $rendered->textDigest !== $expectedText
                || $rendered->sha256 !== hash('sha256', $rendered->pdfBytes) || $rendered->sizeBytes !== strlen($rendered->pdfBytes)
                || $rendered->pageCount < 1 || $rendered->pageCount > 100) { throw new ContractIssuanceException('invalid_pdf'); }
            $file = app(ContractFiles::class)->store($request->public_id, $claim['token'], $rendered);

            return $workService->locked($requestId, function ($locked, $work) use ($claim, $source, $file, $account, $workService): string {
                $at = now()->toImmutable()->utc()->startOfSecond();
                if (! $workService->owns($work, $claim['token'], $at)) { return 'stale'; }
                $fresh = app(ContractEvidence::class)->request($locked, $account);
                if ($locked->input_hash !== $source['grant']->render_input_hash || $locked->profile_hash !== $file['profile_hash']) {
                    throw new ContractIssuanceException('evidence_changed');
                }
                $document = GrantContract::create(['public_id' => $locked->document_public_id,
                    'license_grant_id' => $fresh['grant']->id, 'contract_render_request_id' => $locked->id,
                    'input_hash' => $locked->input_hash, 'profile_hash' => $locked->profile_hash,
                    'disk' => $file['disk'], 'storage_path' => $file['storage_path'], 'claim_token' => $claim['token'],
                    'pdf_hash' => $file['pdf_hash'], 'size_bytes' => $file['size_bytes'], 'page_count' => $file['page_count'], 'issued_at' => $at]);
                $work->forceFill(['state' => 'completed', 'claim_token' => null, 'lease_expires_at' => null,
                    'next_attempt_at' => null, 'reason' => null, 'updated_at' => $at])->save();
                AuditEvent::record('commerce.contract.test_issued', $document, ['grant_public_id' => $fresh['grant']->public_id,
                    'document_public_id' => $document->public_id, 'test_only' => true]);
                app(ReadGrantContract::class)->forRequest($locked, $account);

                return 'ready';
            });
        } catch (ContractIssuanceException $error) {
            if ($error->reason === 'unavailable' || $error->reason === 'original_unavailable') { return $error->reason; }
            if ($claim !== null) {
                $result = $this->fail($requestId, $claim['token'], $error->reason,
                    ! in_array($error->reason, ['render_failed', 'storage_failed'], true));
                if ($result === 'stale') { return 'stale'; }
                return $error->reason === 'evidence_changed' ? 'changed' : $result;
            }
            return 'changed';
        } catch (Throwable) {
            return $claim === null ? 'changed' : $this->fail($requestId, $claim['token'], 'render_failed');
        }
    }

    private function fail(int $requestId, string $token, string $reason, bool $permanent = false): string
    {
        try { return app(ContractWork::class)->fail($requestId, $token, $reason, $permanent); }
        catch (ContractIssuanceException $error) { return $error->reason === 'unavailable' ? 'unavailable' : 'changed'; }
        catch (Throwable) { return 'retry'; }
    }
}
