<?php

namespace App\Domain\Grants\ProductionFree;

use App\Domain\Contracts\ContractIo;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Worker-side original rendering. A claim is an append-only leased row; an uncertain claim is never reset or
 * overwritten, only superseded after its lease expires. The render input is the sealed origin payload alone.
 */
final class ProductionFreeGrantDocuments
{
    /** Render, store write-once and publish the original; an existing original is returned unchanged. */
    public function render(string $originId): array
    {
        ProductionFreeGrantInput::uuid($originId);
        $claim = DB::transaction(function () use ($originId): array {
            $policy = (new ProductionFreeGrantPolicy)->current();
            $rows = new ProductionFreeGrantRows;
            $graph = $this->graph($originId, $rows);
            if ($graph['original'] === []) {
                ProductionFreeGrantException::require($graph['revocation'] === [], 'revoked');
                // Refuse before claiming: a profile the runtime cannot render must not burn one of the 32 attempts.
                ProductionFreeGrantRenderProfile::requireCurrent($graph['payload']['profile']);
                $last = $graph['work'] === [] ? null : $graph['work'][array_key_last($graph['work'])];
                $now = ProductionFreeGrantInput::now();
                ProductionFreeGrantException::require($last === null || $last['kind'] === 'failed'
                    || ProductionFreeGrantInput::parse($last['lease_expires_at'])->lessThanOrEqualTo($now), 'claim_in_progress');
                ProductionFreeGrantException::require(count($graph['work']) < 32, 'attempts_exhausted');
                $claimId = (string) Str::uuid();
                $lease = $now->addSeconds($policy['render_lease_seconds']);
                $this->work($rows, $graph, count($graph['work']), 'claimed', $claimId, $lease, $now);
                $graph = $this->graph($originId, $rows);
            }
            (new ProductionFreeGrantPolicy)->prove($policy);
            $rows->assertCurrent();

            return $graph;
        });
        if ($claim['original'] !== []) {
            return (new ProductionFreeGrants)->project($claim);
        }
        $work = $claim['work'][array_key_last($claim['work'])];
        try {
            $payload = $claim['payload'];
            $input = ProductionFreeGrantRenderInput::fromOrigin($payload);
            $rendered = app(ProductionFreeGrantRendererProcess::class)->render($input, $payload['profile']);
            ProductionFreeGrantException::require(hash_equals(CanonicalJson::hash($payload['profile']), $rendered->profileHash), 'profile_changed');
            $artifact = (new ProductionFreeGrantFiles)->store($originId, $work['claim_id'], $rendered);

            return DB::transaction(function () use ($originId, $claim, $work, $input, $rendered, $artifact): array {
                $policy = (new ProductionFreeGrantPolicy)->current();
                $rows = new ProductionFreeGrantRows;
                $current = $this->graph($originId, $rows);
                ProductionFreeGrantException::require(CanonicalJson::encode($current) === CanonicalJson::encode($claim), 'claim_superseded');
                $now = ProductionFreeGrantInput::now();
                ProductionFreeGrantException::require($now->lessThan(ProductionFreeGrantInput::parse($work['lease_expires_at'])), 'lease_expired');
                $id = (string) Str::uuid();
                $manifest = ['schema_version' => 'production-free-original-v1', 'original_id' => $id, 'origin_seal' => $claim['origin']['seal'],
                    'claim_id' => $work['claim_id'], 'work_id' => $work['id'], 'input_hash' => CanonicalJson::hash($input),
                    'profile_hash' => $rendered->profileHash, 'text_digest' => $rendered->textDigest, 'artifact' => $artifact,
                    'page_count' => $rendered->pageCount, 'published_at' => ProductionFreeGrantInput::iso($now)];
                $rows->insert('production_free_originals', ['id' => $id, 'origin_id' => $originId, 'work_id' => $work['id'],
                    'claim_id' => $work['claim_id'], 'sha256' => $rendered->sha256, 'bytes' => $rendered->sizeBytes,
                    'profile_hash' => $rendered->profileHash, 'input_hash' => $manifest['input_hash'], 'text_digest' => $rendered->textDigest,
                    'created_at' => ProductionFreeGrantInput::stored($now)], $manifest);
                $published = $this->graph($originId, $rows);
                (new ProductionFreeGrantPolicy)->prove($policy);
                $rows->assertCurrent();

                return (new ProductionFreeGrants)->project($published);
            });
        } catch (Throwable $error) {
            $this->fail($originId, $work);
            throw $error instanceof ProductionFreeGrantException ? $error : new ProductionFreeGrantException('render_failed');
        }
    }

