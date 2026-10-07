<?php

namespace App\Domain\Grants\Paid;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\ProductionPaidOrderLocatorV1;
use App\Domain\Commerce\ProductionCheckout\ProductionPaidOrderSourceV1;
use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Grants\Paid\Models\PaidOrderOrigin;
use App\Domain\Rights\LicenseDisclosure;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** New typed paid consumer; current original-session buyer authority precedes immutable historical proof. */
final class PaidGrants
{
    public function finalize(ProductionCustomerPrincipal $principal, User $actor, string $orderId): array
    {
        try {
            return $this->finalizeOwned($principal, $actor, $orderId);
        } catch (IdentityException) {
            throw new PaidGrantException(403);
        } catch (CheckoutException $error) {
            throw new PaidGrantException(in_array($error->status, [403, 404, 409, 422, 503], true) ? $error->status : 503);
        }
    }

    private function finalizeOwned(ProductionCustomerPrincipal $principal, User $actor, string $orderId): array
    {
        PaidGrantInput::uuid($orderId);
        $this->outsideTransactions();
        $locator = ProductionPaidOrderLocatorV1::locate($orderId);
        $receipt = null;
        $heldRows = null;
        try {
            $projection = DB::transaction(function () use ($principal, $actor, $orderId, $locator, &$receipt, &$heldRows): array {
                $rows = new PaidGrantRows;
                $heldRows = $rows;
                $policyService = app(PaidGrantPolicy::class);
                $policy = $policyService->capture();
                $access = app(ProductionCustomerAccess::class);
                $authority = $access->lock($principal, $actor, $rows->current());
                $current = $access->durableBinding($principal);
                $original = $locator->historicalBuyerBinding();
                $this->sameOwner($current, $original);
                $historical = $access->verifyHistoricalBinding($original, $rows->current());
                // The older consumer marker is never released/renewed between this capture and final source proof.
                $source = ProductionPaidOrderSourceV1::lockedRead($locator, $rows->current(), $historical);
                $lines = [];
                for ($position = 1; $position <= $source->lineCount(); $position++) {
                    $line = $source->line($position);
                    $policyService->source($line, $policy);
                    $lines[] = $line;
                }
                PaidGrantException::require(count($lines) >= 1 && count($lines) <= 10, 409);
                $batch = $rows->one('paid_order_origins', 'producer = ? AND order_public_id = ?', ['production_checkout_v1', $orderId]);
                if ($batch === []) {
                    $at = now()->utc()->startOfSecond();
                    $batchId = (string) Str::uuid();
                    $commitments = array_map(fn (array $line): array => array_intersect_key($line, array_flip(['line_id', 'line_hash', 'source_hash'])), $lines);
                    $batchPayload = ['schema_version' => 'paid-order-origin-v1', 'purpose' => 'paid-license-grant', 'origin_id' => $batchId,
                        'provenance' => $policy['provenance'], 'producer' => 'production_checkout_v1', 'order_id' => $orderId, 'order_hash' => $lines[0]['order_hash'],
                        'payment_id' => $lines[0]['payment_id'], 'payment_hash' => $lines[0]['payment_hash'], 'original_buyer' => $original,
                        'line_commitments' => $commitments, 'delivery_policy' => $policy, 'created_at' => $at->format('Y-m-d\TH:i:s\Z')];
                    $batch = PaidGrantRecords::insert('paid_order_origins', ['public_id' => $batchId, 'account_id' => $current['account_id'], 'actor_id' => $current['user_id'],
                        'producer' => 'production_checkout_v1', 'order_public_id' => $orderId, 'order_hash' => $lines[0]['order_hash'],
                        'payment_public_id' => $lines[0]['payment_id'], 'payment_hash' => $lines[0]['payment_hash'], 'line_count' => count($lines),
                        ...PaidGrantRecords::encode($batchPayload), 'created_at' => $at->format('Y-m-d H:i:s')], $rows);
                    foreach ($lines as $index => $line) {
                        $originId = (string) Str::uuid();
                        PaidGrantException::require($line['order_id'] === $orderId && $line['buyer'] === $original
                            && $line['payment_id'] === $batchPayload['payment_id'] && $line['payment_hash'] === $batchPayload['payment_hash'], 409);
                        $assets = (new PaidGrantAssets)->capture($line, $rows);
                        $payload = ['schema_version' => 'paid-grant-origin-v1', 'purpose' => 'paid-license-grant', 'origin_id' => $originId,
                            'batch_id' => $batchId, 'position' => $index + 1, 'source' => $line, 'assets' => $assets,
                            'disclosure' => app(LicenseDisclosure::class)->fromSnapshot($line['license']), 'delivery_policy' => $policy,
                            'profile' => PaidGrantRenderProfile::current($line['provenance'])];
                        (new PaidGrantText)->build(PaidGrantRenderInput::fromOrigin($payload));
                        $origin = PaidGrantRecords::insert('paid_grant_origins', ['public_id' => $originId, 'batch_id' => (int) $batch['id'], 'position' => $index + 1,
                            'line_public_id' => $line['line_id'], 'line_hash' => $line['line_hash'], 'source_hash' => $line['source_hash'],
                            ...PaidGrantRecords::encode($payload), 'created_at' => $batch['created_at']], $rows);
                        PaidGrantRecords::insert('paid_document_work', ['origin_id' => (int) $origin['id'], 'state' => 'pending', 'attempts' => 0,
                            'claim_id' => null, 'expires_at' => null, 'created_at' => $batch['created_at']], $rows);
                    }
                    $subject = new PaidOrderOrigin;
                    $subject->setRawAttributes($batch, true);
                    $subject->exists = true;
                    AuditEvent::recordAttributed('paid_origin.retained', $subject, ['origin_hash' => $batch['payload_hash']], (int) $current['user_id']);
                }
                PaidGrantException::require((int) $batch['account_id'] === $current['account_id'] && (int) $batch['actor_id'] === $current['user_id'], 404);
                $graph = $this->graph($batch['public_id'], $current['account_id'], $rows);
                $this->sameSources($graph, $lines, $policy, $original);
                $projection = $this->project($graph);
                $snapshots = $this->snapshots($graph, $rows);
                $receipt = PaidGrantReadReceipt::capture($rows, $principal, $actor, $authority, $policy, $snapshots);
                $this->fence($principal, $actor, $access, $authority, $source, $policy, $graph, $rows, $receipt);

                return $projection;
            });
        } catch (\Throwable $error) {
            $heldRows?->abort();
            throw $error;
        }
        PaidGrantException::require($receipt instanceof PaidGrantReadReceipt);
        $receipt->proveClosed();

        return $projection;
    }

