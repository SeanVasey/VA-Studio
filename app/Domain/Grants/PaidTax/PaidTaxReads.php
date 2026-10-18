<?php

namespace App\Domain\Grants\PaidTax;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Models\User;
use PDO;
use Throwable;

/** Identifier-only projection using the actual retained Tax source and consumer closure. */
final class PaidTaxReads
{
    /** Newest retained batches shown; one further sentinel row only reports that older batches exist. */
    public const INDEX_LIMIT = 20;

    private const BATCH_KEYS = ['created_at', 'delivery_policy', 'line_commitments', 'order_hash', 'order_id', 'origin_id',
        'original_buyer', 'producer', 'provenance', 'purpose', 'schema_version', 'source_identity'];

    public function show(string $batch, ProductionCustomerPrincipal $principal, User $actor, ?PaidTaxProjectionRead $read = null): array
    {
        return (new PaidTaxCommands)->run($batch, $principal, $actor,
            fn (array $graph): array => (new PaidTaxGrants)->project($graph), projectionRead: $read);
    }

    /**
     * Bounded locator history from the consumer's own retained Tax batches.
     *
     * Its closure is the current owner and the exact list window, never a producer source: one original consumer frame
     * locks the current buyer, reads at most INDEX_LIMIT + 1 of that account's paid_tax_order_origins rows, decrypts and
     * cross-checks every fetched row (sentinel included) against its columns, the current durable owner binding and the
     * current retained policy, then proves owner, window and policy again before commit, after commit and (through the
     * owner-only projection read) immediately before bytes. Output is locators and display-safe fields only. A listed id
     * confers nothing: show/document/status/authorize/redeem each re-run the full producer SourceV2 closure.
     */
    public function index(ProductionCustomerPrincipal $principal, User $actor, ?PaidTaxProjectionRead $read = null): array
    {
        $deadline = PaidTaxDeadline::start();
        $deadline->proveCurrent();
        $grants = new PaidTaxGrants;
        $runtime = PaidTaxRuntime::capture($deadline);
        $runtime->outsideTransactions();
        $actorOriginal = PaidTaxActor::capture($actor);
        $heldRows = null;
        $heldFrame = null;
        $receipt = null;
        try {
            app(PaidTaxPolicy::class)->capture();
            app(ProductionCustomerAccess::class)->current($principal, $actor);
            $deadline->proveCurrent();
            $runtime->prove(0);
            $result = PaidTaxTransaction::run($runtime, $actorOriginal, function (PaidTaxOwnedFrame $frame) use ($principal, $actor, $grants,
                $deadline, $runtime, $actorOriginal, &$heldRows, &$receipt): array {
                $rows = new PaidTaxRows($runtime, $frame);
                $heldRows = $rows;
                $policy = app(PaidTaxPolicy::class)->capture();
                $access = app(ProductionCustomerAccess::class);
                $actorOriginal->prove();
                $authority = $access->lock($principal, $actor, $rows->current());
                $deadline->proveCurrent();
                $binding = $access->durableBinding($principal);
                // No younger producer source exists here. Admitting the consumer reader once keeps the original
                // cleanup marker and finish() contract identical to the producer-backed commands.
                $rows->admitSource();
                $limit = self::INDEX_LIMIT + 1;
                $query = $rows->primary->prepare('SELECT * FROM '.$rows->table('paid_tax_order_origins').' WHERE account_id = ? ORDER BY id DESC LIMIT '.$limit
                    .($rows->primary->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
                $query->execute([$principal->accountId]);
                $records = $query->fetchAll(PDO::FETCH_ASSOC);
                PaidTaxException::require(count($records) <= $limit);
                $origins = [];
                foreach ($records as $record) {
                    $origins[] = $this->locator($record, $principal, $binding, $policy, $grants);
                }
                $deadline->proveCurrent();
                $minimum = $records === [] ? 0 : min(array_map(fn (array $record): int => (int) $record['id'], $records));
                // Every row at or above the oldest fetched id, with room to observe one more: an insert, removal or
                // change anywhere in the shown window or its sentinel refuses the terminal proofs.
                $window = ['paid_tax_order_origins', 'account_id = ? AND id >= ?', [$principal->accountId, $minimum], $limit + 1, array_reverse($records)];
                $receipt = PaidTaxReadReceipt::capture($rows, $principal, $actor, $actorOriginal, $authority, $policy, [$window]);
                // All injectable resolutions precede the final raw owner, window and policy comparisons.
                app(PaidTaxPolicy::class);
                app(ProductionCustomerAccess::class);
                $rows->callbackPhase();
                $receipt->proveActor();
                $receipt->proveLive();
                $receipt->proveActor();
                $access->proveCurrent($principal, $actor, $rows->current(), $authority);
                $receipt->proveActor();
                PaidTaxPolicy::provePure($policy, $rows->configuration, $rows->environment);
                $rows->finish();

                return ['schemaVersion' => 2, 'originLimit' => self::INDEX_LIMIT, 'hasMore' => count($origins) > self::INDEX_LIMIT,
                    'origins' => array_slice($origins, 0, self::INDEX_LIMIT)];
            }, $heldFrame);
            PaidTaxException::require($receipt instanceof PaidTaxReadReceipt);
            $read?->captureIndex($receipt, $deadline);
            $receipt->proveClosed();
            $receipt->proveRawClosed();
            $deadline->proveCurrent();

            return $result;
        } catch (IdentityException) {
            PaidTaxCleanup::abort(null, $heldRows, $heldFrame);
            throw new PaidTaxException(403);
        } catch (CheckoutException $error) {
            PaidTaxCleanup::abort(null, $heldRows, $heldFrame);
            throw new PaidTaxException(in_array($error->status, [403, 404, 409, 422, 503], true) ? $error->status : 503);
        } catch (Throwable $error) {
            PaidTaxCleanup::abort(null, $heldRows, $heldFrame);
            throw $error;
        }
    }

    /** One retained batch row as a display-safe locator; any disagreement with its sealed payload refuses the list. */
    private function locator(array $record, ProductionCustomerPrincipal $principal, array $binding, array $policy, PaidTaxGrants $grants): array
    {
        $payload = PaidTaxRecords::decode($record);
        $keys = array_keys($payload);
        sort($keys);
        PaidTaxException::require($keys === self::BATCH_KEYS, 409);
        $uuid = '/\A[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\z/D';
        $buyer = $payload['original_buyer'];
        $commitments = $payload['line_commitments'];
        $lineCount = is_int($record['line_count']) || (is_string($record['line_count']) && ctype_digit($record['line_count'])) ? (int) $record['line_count'] : 0;
        PaidTaxException::require(is_string($record['public_id']) && preg_match($uuid, $record['public_id']) === 1
            && is_string($record['order_public_id']) && preg_match($uuid, $record['order_public_id']) === 1
            && (int) $record['account_id'] === $principal->accountId
            && $record['producer'] === 'production_tax_checkout_v2' && $payload['producer'] === $record['producer']
            && $payload['schema_version'] === 'paid-tax-order-origin-v2' && $payload['purpose'] === 'paid-tax-license-grant'
            && $payload['origin_id'] === $record['public_id'] && $payload['order_id'] === $record['order_public_id']
            && $payload['order_hash'] === $record['order_hash'] && $payload['source_identity'] === $grants->batchIdentity($record)
            && is_array($buyer) && ($buyer['account_id'] ?? null) === $principal->accountId && ($buyer['user_id'] ?? null) === (int) $record['actor_id']
            && $payload['provenance'] === ($buyer['provenance'] ?? null) && $payload['provenance'] === $policy['provenance']
            && is_array($commitments) && array_is_list($commitments) && $lineCount >= 1 && $lineCount <= 10 && count($commitments) === $lineCount
            && is_string($payload['created_at']) && preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $payload['created_at']) === 1
            && is_string($record['created_at']) && str_replace(' ', 'T', $record['created_at']).'Z' === $payload['created_at'], 409);
        foreach ($commitments as $commitment) {
            PaidTaxException::require(is_array($commitment) && count($commitment) === 3
                && is_string($commitment['line_id'] ?? null) && is_string($commitment['line_hash'] ?? null) && is_string($commitment['source_hash'] ?? null), 409);
        }
        // Exactly the owner closure used by show(): the retained original buyer must be the current durable binding.
        $grants->sameOwner($binding, $buyer);
        PaidTaxPolicy::retained($payload['delivery_policy'], $policy);

        return ['id' => $record['public_id'], 'orderId' => $record['order_public_id'], 'createdAt' => $payload['created_at'],
            'lineCount' => $lineCount, 'provenance' => $payload['provenance']];
    }
}
