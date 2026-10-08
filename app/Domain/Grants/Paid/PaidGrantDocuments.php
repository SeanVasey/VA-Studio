<?php

namespace App\Domain\Grants\Paid;

use App\Domain\Contracts\RenderedContract;
use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Grants\Paid\Models\PaidOrderOrigin;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/** Bounded winning claims; physical work stays outside transactions, all lines activate together. */
final class PaidGrantDocuments
{
    public const MAX_ATTEMPTS = 5;

    public const LEASE_SECONDS = 300;

    public function prepare(string $batchId, ProductionCustomerPrincipal $principal, User $actor, ?PaidGrantProjectionRead $projectionRead = null): array
    {
        PaidGrantInput::uuid($batchId);
        $deadline = PaidGrantDeadline::start(self::LEASE_SECONDS);
        $commands = new PaidGrantCommands;
        for ($lineNumber = 0; $lineNumber < 10; $lineNumber++) {
            $deadline->proveCurrent();
            $claim = $commands->run($batchId, $principal, $actor, function (array $graph, PaidGrantRows $rows): array {
                if ($graph['complete'] !== null) {
                    return ['done' => true];
                }
                foreach ($graph['lines'] as $line) {
                    if ($line['work']['state'] === 'complete') {
                        continue;
                    }
                    $at = CarbonImmutable::now('UTC')->startOfSecond();
                    if ($line['work']['state'] === 'claimed' && $at->lessThan($line['work']['expires_at'])) {
                        return ['busy' => true, 'projection' => (new PaidGrants)->project($graph)];
                    }
                    PaidGrantException::require((int) $line['work']['attempts'] < self::MAX_ATTEMPTS, 409);
                    $claimId = (string) Str::uuid();
                    $lease = $at->addSeconds(self::LEASE_SECONDS)->format('Y-m-d H:i:s');
                    $rows->execute('UPDATE '.$rows->table('paid_document_work')." SET state = 'claimed', attempts = attempts + 1, claim_id = ?, expires_at = ? WHERE id = ?",
                        [$claimId, $lease, $line['work']['id']]);

                    return ['origin' => $line['origin']['public_id'], 'origin_hash' => $line['origin']['payload_hash'], 'claim_id' => $claimId,
                        'body' => $line['body'], 'expires_at' => $lease];
                }

                return ['done' => true];
            }, $deadline);
            if (isset($claim['busy'])) {
                // A fresh terminal read mints the body receipt; an earlier claim frame cannot lend it.
                return $commands->run($batchId, $principal, $actor,
                    fn (array $graph): array => (new PaidGrants)->project($graph), $deadline, projectionRead: $projectionRead);
            }
            if (isset($claim['done'])) {
                break;
            }
            try {
                $deadline->proveCurrent();
                $input = PaidGrantRenderInput::fromOrigin($claim['body']);
                $expected = (new PaidGrantText)->build($input);
                $rendered = app(PaidGrantRendererProcess::class)->render($input, $claim['body']['profile']);
                PaidGrantException::require($rendered instanceof RenderedContract
                    && hash_equals($expected['text_digest'], $rendered->textDigest)
                    && hash_equals(CanonicalJson::hash($claim['body']['profile']), $rendered->profileHash));
                $artifact = app(PaidGrantFiles::class)->store($claim['origin'], $claim['claim_id'], $claim['body']['source']['provenance'], $rendered);
                $deadline->proveCurrent();
                (new PaidGrantAssets)->verify($claim['body']['assets'], $deadline->value());
                $commands->run($batchId, $principal, $actor, function (array $graph, PaidGrantRows $rows) use ($claim, $artifact, $expected, $actor): array {
                    $line = $this->line($graph, $claim['origin']);
                    PaidGrantException::require($line['origin']['payload_hash'] === $claim['origin_hash'] && $line['body'] === $claim['body']
                        && $line['original'] === [] && $line['work']['state'] === 'claimed' && $line['work']['claim_id'] === $claim['claim_id']
                        && $line['work']['expires_at'] === $claim['expires_at'] && CarbonImmutable::now('UTC')->lessThan($claim['expires_at']), 409);
                    $at = CarbonImmutable::now('UTC')->startOfSecond();
                    $manifest = ['schema_version' => 'paid-first-original-v1', 'origin_hash' => $claim['origin_hash'], 'claim_id' => $claim['claim_id'],
                        'profile_hash' => CanonicalJson::hash($claim['body']['profile']), 'input_hash' => $expected['input_hash'], 'text_digest' => $expected['text_digest'],
                        'asset_manifest_hash' => CanonicalJson::hash($claim['body']['assets']), 'artifact' => $artifact, 'prepared_at' => $at->format('Y-m-d\TH:i:s\Z')];
                    PaidGrantRecords::insert('paid_originals', ['origin_id' => (int) $line['origin']['id'], 'claim_id' => $claim['claim_id'],
                        ...PaidGrantRecords::encode($manifest), 'created_at' => $at->format('Y-m-d H:i:s')], $rows);
                    $rows->execute('UPDATE '.$rows->table('paid_document_work')." SET state = 'complete' WHERE id = ?", [$line['work']['id']]);
                    $this->audit('paid_original.prepared', $graph, ['original_hash' => CanonicalJson::hash($manifest)], $actor);

                    return ['prepared' => true];
                }, $deadline);
            } catch (Throwable $error) {
                // Only this still-owned claim may become failed. Withdrawal/staleness never renews authority.
                try {
                    $commands->run($batchId, $principal, $actor, function (array $graph, PaidGrantRows $rows) use ($claim): array {
                        $line = $this->line($graph, $claim['origin']);
                        if ($line['original'] === [] && $line['work']['state'] === 'claimed' && $line['work']['claim_id'] === $claim['claim_id']) {
                            $rows->execute('UPDATE '.$rows->table('paid_document_work')." SET state = 'failed' WHERE id = ?", [$line['work']['id']]);
                        }

                        return [];
                    }, $deadline);
                } catch (Throwable) {
                    // Retained lease/attempt remains truthful until a fresh authorized retry after expiry.
                }
                throw $error;
            }
        }

        return $this->complete($batchId, $principal, $actor, $deadline, $projectionRead);
    }

