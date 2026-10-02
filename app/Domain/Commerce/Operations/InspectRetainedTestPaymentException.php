<?php

namespace App\Domain\Commerce\Operations;

use App\Domain\Commerce\Finalization\FinalizationException;
use App\Domain\Commerce\Finalization\ReadFinalization;
use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\Orders\OrderRequest;
use App\Domain\Commerce\Orders\ReadOrder;
use App\Domain\Commerce\QuoteException;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\Audit\AuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Throwable;

/** Audited historical test evidence only; no financial, resource or fulfillment transition. */
final class InspectRetainedTestPaymentException
{
    public function handle(string $publicId, ?User $actor): array
    {
        try {
            foreach (DB::getConnections() as $connection) {
                if ($connection->transactionLevel() !== 0) {
                    throw new RuntimeException('Test exception inspection is unavailable.');
                }
            }

            return DB::transaction(function () use ($publicId, $actor): array {
                // Serialize this disclosure with persisted authority withdrawal, not stale session attributes.
                $current = $actor?->exists ? User::whereKey($actor->getKey())->lockForUpdate()->first() : null;
                if ($current === null || ! Gate::forUser($current)->allows('administer-catalog')
                    || ! AdminMultiFactor::satisfiedBy($current)) {
                    throw new AuthorizationException;
                }
                $account = config('payments.stripe.account_id');
                if (! OrderRequest::uuid($publicId) || ! is_string($account)
                    || preg_match('/\Aacct_[A-Za-z0-9]{1,64}\z/D', $account) !== 1) {
                    throw (new ModelNotFoundException)->setModel(OrderFinalization::class);
                }
                $record = OrderFinalization::where('public_id', $publicId)->where('mode', 'test')->where('outcome', 'paid_exception')
                    ->whereHas('payment', fn ($query) => $query->where('mode', 'test')->where('account_id', $account))
                    ->first();
                if ($record === null || ! hash_equals($publicId, $record->public_id)
                    || ! hash_equals($account, $record->payment->account_id)) {
                    throw (new ModelNotFoundException)->setModel(OrderFinalization::class);
                }

                // No guessed diagnosis is exposed when the complete retained graph cannot be reconstructed.
                $detail = ['testOnly' => true, 'inspectionStatus' => 'attention', 'finalizationId' => $publicId,
                    'orderId' => null, 'recordedReason' => null, 'confirmedAt' => null, 'eligibilityCutoff' => null,
                    'finalizedAt' => null, 'inventoryState' => null, 'promotionState' => null,
                    'grantCount' => null, 'exclusiveSaleCount' => null, 'outboxCount' => null,
                    'currentProviderState' => 'not_inspected'];
                try {
                    $original = app(ReadOrder::class)->verify($record->order);
                    app(ReadFinalization::class)->verify($record, $original);
                    // ReadOrder verifies the one immutable finalization and every retained terminal effect.
                    if ($record->order->attempt()->sole()->id !== $record->order_attempt_id
                        || $record->payment->order_id !== $record->order_id
                        || $record->payment->order_attempt_id !== $record->order_attempt_id
                        || ! array_key_exists($record->reason, ReadTestCommerceOperations::EXCEPTION_REASONS)) {
                        throw new QuoteException('ORDER_CHANGED', 409);
                    }
                    $detail = array_replace($detail, ['inspectionStatus' => 'verified',
                        'orderId' => $record->order->public_id, 'recordedReason' => $record->reason,
                        'confirmedAt' => $record->confirmed_at->toIso8601ZuluString(),
                        'eligibilityCutoff' => $record->eligibility_cutoff->toIso8601ZuluString(),
                        'finalizedAt' => $record->finalized_at->toIso8601ZuluString(), 'inventoryState' => 'pending',
                        'promotionState' => $original['attempt']['promotion'] === null ? 'none' : 'pending',
                        'grantCount' => 0, 'exclusiveSaleCount' => 0, 'outboxCount' => 1]);
                } catch (QuoteException|FinalizationException) {
                    // Distinguish an unverified graph from an unknown/foreign record, without private diagnostics.
                }
                // Failure to append this audit rolls back the transaction and prevents disclosure.
                AuditEvent::record('commerce.payment_exception.inspected', $record,
                    ['finalization_id' => $publicId, 'result' => $detail['inspectionStatus'], 'test_only' => true], $current->id);

                return $detail;
            }, 5);
        } catch (AuthorizationException|ModelNotFoundException $error) {
            throw $error;
        } catch (Throwable) {
            // Database/transport exceptions can retain private evidence in their bindings or previous causes.
            throw new RuntimeException('Test exception inspection is unavailable.');
        }
    }
}