    public function graph(string $id, int $accountId, PaidGrantRows $rows): array
    {
        $batch = $rows->one('paid_order_origins', 'public_id = ? AND account_id = ?', [$id, $accountId]);
        PaidGrantException::require($batch !== [], 404);
        $payload = PaidGrantRecords::decode($batch);
        PaidGrantException::require($payload['schema_version'] === 'paid-order-origin-v1' && $payload['purpose'] === 'paid-license-grant'
            && $payload['origin_id'] === $id && $payload['producer'] === $batch['producer'] && $payload['order_id'] === $batch['order_public_id']
            && $payload['order_hash'] === $batch['order_hash'] && $payload['payment_id'] === $batch['payment_public_id']
            && $payload['payment_hash'] === $batch['payment_hash'] && $payload['original_buyer']['account_id'] === $accountId
            && $payload['original_buyer']['user_id'] === (int) $batch['actor_id'] && $payload['provenance'] === $payload['original_buyer']['provenance']);
        $origins = $rows->rows('paid_grant_origins', 'batch_id = ?', [(int) $batch['id']], 11);
        usort($origins, fn (array $a, array $b): int => (int) $a['position'] <=> (int) $b['position']);
        PaidGrantException::require(count($origins) === (int) $batch['line_count'] && count($origins) === count($payload['line_commitments']));
        $lines = [];
        foreach ($origins as $index => $origin) {
            $body = PaidGrantRecords::decode($origin);
            $source = $body['source'];
            PaidGrantException::require($body['schema_version'] === 'paid-grant-origin-v1' && $body['purpose'] === 'paid-license-grant'
                && $body['origin_id'] === $origin['public_id'] && $body['batch_id'] === $id && $body['position'] === $index + 1
                && (int) $origin['position'] === $index + 1 && $source['order_id'] === $payload['order_id'] && $source['order_hash'] === $payload['order_hash']
                && $source['payment_id'] === $payload['payment_id'] && $source['payment_hash'] === $payload['payment_hash']
                && $source['line_id'] === $origin['line_public_id'] && $source['line_hash'] === $origin['line_hash'] && $source['source_hash'] === $origin['source_hash']
                && $source['buyer'] === $payload['original_buyer'] && $body['delivery_policy'] === $payload['delivery_policy']
                && $payload['line_commitments'][$index] === array_intersect_key($source, array_flip(['line_id', 'line_hash', 'source_hash'])));
            (new PaidGrantPolicy)->source($source, $payload['delivery_policy']);
            $work = $rows->one('paid_document_work', 'origin_id = ?', [(int) $origin['id']]);
            $original = $rows->one('paid_originals', 'origin_id = ?', [(int) $origin['id']]);
            PaidGrantException::require($work !== [] && in_array($work['state'], ['pending', 'claimed', 'failed', 'complete'], true)
                && (int) $work['attempts'] >= 0 && (int) $work['attempts'] <= 5
                && ($work['state'] === 'complete') === ($original !== []));
            $manifest = $original === [] ? null : PaidGrantRecords::decode($original);
            if ($manifest !== null) {
                PaidGrantException::require($manifest['schema_version'] === 'paid-first-original-v1' && $manifest['origin_hash'] === $origin['payload_hash']
                    && $original['claim_id'] === $work['claim_id'] && $manifest['profile_hash'] === CanonicalJson::hash($body['profile'])
                    && $manifest['input_hash'] === CanonicalJson::hash(PaidGrantRenderInput::fromOrigin($body))
                    && $manifest['artifact']['profile_hash'] === $manifest['profile_hash']
                    && $manifest['artifact']['purpose'] === 'paid-license-grant' && $manifest['artifact']['provenance'] === $source['provenance']
                    && $manifest['artifact']['storage_path'] === 'contracts/paid/'.$source['provenance'].'/'.$origin['public_id'].'/'.$work['claim_id'].'/original.pdf');
            }
            $lines[] = compact('origin', 'body', 'work', 'original', 'manifest');
        }
        $fulfillment = $rows->one('paid_fulfillments', 'batch_id = ?', [(int) $batch['id']]);
        $complete = $fulfillment === [] ? null : PaidGrantRecords::decode($fulfillment);
        if ($complete !== null) {
            PaidGrantException::require($complete['schema_version'] === 'paid-complete-order-v1' && $complete['batch_hash'] === $batch['payload_hash']
                && $complete['origin_hashes'] === array_column(array_column($lines, 'origin'), 'payload_hash')
                && $complete['original_hashes'] === array_column(array_column($lines, 'original'), 'payload_hash')
                && count(array_filter($lines, fn (array $line): bool => $line['work']['state'] === 'complete')) === count($lines));
        }

        return compact('batch', 'payload', 'lines', 'fulfillment', 'complete');
    }

