<?php

namespace App\Domain\Grants\PaidTax;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxPaidLineAdapterV2;
use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxPaidOrderCommittedReadReceiptV2;
use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxPaidOrderLocatorV2;
use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxPaidOrderSourceV2;
use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Grants\PaidTax\Models\PaidTaxOrderOrigin;
use App\Domain\Rights\LicenseDisclosure;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Support\Str;

/** New typed paid consumer; current original-session buyer authority precedes immutable historical proof. */
final class PaidTaxGrants
{
    public function finalize(ProductionCustomerPrincipal $principal, User $actor, string $orderId, ?PaidTaxProjectionRead $projectionRead = null): array
    {
        try {
            return $this->finalizeOwned($principal, $actor, $orderId, $projectionRead);
        } catch (IdentityException) {
            throw new PaidTaxException(403);
        } catch (CheckoutException $error) {
            throw new PaidTaxException(in_array($error->status, [403, 404, 409, 422, 503], true) ? $error->status : 503);
        }
    }

    private function finalizeOwned(ProductionCustomerPrincipal $principal, User $actor, string $orderId, ?PaidTaxProjectionRead $projectionRead): array
    {
        PaidTaxInput::uuid($orderId);
        $deadline = PaidTaxDeadline::start();
        $runtime = PaidTaxRuntime::capture($deadline);
        $runtime->outsideTransactions();
        $actorOriginal = PaidTaxActor::capture($actor);
        $locator = ProductionTaxPaidOrderLocatorV2::locate($orderId);
        $receipt = null;
        $heldRows = null;
        $producerRead = null;
        $producerSecond = null;
        $heldSource = null;
        $heldFrame = null;
        $runtime->prove(0);
        try {
            $projection = PaidTaxTransaction::run($runtime, function (PaidTaxOwnedFrame $frame) use ($principal, $actor, $orderId, $locator, $deadline, $projectionRead, $runtime, $actorOriginal,
                &$heldSource, &$receipt, &$heldRows, &$producerRead, &$producerSecond): array {
                $rows = new PaidTaxRows($runtime, $frame);
                $heldRows = $rows;
                $policyService = app(PaidTaxPolicy::class);
                $policy = $policyService->capture();
                $access = app(ProductionCustomerAccess::class);
                $actorOriginal->prove();
                $authority = $access->lock($principal, $actor, $rows->current());
                $current = $access->durableBinding($principal);
                $original = $locator->historicalBuyerBinding();
                $this->sameOwner($current, $original);
                $historical = $access->verifyHistoricalBinding($original, $rows->current());
                $reader = $rows->admitSource();
                // No callback gap between original consumer admission and the younger producer capture.
                $source = ProductionTaxPaidOrderSourceV2::lockedRead($locator, $reader, $historical);
                $heldSource = $source;
                $lines = [];
                for ($position = 1; $position <= $source->lineCount(); $position++) {
                    $line = ProductionTaxPaidLineAdapterV2::accept($source, $position, $source->line($position), $policy['provenance'], $reader, $orderId);
                    $policyService->source($line, $policy);
                    $lines[] = $line;
                }
                PaidTaxException::require(count($lines) >= 1 && count($lines) <= 10, 409);
                $batch = $rows->one('paid_tax_order_origins', 'producer = ? AND order_public_id = ?', ['production_tax_checkout_v2', $orderId]);
                if ($batch === []) {
                    $at = now()->utc()->startOfSecond();
                    $batchId = (string) Str::uuid();
                    $commitments = array_map(fn (array $line): array => array_intersect_key($line, array_flip(['line_id', 'line_hash', 'source_hash'])), $lines);
                    $batchPayload = ['schema_version' => 'paid-tax-order-origin-v2', 'purpose' => 'paid-tax-license-grant', 'origin_id' => $batchId,
                        'provenance' => $policy['provenance'], 'producer' => 'production_tax_checkout_v2', 'order_id' => $orderId, 'order_hash' => $lines[0]['order_hash'],
                        'source_identity' => $this->sourceIdentity($lines[0]), 'original_buyer' => $original,
                        'line_commitments' => $commitments, 'delivery_policy' => $policy, 'created_at' => $at->format('Y-m-d\TH:i:s\Z')];
                    $batch = PaidTaxRecords::insert('paid_tax_order_origins', ['public_id' => $batchId, 'account_id' => $current['account_id'], 'actor_id' => $current['user_id'],
                        'producer' => 'production_tax_checkout_v2', 'order_public_id' => $orderId, 'order_hash' => $lines[0]['order_hash'],
                        'request_public_id' => $lines[0]['request_id'], 'request_hash' => $lines[0]['request_hash'],
                        'reviewed_session_public_id' => $lines[0]['reviewed_session_id'], 'reviewed_session_hash' => $lines[0]['reviewed_session_hash'],
                        'provider_account' => $lines[0]['provider_account'], 'funds_mode' => $lines[0]['funds_mode'],
                        'provider_session_id' => $lines[0]['provider_session_id'], 'provider_payment_id' => $lines[0]['provider_payment_id'], 'line_count' => count($lines),
                        ...PaidTaxRecords::encode($batchPayload), 'created_at' => $at->format('Y-m-d H:i:s')], $rows);
                    foreach ($lines as $index => $line) {
                        $originId = (string) Str::uuid();
                        PaidTaxException::require($line['order_id'] === $orderId && $line['buyer'] === $original
                            && $this->sourceIdentity($line) === $batchPayload['source_identity'], 409);
                        $assets = (new PaidTaxAssets)->capture($line, $rows);
                        $payload = ['schema_version' => 'paid-tax-grant-origin-v2', 'purpose' => 'paid-tax-license-grant', 'origin_id' => $originId,
                            'batch_id' => $batchId, 'position' => $index + 1, 'source' => $line, 'assets' => $assets,
                            'disclosure' => app(LicenseDisclosure::class)->fromSnapshot($line['license']), 'delivery_policy' => $policy,
                            'profile' => PaidTaxRenderProfile::current($line['provenance'])];
                        (new PaidTaxText)->build(PaidTaxRenderInput::fromOrigin($payload));
                        $origin = PaidTaxRecords::insert('paid_tax_grant_origins', ['public_id' => $originId, 'batch_id' => (int) $batch['id'], 'position' => $index + 1,
                            'line_public_id' => $line['line_id'], 'line_hash' => $line['line_hash'], 'source_hash' => $line['source_hash'],
                            ...PaidTaxRecords::encode($payload), 'created_at' => $batch['created_at']], $rows);
                        PaidTaxRecords::insert('paid_tax_document_work', ['origin_id' => (int) $origin['id'], 'state' => 'pending', 'attempts' => 0,
                            'claim_id' => null, 'expires_at' => null, 'created_at' => $batch['created_at']], $rows);
                    }
                    $subject = new PaidTaxOrderOrigin;
                    $subject->setRawAttributes($batch, true);
                    $subject->exists = true;
                    AuditEvent::recordAttributed('paid_tax_origin.retained', $subject, ['origin_hash' => $batch['payload_hash']], (int) $current['user_id']);
                }
                PaidTaxException::require((int) $batch['account_id'] === $current['account_id'] && (int) $batch['actor_id'] === $current['user_id'], 404);
                $graph = $this->graph($batch['public_id'], $current['account_id'], $rows);
                $this->sameSources($graph, $lines, $policy, $original);
                $projection = $this->project($graph);
                $snapshots = $this->snapshots($graph, $rows);
                $receipt = PaidTaxReadReceipt::capture($rows, $principal, $actor, $actorOriginal, $authority, $policy, $snapshots);
                $admission = PaidTaxConsumerCommitAdmission::capture($receipt, $deadline);
                $producerRead = $source->committedReadReceipt($reader, $deadline->value(), $admission);
                if ($projectionRead !== null) {
                    $producerSecond = $source->committedReadReceipt($reader, $deadline->value(), $admission);
                }
                $this->fence($principal, $actor, $access, $authority, $source, $policy, $graph, $rows, $receipt);

                return $projection;
            }, $heldFrame);
            PaidTaxException::require($receipt instanceof PaidTaxReadReceipt && $producerRead instanceof ProductionTaxPaidOrderCommittedReadReceiptV2
                && ($projectionRead === null || $producerSecond instanceof ProductionTaxPaidOrderCommittedReadReceiptV2));
            $projectionRead?->capture($receipt, $producerSecond, $deadline);
            $receipt->proveClosed();
            $producerRead->proveClosed();
            $receipt->proveRawClosed();
            $deadline->proveCurrent();

            return $projection;
        } catch (\Throwable $error) {
            PaidTaxCleanup::abort($heldSource, $heldRows, $heldFrame);
            throw $error;
        }
    }

