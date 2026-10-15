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
use App\Models\User;
use App\Support\CanonicalJson;
use Closure;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

/** Trusted owned commands share one current-buyer -> original-buyer -> producer -> owned-graph lock order. */
final class PaidTaxCommands
{
    public function run(string $batchId, ProductionCustomerPrincipal $principal, User $actor, Closure $operation,
        PaidTaxDeadline|int|null $observationDeadline = null, ?Closure $additionalSnapshots = null,
        ?PaidTaxProjectionRead $projectionRead = null): array
    {
        PaidTaxInput::uuid($batchId);
        $deadline = $observationDeadline instanceof PaidTaxDeadline ? $observationDeadline : PaidTaxDeadline::start();
        if (is_int($observationDeadline)) {
            $deadline->shortenTo($observationDeadline);
        }
        $deadline->proveCurrent();
        $grants = new PaidTaxGrants;
        $runtime = PaidTaxRuntime::capture($deadline);
        $runtime->outsideTransactions();
        $actorOriginal = PaidTaxActor::capture($actor);
        $heldRows = null;
        $receipt = null;
        $producerRead = null;
        $producerSecond = null;
        $heldSource = null;
        try {
            app(PaidTaxPolicy::class)->capture();
            app(ProductionCustomerAccess::class)->current($principal, $actor);
            $deadline->proveCurrent();
            $runtime->prove(0);
            $orderId = $this->locate($batchId, $principal->accountId, $runtime);
            $deadline->proveCurrent();
            $locator = ProductionTaxPaidOrderLocatorV2::locate($orderId);
            $deadline->proveCurrent();
            $runtime->prove(0);
            $result = $runtime->connection->transaction(function () use ($batchId, $principal, $actor, $operation, $locator, $grants, $additionalSnapshots,
                $deadline, $projectionRead, $runtime, $actorOriginal, &$heldSource, &$heldRows, &$receipt, &$producerRead, &$producerSecond): array {
                $rows = new PaidTaxRows($runtime);
                $heldRows = $rows;
                $policy = app(PaidTaxPolicy::class)->capture();
                $access = app(ProductionCustomerAccess::class);
                $actorOriginal->prove();
                $authority = $access->lock($principal, $actor, $rows->current());
                $deadline->proveCurrent();
                $binding = $access->durableBinding($principal);
                $original = $locator->historicalBuyerBinding();
                $grants->sameOwner($binding, $original);
                $historical = $access->verifyHistoricalBinding($original, $rows->current());
                $deadline->proveCurrent();
                $reader = $rows->admitSource();
                $source = ProductionTaxPaidOrderSourceV2::lockedRead($locator, $reader, $historical);
                $heldSource = $source;
                $sources = [];
                for ($position = 1; $position <= $source->lineCount(); $position++) {
                    $sources[] = ProductionTaxPaidLineAdapterV2::accept($source, $position, $source->line($position), $policy['provenance'], $reader, $locator->orderId);
                }
                $graph = $grants->graph($batchId, $binding['account_id'], $rows);
                PaidTaxException::require(CanonicalJson::encode($graph['payload']['original_buyer']) === CanonicalJson::encode($original)
                    && CanonicalJson::encode(array_column(array_column($graph['lines'], 'body'), 'source')) === CanonicalJson::encode($sources), 409);
                PaidTaxPolicy::retained($graph['payload']['delivery_policy'], $policy);
                $deadline->proveCurrent();
                $result = $operation($graph, $rows);
                PaidTaxException::require(is_array($result));
                $expected = $grants->graph($batchId, $binding['account_id'], $rows);
                $snapshots = $additionalSnapshots === null ? [] : $additionalSnapshots($expected, $rows);
                PaidTaxException::require(is_array($snapshots) && array_is_list($snapshots));
                $receipt = PaidTaxReadReceipt::capture($rows, $principal, $actor, $actorOriginal, $authority, $policy, [...$grants->snapshots($expected, $rows), ...$snapshots]);
                $admission = PaidTaxConsumerCommitAdmission::capture($receipt, $deadline);
                $producerRead = $source->committedReadReceipt($reader, $deadline->value(), $admission);
                if ($projectionRead !== null) {
                    $producerSecond = $source->committedReadReceipt($reader, $deadline->value(), $admission);
                }
                $grants->fence($principal, $actor, $access, $authority, $source, $policy, $expected, $rows, $receipt);

                return $result;
            });
            PaidTaxException::require($receipt instanceof PaidTaxReadReceipt && $producerRead instanceof ProductionTaxPaidOrderCommittedReadReceiptV2
                && ($projectionRead === null || $producerSecond instanceof ProductionTaxPaidOrderCommittedReadReceiptV2));
            // Trusted transfer construction occurs before the terminal post-commit raw proof.
            $projectionRead?->capture($receipt, $producerSecond, $deadline);
            $receipt->proveClosed();
            $producerRead->proveClosed();
            $receipt->proveRawClosed();
            $deadline->proveCurrent();

            return $result;
        } catch (IdentityException) {
            $heldSource?->abortOwnedFrame();
            $heldRows?->abort();
            throw new PaidTaxException(403);
        } catch (CheckoutException $error) {
            $heldSource?->abortOwnedFrame();
            $heldRows?->abort();
            throw new PaidTaxException(in_array($error->status, [403, 404, 409, 422, 503], true) ? $error->status : 503);
        } catch (Throwable $error) {
            $heldSource?->abortOwnedFrame();
            $heldRows?->abort();
            throw $error;
        }
    }

    /** Private metadata locator only; current authority was independently proved before this read. */
    private function locate(string $id, int $accountId, PaidTaxRuntime $runtime): string
    {
        $runtime->prove(0);
        $connection = $runtime->connection;
        $pdo = $runtime->primary;
        $driver = $runtime->driver;
        PaidTaxException::require($connection->transactionLevel() === 0 && ! $pdo->inTransaction() && $connection->getTablePrefix() === '');
        if ($driver === 'sqlite') {
            $temporary = $pdo->query("SELECT 1 FROM sqlite_temp_master WHERE name = 'paid_tax_order_origins' COLLATE NOCASE LIMIT 1")->fetchColumn();
            PaidTaxException::require($temporary === false);
            $table = 'main."paid_tax_order_origins"';
        } else {
            PaidTaxException::require($driver === 'mysql');
            $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
            PaidTaxException::require(is_string($database) && preg_match('/\A[a-zA-Z0-9_]+\z/D', $database) === 1);
            $table = '`'.$database.'`.`paid_tax_order_origins`';
            $definition = $pdo->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM);
            PaidTaxException::require(is_array($definition) && str_starts_with((string) $definition[1], 'CREATE TABLE '));
        }
        $query = $pdo->prepare('SELECT order_public_id FROM '.$table.' WHERE public_id = ? AND account_id = ? LIMIT 2');
        $query->execute([$id, $accountId]);
        $orders = $query->fetchAll(PDO::FETCH_COLUMN);
        PaidTaxException::require(count($orders) === 1, 404);
        PaidTaxException::require(DB::connection() === $connection && $connection->getRawPdo() === $pdo
            && $connection->transactionLevel() === 0 && ! $pdo->inTransaction() && $connection->getTablePrefix() === '');

        $runtime->prove(0);

        return PaidTaxInput::uuid($orders[0]);
    }
}
