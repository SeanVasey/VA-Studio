<?php

namespace App\Domain\Grants\Paid;

use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

/** Bounded origin locators; full private license projection uses its exact retained paid source. */
final class PaidGrantReads
{
    public const LIMIT = 20;

    public function index(ProductionCustomerPrincipal $principal, User $actor, ?PaidGrantProjectionRead $projectionRead = null): array
    {
        (new PaidGrants)->outsideTransactions();
        $held = null;
        $receipt = null;
        $deadline = PaidGrantDeadline::start();
        try {
            $result = DB::transaction(function () use ($principal, $actor, &$held, &$receipt): array {
                $rows = new PaidGrantRows;
                $held = $rows;
                $policy = app(PaidGrantPolicy::class)->capture();
                $access = app(ProductionCustomerAccess::class);
                $authority = $access->lock($principal, $actor, $rows->current());
                $sql = 'SELECT * FROM '.$rows->table('paid_order_origins').' WHERE account_id = ? ORDER BY id DESC LIMIT '.self::LIMIT
                    .($rows->primary->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '');
                $query = $rows->primary->prepare($sql);
                $query->execute([$principal->accountId]);
                $records = $query->fetchAll(PDO::FETCH_ASSOC);
                $origins = [];
                foreach ($records as $record) {
                    $body = PaidGrantRecords::decode($record);
                    PaidGrantException::require($body['schema_version'] === 'paid-order-origin-v1' && $body['purpose'] === 'paid-license-grant'
                        && $body['origin_id'] === $record['public_id'] && $body['order_id'] === $record['order_public_id']
                        && $body['original_buyer']['account_id'] === $principal->accountId && $body['provenance'] === $policy['provenance'], 409);
                    $origins[] = ['id' => $record['public_id'], 'orderId' => $record['order_public_id'],
                        'createdAt' => $record['created_at'], 'provenance' => $body['provenance']];
                }
                $minimum = $records === [] ? 0 : min(array_column($records, 'id'));
                $maximum = $records === [] ? 0 : max(array_column($records, 'id'));
                $where = 'account_id = ? AND id BETWEEN ? AND ?';
                $bindings = [$principal->accountId, $minimum, $maximum];
                $snapshot = $rows->rows('paid_order_origins', $where, $bindings, self::LIMIT + 1);
                PaidGrantException::require(array_reverse($snapshot) === $records, 409);
                $receipt = PaidGrantReadReceipt::capture($rows, $principal, $actor, $authority, $policy,
                    [['paid_order_origins', $where, $bindings, self::LIMIT + 1, $snapshot]]);
                // All injectable resolutions precede the terminal current owner and list bytes.
                app(PaidGrantPolicy::class);
                app(ProductionCustomerAccess::class);
                $rows->callbackPhase();
                $receipt->proveLive();
                $access->proveCurrent($principal, $actor, $rows->current(), $authority);
                PaidGrantPolicy::provePure($policy, $rows->configuration, $rows->environment);
                $rows->finish();

                return ['schemaVersion' => 1, 'originLimit' => self::LIMIT, 'origins' => $origins];
            });
            PaidGrantException::require($receipt instanceof PaidGrantReadReceipt);
            $projectionRead?->capture($receipt, null, $deadline);
            $receipt->proveClosed();
            $receipt->proveRawClosed();
            $deadline->proveCurrent();

            return $result;
        } catch (IdentityException) {
            $held?->abort();
            throw new PaidGrantException(403);
        } catch (Throwable $error) {
            $held?->abort();
            throw $error;
        }
    }

    public function show(string $id, ProductionCustomerPrincipal $principal, User $actor, ?PaidGrantProjectionRead $projectionRead = null): array
    {
        return (new PaidGrantCommands)->run($id, $principal, $actor,
            fn (array $graph): array => (new PaidGrants)->project($graph), projectionRead: $projectionRead);
    }
}