    public function graph(string $id, int $accountId, PaidTaxRows $rows): array
    {
        $batch = $rows->one('paid_tax_order_origins', 'public_id = ? AND account_id = ?', [$id, $accountId]);
        PaidTaxException::require($batch !== [], 404);
        $payload = PaidTaxRecords::decode($batch);
        PaidTaxException::require($payload['schema_version'] === 'paid-tax-order-origin-v2' && $payload['purpose'] === 'paid-tax-license-grant'
            && $payload['origin_id'] === $id && $payload['producer'] === $batch['producer'] && $payload['order_id'] === $batch['order_public_id']
            && $payload['order_hash'] === $batch['order_hash'] && $payload['source_identity'] === $this->batchIdentity($batch)
            && $payload['original_buyer']['account_id'] === $accountId
            && $payload['original_buyer']['user_id'] === (int) $batch['actor_id'] && $payload['provenance'] === $payload['original_buyer']['provenance']);
        $origins = $rows->rows('paid_tax_grant_origins', 'batch_id = ?', [(int) $batch['id']], 11);
        usort($origins, fn (array $a, array $b): int => (int) $a['position'] <=> (int) $b['position']);
        PaidTaxException::require(count($origins) === (int) $batch['line_count'] && count($origins) === count($payload['line_commitments']));
        $lines = [];
        foreach ($origins as $index => $origin) {
            $body = PaidTaxRecords::decode($origin);
            $source = $body['source'];
            PaidTaxException::require($body['schema_version'] === 'paid-tax-grant-origin-v2' && $body['purpose'] === 'paid-tax-license-grant'
                && $body['origin_id'] === $origin['public_id'] && $body['batch_id'] === $id && $body['position'] === $index + 1
                && (int) $origin['position'] === $index + 1 && $source['order_id'] === $payload['order_id'] && $source['order_hash'] === $payload['order_hash']
                && $this->sourceIdentity($source) === $payload['source_identity']
                && $source['line_id'] === $origin['line_public_id'] && $source['line_hash'] === $origin['line_hash'] && $source['source_hash'] === $origin['source_hash']
                && $source['buyer'] === $payload['original_buyer'] && $body['delivery_policy'] === $payload['delivery_policy']
                && $payload['line_commitments'][$index] === array_intersect_key($source, array_flip(['line_id', 'line_hash', 'source_hash'])));
            (new PaidTaxPolicy)->source($source, $payload['delivery_policy']);
            $work = $rows->one('paid_tax_document_work', 'origin_id = ?', [(int) $origin['id']]);
            $original = $rows->one('paid_tax_originals', 'origin_id = ?', [(int) $origin['id']]);
            PaidTaxException::require($work !== [] && in_array($work['state'], ['pending', 'claimed', 'failed', 'complete'], true)
                && (int) $work['attempts'] >= 0 && (int) $work['attempts'] <= 5
                && ($work['state'] === 'complete') === ($original !== []));
            $manifest = $original === [] ? null : PaidTaxRecords::decode($original);
            if ($manifest !== null) {
                $text = (new PaidTaxText)->build(PaidTaxRenderInput::fromOrigin($body));
                PaidTaxException::require($manifest['schema_version'] === 'paid-tax-first-original-v2' && $manifest['origin_hash'] === $origin['payload_hash']
                    && $original['claim_id'] === $work['claim_id'] && $manifest['claim_id'] === $work['claim_id']
                    && $manifest['profile_hash'] === CanonicalJson::hash($body['profile'])
                    && $manifest['input_hash'] === $text['input_hash'] && $manifest['text_digest'] === $text['text_digest']
                    && $manifest['asset_manifest_hash'] === CanonicalJson::hash($body['assets'])
                    && $manifest['artifact']['profile_hash'] === $manifest['profile_hash']
                    && $manifest['artifact']['purpose'] === 'paid-tax-license-grant' && $manifest['artifact']['provenance'] === $source['provenance']
                    && $manifest['artifact']['storage_path'] === 'contracts/paid-tax-v2/'.$source['provenance'].'/'.$origin['public_id'].'/'.$work['claim_id'].'/original.pdf');
            }
            $lines[] = compact('origin', 'body', 'work', 'original', 'manifest');
        }
        $fulfillment = $rows->one('paid_tax_fulfillments', 'batch_id = ?', [(int) $batch['id']]);
        $complete = $fulfillment === [] ? null : PaidTaxRecords::decode($fulfillment);
        if ($complete !== null) {
            PaidTaxException::require($complete['schema_version'] === 'paid-tax-complete-order-v2' && $complete['batch_hash'] === $batch['payload_hash']
                && $complete['origin_hashes'] === array_column(array_column($lines, 'origin'), 'payload_hash')
                && $complete['original_hashes'] === array_column(array_column($lines, 'original'), 'payload_hash')
                && $complete['asset_manifest_hashes'] === array_map(fn (array $line): string => CanonicalJson::hash($line['body']['assets']), $lines)
                && $complete['physical_observation'] === 'exact_private_bytes_all_original_lines'
                && count(array_filter($lines, fn (array $line): bool => $line['work']['state'] === 'complete')) === count($lines));
        }

        return compact('batch', 'payload', 'lines', 'fulfillment', 'complete');
    }