    /**
     * Recovery from stored bytes: the retained file must match its recorded hash, and a fresh render of the sealed
     * origin must be byte-identical. Nothing is rewritten; a mismatch is reported, never repaired.
     */
    public function recover(string $originId): array
    {
        ProductionFreeGrantInput::uuid($originId);
        $graph = DB::transaction(function () use ($originId): array {
            $policy = (new ProductionFreeGrantPolicy)->current();
            $rows = new ProductionFreeGrantRows;
            $graph = $this->graph($originId, $rows);
            ProductionFreeGrantException::require($graph['original'] !== [], 'original_unavailable');
            ProductionFreeGrantRenderProfile::requireCurrent($graph['payload']['profile']);
            (new ProductionFreeGrantPolicy)->prove($policy);

            return $graph;
        });
        $stored = (new ProductionFreeGrantFiles)->verify($graph['manifest']['artifact']);
        $rendered = app(ProductionFreeGrantRendererProcess::class)->render(ProductionFreeGrantRenderInput::fromOrigin($graph['payload']), $graph['payload']['profile']);
        ContractIo::outsideTransactions();
        ProductionFreeGrantException::require(hash_equals($graph['original']['sha256'], hash('sha256', $stored))
            && hash_equals($graph['original']['sha256'], $rendered->sha256) && $rendered->pdfBytes === $stored, 'render_drift');

        return ['originId' => $originId, 'sha256' => $graph['original']['sha256'], 'bytes' => strlen($stored), 'identical' => true];
    }

    /** Worker read of one origin; ownership is checked by the customer reads, not here. */
    public function graph(string $originId, ProductionFreeGrantRows $rows): array
    {
        $origin = $rows->one('production_free_origins', 'id = ?', [$originId]);
        ProductionFreeGrantException::require($origin !== [], 'not_found');

        return (new ProductionFreeGrants)->originGraph($originId, (int) $origin['account_id'], $rows);
    }

    private function work(ProductionFreeGrantRows $rows, array $graph, int $ordinal, string $kind, string $claimId, CarbonImmutable $lease, CarbonImmutable $at): void
    {
        $id = (string) Str::uuid();
        $rows->insert('production_free_document_work', ['id' => $id, 'origin_id' => $graph['origin']['id'], 'claim_id' => $claimId,
            'ordinal' => $ordinal, 'kind' => $kind, 'lease_expires_at' => ProductionFreeGrantInput::stored($lease), 'created_at' => ProductionFreeGrantInput::stored($at)],
            ['schema_version' => 'production-free-work-v1', 'work_id' => $id, 'origin_seal' => $graph['origin']['seal'], 'claim_id' => $claimId,
                'kind' => $kind, 'at' => ProductionFreeGrantInput::iso($at)]);
    }

    /** Only the still-current, unexpired claim may append its own failure; anything else stays as recorded. */
    private function fail(string $originId, array $work): void
    {
        try {
            DB::transaction(function () use ($originId, $work): void {
                $rows = new ProductionFreeGrantRows;
                $graph = $this->graph($originId, $rows);
                $last = $graph['work'] === [] ? null : $graph['work'][array_key_last($graph['work'])];
                $now = ProductionFreeGrantInput::now();
                if ($graph['original'] === [] && $last !== null && $last['id'] === $work['id'] && $last['kind'] === 'claimed'
                    && $now->lessThan(ProductionFreeGrantInput::parse($last['lease_expires_at']))) {
                    $this->work($rows, $graph, count($graph['work']), 'failed', $last['claim_id'], ProductionFreeGrantInput::parse($last['lease_expires_at']), $now);
                }
            });
        } catch (Throwable) {
            // An unrecordable failure leaves the claim to expire; the original intent is retained either way.
        }
    }
}