    public function fence(ProductionCustomerPrincipal $principal, User $actor, ProductionCustomerAccess $access, array $authority,
        ProductionPaidOrderSourceV1 $source, array $policy, array $graph, PaidGrantRows $rows, PaidGrantReadReceipt $receipt): void
    {
        // All resolver/decrypt/helper/model callbacks run before the final raw graph, producer and current buyer fences.
        app(PaidGrantPolicy::class);
        app(ProductionCustomerAccess::class);
        $rows->callbackPhase();
        $actorId = $actor->getKey();
        PaidGrantException::require($actorId === (int) $authority['user']['id'], 403);
        PaidGrantException::require($this->graph($graph['batch']['public_id'], (int) $graph['batch']['account_id'], $rows) === $graph, 409);
        foreach ($graph['lines'] as $line) {
            (new PaidGrantAssets)->proveRetained($line['body']['assets'], $rows);
        }
        $receipt->proveLive();
        // Producer reproof does not resolve consumer authority; its public line extraction already occurred above.
        $source->proveRetainedCurrent($rows->current());
        $access->proveCurrent($principal, $actor, $rows->current(), $authority);
        PaidGrantPolicy::provePure($policy, $rows->configuration, $rows->environment);
        $rows->finish();
    }

    public function snapshots(array $graph, PaidGrantRows $rows): array
    {
        $result = [['paid_order_origins', 'id = ?', [$graph['batch']['id']], 2, [$graph['batch']]],
            ['paid_grant_origins', 'batch_id = ?', [$graph['batch']['id']], 11, $rows->rows('paid_grant_origins', 'batch_id = ?', [$graph['batch']['id']], 11)],
            ['paid_fulfillments', 'batch_id = ?', [$graph['batch']['id']], 2, $graph['fulfillment'] === [] ? [] : [$graph['fulfillment']]]];
        foreach ($graph['lines'] as $line) {
            $result[] = ['paid_document_work', 'origin_id = ?', [$line['origin']['id']], 2, [$line['work']]];
            $result[] = ['paid_originals', 'origin_id = ?', [$line['origin']['id']], 2, $line['original'] === [] ? [] : [$line['original']]];
            $result = [...$result, ...(new PaidGrantAssets)->snapshots($line['body']['assets'], $rows)];
        }

        return $result;
    }