    public function fence(ProductionCustomerPrincipal $principal, User $actor, ProductionCustomerAccess $access, array $authority,
        ProductionTaxPaidOrderSourceV2 $source, array $policy, array $graph, PaidTaxRows $rows, PaidTaxReadReceipt $receipt): void
    {
        // All resolver/decrypt/helper/model callbacks run before the final raw graph, producer and current buyer fences.
        app(PaidTaxPolicy::class);
        app(ProductionCustomerAccess::class);
        $rows->callbackPhase();
        $receipt->proveActor();
        PaidTaxException::require($this->graph($graph['batch']['public_id'], (int) $graph['batch']['account_id'], $rows) === $graph, 409);
        foreach ($graph['lines'] as $line) {
            (new PaidTaxAssets)->proveRetained($line['body']['assets'], $rows);
        }
        $receipt->proveLive();
        // Producer reproof does not resolve consumer authority; its public line extraction already occurred above.
        $receipt->proveActor();
        $access->proveCurrent($principal, $actor, $rows->current(), $authority);
        $receipt->proveActor();
        $source->proveRetainedCurrent($rows->current());
        PaidTaxPolicy::provePure($policy, $rows->configuration, $rows->environment);
        $rows->finish();
    }

    public function snapshots(array $graph, PaidTaxRows $rows): array
    {
        $result = [['paid_tax_order_origins', 'id = ?', [$graph['batch']['id']], 2, [$graph['batch']]],
            ['paid_tax_grant_origins', 'batch_id = ?', [$graph['batch']['id']], 11, $rows->rows('paid_tax_grant_origins', 'batch_id = ?', [$graph['batch']['id']], 11)],
            ['paid_tax_fulfillments', 'batch_id = ?', [$graph['batch']['id']], 2, $graph['fulfillment'] === [] ? [] : [$graph['fulfillment']]]];
        foreach ($graph['lines'] as $line) {
            $result[] = ['paid_tax_document_work', 'origin_id = ?', [$line['origin']['id']], 2, [$line['work']]];
            $result[] = ['paid_tax_originals', 'origin_id = ?', [$line['origin']['id']], 2, $line['original'] === [] ? [] : [$line['original']]];
            $result = [...$result, ...(new PaidTaxAssets)->snapshots($line['body']['assets'], $rows)];
        }

        return $result;
    }