    private function complete(string $batchId, ProductionCustomerPrincipal $principal, User $actor, PaidGrantDeadline $deadline, ?PaidGrantProjectionRead $projectionRead): array
    {
        $commands = new PaidGrantCommands;
        $bundle = $commands->run($batchId, $principal, $actor, function (array $graph): array {
            PaidGrantException::require(count(array_filter($graph['lines'], fn (array $line): bool => $line['work']['state'] === 'complete')) === count($graph['lines']), 409);

            return $graph;
        }, $deadline);
        foreach ($bundle['lines'] as $line) {
            // Each line's physical re-verification gets its own non-extendable bound, the same as the per-line render
            // lease, so a large order (up to 10 lines of 1 GiB assets) is not bounded by the one budget that prepare()
            // started. A refused line fulfils nothing, and a retry finds every line prepared and gets fresh bounds.
            $lineBudget = PaidGrantDeadline::start(self::LEASE_SECONDS);
            // Existing originals are exact restore-only. Missing bytes never create another render claim.
            app(PaidGrantFiles::class)->verify($line['manifest']['artifact']);
            (new PaidGrantAssets)->verify($line['body']['assets'], $lineBudget->value());
            $lineBudget->proveCurrent();
        }

        // A fresh observation budget for the fulfillment frame and its body receipt. The frame still requires the exact
        // bundle verified above and keeps every receipt, fence and post-commit proof of PaidGrantCommands::run.
        $commit = PaidGrantDeadline::start();

        return $commands->run($batchId, $principal, $actor, function (array $graph, PaidGrantRows $rows) use ($bundle, $actor, $commit): array {
            $commit->proveCurrent();
            PaidGrantException::require($graph === $bundle, 409);
            if ($graph['complete'] === null) {
                $at = CarbonImmutable::now('UTC')->startOfSecond();
                $manifest = ['schema_version' => 'paid-complete-order-v1', 'batch_hash' => $graph['batch']['payload_hash'],
                    'origin_hashes' => array_column(array_column($graph['lines'], 'origin'), 'payload_hash'),
                    'original_hashes' => array_column(array_column($graph['lines'], 'original'), 'payload_hash'),
                    'asset_manifest_hashes' => array_map(fn (array $line): string => CanonicalJson::hash($line['body']['assets']), $graph['lines']),
                    'observed_at' => $at->format('Y-m-d\TH:i:s\Z'), 'physical_observation' => 'exact_private_bytes_all_original_lines'];
                PaidGrantRecords::insert('paid_fulfillments', ['batch_id' => (int) $graph['batch']['id'], ...PaidGrantRecords::encode($manifest), 'created_at' => $at->format('Y-m-d H:i:s')], $rows);
                $this->audit('paid_order.fulfilled', $graph, ['fulfillment_hash' => CanonicalJson::hash($manifest)], $actor);
                $graph = (new PaidGrants)->graph($graph['batch']['public_id'], (int) $graph['batch']['account_id'], $rows);
            }

            return (new PaidGrants)->project($graph);
        }, $commit, projectionRead: $projectionRead);
    }

    private function line(array $graph, string $id): array
    {
        $matches = array_values(array_filter($graph['lines'], fn (array $line): bool => $line['origin']['public_id'] === $id));
        PaidGrantException::require(count($matches) === 1, 404);

        return $matches[0];
    }

    private function audit(string $action, array $graph, array $context, User $actor): void
    {
        $subject = new PaidOrderOrigin;
        $subject->setRawAttributes($graph['batch'], true);
        $subject->exists = true;
        AuditEvent::recordAttributed($action, $subject, $context, (int) $actor->getKey());
    }
}