    public function sameOwner(array $current, array $original): void
    {
        foreach (['origin_id', 'provenance', 'account_id', 'account_public_id', 'user_id', 'identity_policy_version', 'identity_policy_hash'] as $key) {
            PaidGrantException::require(isset($current[$key], $original[$key]) && $current[$key] === $original[$key], 404);
        }
    }

    private function sameSources(array $graph, array $sources, array $policy, array $original): void
    {
        PaidGrantException::require(CanonicalJson::encode($graph['payload']['original_buyer']) === CanonicalJson::encode($original)
            && CanonicalJson::encode($graph['payload']['delivery_policy']) === CanonicalJson::encode($policy)
            && CanonicalJson::encode(array_column(array_column($graph['lines'], 'body'), 'source')) === CanonicalJson::encode($sources), 409);
    }

    public function project(array $graph): array
    {
        return ['id' => $graph['batch']['public_id'], 'orderId' => $graph['payload']['order_id'], 'purpose' => 'paid-license-grant',
            'provenance' => $graph['payload']['provenance'], 'fulfilled' => $graph['complete'] !== null,
            'lines' => array_map(fn (array $line): array => ['id' => $line['origin']['public_id'], 'position' => (int) $line['origin']['position'],
                'title' => $line['body']['source']['product']['title'], 'license' => $line['body']['disclosure'],
                'declaredName' => $line['body']['source']['buyer_declarations']['legal_name'], 'assentedAt' => $line['body']['source']['assent']['accepted_at'],
                'documentStatus' => $line['work']['state'], 'attempts' => (int) $line['work']['attempts'],
                'files' => $graph['complete'] === null ? [] : array_map(fn (array $file): array => ['kind' => $file['role'], 'sha256' => $file['sha256'], 'sizeBytes' => $file['size_bytes']], $line['body']['assets']['files'])], $graph['lines'])];
    }

    public function outsideTransactions(): void
    {
        foreach (DB::getConnections() as $connection) {
            PaidGrantException::require($connection->transactionLevel() === 0 && ! ($connection->getRawPdo() instanceof \PDO && $connection->getRawPdo()->inTransaction()), 409);
        }
    }
}