    public function sameOwner(array $current, array $original): void
    {
        foreach (['origin_id', 'provenance', 'account_id', 'account_public_id', 'user_id', 'identity_policy_version', 'identity_policy_hash'] as $key) {
            PaidTaxException::require(isset($current[$key], $original[$key]) && $current[$key] === $original[$key], 404);
        }
    }

    private function sameSources(array $graph, array $sources, array $policy, array $original): void
    {
        PaidTaxException::require(CanonicalJson::encode($graph['payload']['original_buyer']) === CanonicalJson::encode($original)
            && CanonicalJson::encode(array_column(array_column($graph['lines'], 'body'), 'source')) === CanonicalJson::encode($sources), 409);
        PaidTaxPolicy::retained($graph['payload']['delivery_policy'], $policy);
    }

    public function project(array $graph): array
    {
        $value = ['id' => $graph['batch']['public_id'], 'orderId' => $graph['payload']['order_id'], 'purpose' => 'paid-tax-license-grant', 'schemaVersion' => 2,
            'provenance' => $graph['payload']['provenance'], 'fulfilled' => $graph['complete'] !== null,
            'lines' => array_map(fn (array $line): array => ['id' => $line['origin']['public_id'], 'originHash' => $line['origin']['payload_hash'], 'position' => (int) $line['origin']['position'],
                'title' => $line['body']['source']['product']['title'], 'license' => $line['body']['disclosure'],
                'declaredName' => $line['body']['source']['buyer_declarations']['legal_name'], 'assentedAt' => $line['body']['source']['assent']['accepted_at'],
                'preTax' => $line['body']['source']['pre_tax'], 'tax' => $line['body']['source']['tax'],
                'documentStatus' => $line['work']['state'], 'attempts' => (int) $line['work']['attempts'],
                'files' => $graph['complete'] === null ? [] : [['kind' => 'contract', 'sha256' => $line['manifest']['artifact']['pdf_hash'], 'sizeBytes' => $line['manifest']['artifact']['size_bytes']],
                    ...array_map(fn (array $file): array => ['kind' => $file['role'], 'sha256' => $file['sha256'], 'sizeBytes' => $file['size_bytes']], $line['body']['assets']['files'])]], $graph['lines'])];

        return PaidTaxProjection::fromTrusted($value)->toArray();
    }

    private function sourceIdentity(array $source): array
    {
        $keys = ['producer', 'order_id', 'order_hash', 'request_id', 'request_hash', 'reviewed_session_id', 'reviewed_session_hash',
            'provider_account', 'funds_mode', 'provider_session_id', 'provider_payment_id'];
        $identity = [];
        foreach ($keys as $key) {
            PaidTaxException::require(is_string($source[$key] ?? null) && $source[$key] !== '', 409);
            $identity[$key] = $source[$key];
        }
        ksort($identity, SORT_STRING);

        return $identity;
    }

    private function batchIdentity(array $batch): array
    {
        return $this->sourceIdentity(['order_id' => $batch['order_public_id'], 'request_id' => $batch['request_public_id'],
            'reviewed_session_id' => $batch['reviewed_session_public_id'], ...$batch]);
    }
}
