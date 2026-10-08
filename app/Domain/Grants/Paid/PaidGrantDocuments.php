<?php

namespace App\Domain\Grants\Paid;

use App\Domain\Contracts\RenderedContract;
use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Grants\Paid\Models\PaidOrderOrigin;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/** Bounded winning claims; physical work stays outside transactions, all lines activate together. */
final class PaidGrantDocuments
{
    public const MAX_ATTEMPTS = 5;

    public const LEASE_SECONDS = 300;

    /**
     * The buyer's heavy-work lock must outlive the longest step it covers (Codex 4223825205). In prepare() it is taken
     * before the claim frame, which runs on the call budget (up to LEASE_SECONDS left), and the claimed line then runs its
     * own LEASE_SECONDS budget for render, asset hash and record or failure frame: at most 2 x LEASE_SECONDS, plus the
     * frames' post-commit tails. complete() holds it for one line's re-verification (LEASE_SECONDS budget) only. 60 s of
     * margin covers the tails; a crashed worker's lock still frees itself within this TTL.
     */
    public const HEAVY_LOCK_SECONDS = 2 * self::LEASE_SECONDS + 60;

    /**
     * Prepares as many lines as this request's budget allows, then completes the order. The request ends with the current
     * projection, not a refusal, when its call budget is spent or other work holds the buyer's preparation (condition C13);
     * the page then continues with another request.
     *
     * @param  bool|null  $busy  Set to true when this request yielded to other work (another request's live claim or the
     *                           buyer's heavy-work lock), false otherwise.
     */
    public function prepare(string $batchId, ProductionCustomerPrincipal $principal, User $actor, ?PaidGrantProjectionRead $projectionRead = null,
        ?bool &$busy = null): array
    {
        PaidGrantInput::uuid($batchId);
        $busy = false;
        $deadline = PaidGrantDeadline::start(self::LEASE_SECONDS);
        $commands = new PaidGrantCommands;
        for ($lineNumber = 0; $lineNumber < 10; $lineNumber++) {
            // The call budget only decides whether another line may be claimed. Once it is spent the request answers with
            // what it achieved instead of 410, and the page continues with a new request (C13).
            if (hrtime(true) > $deadline->value()) {
                return $this->current($batchId, $principal, $actor, $projectionRead);
            }
            // One heavy step per buyer account at a time (C12, availability only): held from just before the claim through
            // the line's render, asset hash and record or failure frame. Another request holding it means no claim, no
            // render and no hashing here; no attempt is spent.
            $lock = $this->heavyLock($principal);
            if ($lock === null) {
                $busy = true;

                return $this->current($batchId, $principal, $actor, $projectionRead);
            }
            try {
                $prepared = $this->prepareLine($batchId, $principal, $actor, $deadline, $commands);
            } finally {
                $lock->release();
            }
            if ($prepared === 'busy') {
                $busy = true;

                return $this->current($batchId, $principal, $actor, $projectionRead);
            }
            if ($prepared === 'done') {
                break;
            }
        }
        if (hrtime(true) > $deadline->value()) {
            return $this->current($batchId, $principal, $actor, $projectionRead);
        }

        return $this->complete($batchId, $principal, $actor, $deadline, $projectionRead, $busy);
    }

