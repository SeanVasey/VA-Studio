<?php

namespace App\Domain\Grants\Free;

use App\Domain\Contracts\ContractFiles;
use App\Domain\Delivery\DeliveryAssets;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Bounded retryable claims; exact immutable originals publish only after outside-transaction durability proof. */
final class FreeGrantDocuments
{
    public function issue(string $id, string $originHash, object $principal, User $actor): array
    {
        FreeGrantInput::uuid($id);
        FreeGrantInput::hash($originHash);
        $grants = new FreeGrants;
        $claim = DB::transaction(function () use ($id, $originHash, $principal, $actor, $grants): array {
            $rows = new FreeGrantRows;
            $identity = (new FreeGrantPolicy)->identity();
            $authority = $identity->lock($principal, $actor, $rows);
            $accountId = (int) $identity->durableBinding($principal)['account_id'];
            $graph = $grants->originGraph($id, $accountId, $rows);
            FreeGrantException::require(hash_equals($graph['origin']['payload_hash'], $originHash), 409);
            if ($graph['work']['state'] !== 'complete') {
                $work = $graph['work'];
                FreeGrantException::require((int) $work['attempts'] < 5 && ($work['state'] !== 'claimed' || now()->greaterThanOrEqualTo($work['expires_at'])), 409);
                $claimId = (string) Str::uuid();
                $rows->execute('UPDATE '.$rows->table('free_document_work')." SET state = 'claimed', attempts = ?, claim_id = ?, expires_at = ? WHERE id = ?",
                    [(int) $work['attempts'] + 1, $claimId, now()->utc()->addSeconds(300)->format('Y-m-d H:i:s'), (int) $work['id']]);
                $graph = $grants->originGraph($id, $accountId, $rows);
            }
            $grants->customerFence($principal, $actor, $identity, $authority, $graph, $rows, false);

            return ['graph' => $graph, 'authority' => $authority, 'identity' => $identity];
        });
        $graph = $claim['graph'];
        if ($graph['work']['state'] === 'complete') {
            return $grants->originProject($graph);
        }
        try {
            $payload = $graph['payload'];
            $observedAt = now()->utc()->startOfSecond();
            // Existing low-level checks verify actual immutable private bytes and retained scan provenance.
            (new DeliveryAssets)->verify($payload['definition']['source']['assets']);
            $input = FreeGrantRenderInput::fromOrigin($payload);
            $rendered = app(FreeGrantRendererProcess::class)->render($input, $payload['profile']);
            $artifact = (new ContractFiles)->store($id, $graph['work']['claim_id'], $rendered);

            return DB::transaction(function () use ($id, $principal, $actor, $grants, $claim, $graph, $input, $rendered, $artifact, $observedAt): array {
                $rows = new FreeGrantRows;
                // The same typed identity is re-proved after physical work; no cached guard reminting.
                $identity = $claim['identity'];
                $authority = $identity->lock($principal, $actor, $rows);
                FreeGrantException::require($authority === $claim['authority'], 403);
                $current = $grants->originGraph($id, (int) $graph['origin']['account_id'], $rows);
                FreeGrantException::require($current === $graph && now()->lessThan($current['work']['expires_at'])
                    && now()->diffInSeconds($observedAt, absolute: true) <= 300, 409);
                $manifest = ['schema_version' => 'free-original-v1', 'origin_hash' => $graph['origin']['payload_hash'],
                    'profile_hash' => CanonicalJson::hash($graph['payload']['profile']), 'input_hash' => CanonicalJson::hash($input),
                    'text_digest' => $rendered->textDigest, 'artifact' => $artifact,
                    'asset_hashes' => array_column($graph['payload']['definition']['source']['assets'], 'sha256'), 'observed_at' => $observedAt->format('Y-m-d\TH:i:s\Z')];
                FreeGrantRecords::insert('free_originals', ['origin_id' => (int) $graph['origin']['id'], 'claim_id' => $graph['work']['claim_id'],
                    ...FreeGrantRecords::encode($manifest), 'created_at' => now()->utc()->format('Y-m-d H:i:s')], $rows);
                $rows->execute('UPDATE '.$rows->table('free_document_work')." SET state = 'complete' WHERE id = ? AND state = 'claimed' AND claim_id = ?",
                    [(int) $graph['work']['id'], $graph['work']['claim_id']]);
                $expected = $grants->originGraph($id, (int) $graph['origin']['account_id'], $rows);
                $grants->customerFence($principal, $actor, $identity, $authority, $expected, $rows, false);

                return $grants->originProject($expected);
            });
        } catch (Throwable $error) {
            // A stale/refused publication leaves its original path untouched. Only the winning
            // internal claim can append a failed technical status; it confers no grant authority.
            DB::transaction(function () use ($graph): void {
                $rows = new FreeGrantRows;
                $work = $rows->one('free_document_work', 'id = ?', [(int) $graph['work']['id']]);
                if ($work !== [] && $work['state'] === 'claimed' && $work['claim_id'] === $graph['work']['claim_id']) {
                    $rows->execute('UPDATE '.$rows->table('free_document_work')." SET state = 'failed' WHERE id = ?", [(int) $work['id']]);
                }
            });
            throw $error;
        }
    }
}