    /** One claimed line from its claim through its record or failure frame; 'busy', 'done' or 'prepared'. */
    private function prepareLine(string $batchId, ProductionCustomerPrincipal $principal, User $actor, PaidGrantDeadline $deadline, PaidGrantCommands $commands): string
    {
        $claim = $commands->run($batchId, $principal, $actor, function (array $graph, PaidGrantRows $rows): array {
            if ($graph['complete'] !== null) {
                return ['done' => true];
            }
            foreach ($graph['lines'] as $line) {
                if ($line['work']['state'] === 'complete') {
                    continue;
                }
                // The lease's own instant on both clocks: the line's budget below ends with this lease, never after it.
                $now = CarbonImmutable::now('UTC');
                $tick = hrtime(true);
                $at = $now->startOfSecond();
                if ($line['work']['state'] === 'claimed' && $at->lessThan($line['work']['expires_at'])) {
                    return ['busy' => true, 'projection' => (new PaidGrants)->project($graph)];
                }
                PaidGrantException::require((int) $line['work']['attempts'] < self::MAX_ATTEMPTS, 409);
                $claimId = (string) Str::uuid();
                $lease = $at->addSeconds(self::LEASE_SECONDS)->format('Y-m-d H:i:s');
                $rows->execute('UPDATE '.$rows->table('paid_document_work')." SET state = 'claimed', attempts = attempts + 1, claim_id = ?, expires_at = ? WHERE id = ?",
                    [$claimId, $lease, $line['work']['id']]);

                return ['origin' => $line['origin']['public_id'], 'origin_hash' => $line['origin']['payload_hash'], 'claim_id' => $claimId,
                    'body' => $line['body'], 'expires_at' => $lease,
                    // Monotonic end of the lease: the claim instant less the second's fraction the lease truncated away.
                    'lease_ends_ns' => $tick - $now->micro * 1000 + self::LEASE_SECONDS * 1_000_000_000];
            }

            return ['done' => true];
        }, $deadline);
        if (isset($claim['busy'])) {
            // Another request's live claim; the caller answers with a fresh terminal read.
            return 'busy';
        }
        if (isset($claim['done'])) {
            return 'done';
        }
        // The call-wide budget only gates new claims (the loop top and the claim frame). A claimed line gets its own
        // budget for its render, asset verification, record frame and failure frame, so a line claimed late in the call
        // is not cut off with most of its lease left and its attempt spent. The budget ends with the claim's database
        // lease, never after it (independent review A11-I1): the lease began inside the claim frame, before its commit and
        // post-commit proofs, so a budget started only now would outlive it and run work the record frame then refuses.
        // The record frame still refuses once the lease itself has ended.
        $budget = PaidGrantDeadline::start(self::LEASE_SECONDS);
        $budget->shortenTo($claim['lease_ends_ns']);
        try {
            $budget->proveCurrent();
            $input = PaidGrantRenderInput::fromOrigin($claim['body']);
            $expected = (new PaidGrantText)->build($input);
            $rendered = app(PaidGrantRendererProcess::class)->render($input, $claim['body']['profile']);
            PaidGrantException::require($rendered instanceof RenderedContract
                && hash_equals($expected['text_digest'], $rendered->textDigest)
                && hash_equals(CanonicalJson::hash($claim['body']['profile']), $rendered->profileHash));
            $artifact = app(PaidGrantFiles::class)->store($claim['origin'], $claim['claim_id'], $claim['body']['source']['provenance'], $rendered);
            $budget->proveCurrent();
            (new PaidGrantAssets)->verify($claim['body']['assets'], $budget->value());
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
            }, $budget);
        } catch (Throwable $error) {
            // Only this still-owned claim may become failed. Withdrawal/staleness never renews authority. The line's
            // own budget lets a late line's failure be recorded after the call-wide budget lapsed; once the line's
            // budget has lapsed too, the claim stays until its lease ends.
            try {
                $commands->run($batchId, $principal, $actor, function (array $graph, PaidGrantRows $rows) use ($claim): array {
                    $line = $this->line($graph, $claim['origin']);
                    if ($line['original'] === [] && $line['work']['state'] === 'claimed' && $line['work']['claim_id'] === $claim['claim_id']) {
                        $rows->execute('UPDATE '.$rows->table('paid_document_work')." SET state = 'failed' WHERE id = ?", [$line['work']['id']]);
                    }

                    return [];
                }, $budget);
            } catch (Throwable) {
                // Retained lease/attempt remains truthful until a fresh authorized retry after expiry.
            }
            throw $error;
        }

        return 'prepared';
    }

    private function complete(string $batchId, ProductionCustomerPrincipal $principal, User $actor, PaidGrantDeadline $deadline, ?PaidGrantProjectionRead $projectionRead,
        ?bool &$busy): array
    {
        $commands = new PaidGrantCommands;
        $bundle = $commands->run($batchId, $principal, $actor, function (array $graph): array {
            PaidGrantException::require(count(array_filter($graph['lines'], fn (array $line): bool => $line['work']['state'] === 'complete')) === count($graph['lines']), 409);

            return $graph;
        }, $deadline);
        // An already fulfilled order is not re-verified: each download re-hashes its exact bytes anyway, and re-hashing up
        // to 10 lines of 1 GiB assets per request only spends the server (independent review A10-L1). The fulfillment
        // frame below still runs: it requires the unchanged bundle, inserts nothing and returns the projection.
        foreach ($bundle['complete'] === null ? $bundle['lines'] : [] as $line) {
            // Each line's physical re-verification gets its own non-extendable bound, the same as the per-line render
            // lease, so a large order (up to 10 lines of 1 GiB assets) is not bounded by the one budget that prepare()
            // started. A refused line fulfils nothing, and a retry finds every line prepared and gets fresh bounds.
            // The same buyer lock as a render step (C12): no re-verification runs beside other heavy work of this buyer.
            $lock = $this->heavyLock($principal);
            if ($lock === null) {
                $busy = true;

                return $this->current($batchId, $principal, $actor, $projectionRead);
            }
            try {
                $lineBudget = PaidGrantDeadline::start(self::LEASE_SECONDS);
                // Existing originals are exact restore-only. Missing bytes never create another render claim.
                app(PaidGrantFiles::class)->verify($line['manifest']['artifact']);
                (new PaidGrantAssets)->verify($line['body']['assets'], $lineBudget->value());
                $lineBudget->proveCurrent();
            } finally {
                $lock->release();
            }
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

    /** The current projection through a fresh terminal read frame; its own receipt, never one lent by an earlier frame. */
    private function current(string $batchId, ProductionCustomerPrincipal $principal, User $actor, ?PaidGrantProjectionRead $projectionRead): array
    {
        return (new PaidGrantCommands)->run($batchId, $principal, $actor,
            fn (array $graph): array => (new PaidGrants)->project($graph), PaidGrantDeadline::start(), projectionRead: $projectionRead);
    }

    /**
     * Non-blocking per-account lock on the default cache store (C12). It only keeps one buyer from running parallel heavy
     * I/O; claims, leases and `batch_once` stay the correctness guarantees. A crashed worker's lock expires after
     * HEAVY_LOCK_SECONDS. Released by its owner token only.
     */
    private function heavyLock(ProductionCustomerPrincipal $principal): ?Lock
    {
        $lock = Cache::lock('paid-grant-heavy:'.hash('sha256', 'paid-heavy-v1:'.$principal->accountId), self::HEAVY_LOCK_SECONDS);

        return $lock->get() ? $lock : null;
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
