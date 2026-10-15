<?php

namespace App\Domain\Commerce\ProductionTaxCheckout;

use App\Domain\Commerce\ProductionCheckout\CheckoutCommandCommitDispatcher;
use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\OriginalCommitDispatcher;
use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Customers\ProductionIdentity\IdentityHistoricalCommittedReceipt;
use App\Domain\Customers\ProductionIdentity\IdentityOriginalCommitWitness;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\Events\TransactionRolledBack;
use LogicException;
use Throwable;

/** One Tax original-commit observer. A caught held refusal never removes its physical admission fence. */
final class TaxPaidOriginalCommitDispatcher implements \JsonSerializable, Dispatcher
{
    private string $phase = 'held';

    private array $receipts = [];

    private bool $dispatchingCommit = false;

    private function __construct(
        private readonly Dispatcher $delegate,
        private readonly ProductionTaxPaidOrderSourceV2 $source,
        private readonly TaxPaidReadContext $context,
        private readonly CurrentRows $reader,
        private readonly int $deadlineNs,
        private readonly IdentityOriginalCommitWitness $witness,
        private readonly ?ProductionTaxPaidOrderConsumerCommitAdmissionV2 $admission,
    ) {}

    public static function capture(ProductionTaxPaidOrderSourceV2 $source, TaxPaidReadContext $context,
        CurrentRows $reader, int $deadlineNs, ?ProductionTaxPaidOrderConsumerCommitAdmissionV2 $admission): self
    {
        $delegate = $context->connection()->getEventDispatcher();
        if ($delegate instanceof self) {
            // Validate harmless rejected siblings BEFORE touching the installed fence or either valid receipt.
            CheckoutException::require($delegate->phase === 'held' && count($delegate->receipts) < 2
                && $delegate->source === $source && $delegate->context === $context && $delegate->reader === $reader
                && $delegate->deadlineNs === $deadlineNs && $delegate->admission === $admission, 'committed_read_frame');

            return $delegate;
        }
        CheckoutException::require($delegate instanceof Dispatcher && ! $delegate instanceof OriginalCommitDispatcher
            && ! $delegate instanceof CheckoutCommandCommitDispatcher, 'committed_read_frame');
        $context->proveHeld();
        $observer = new self($delegate, $source, $context, $reader, $deadlineNs,
            IdentityOriginalCommitWitness::capture($reader, $deadlineNs), $admission);
        $context->bindObserver($observer);
        $context->connection()->setEventDispatcher($observer);

        return $observer;
    }

    public function mint(): ProductionTaxPaidOrderCommittedReadReceiptV2
    {
        CheckoutException::require($this->phase === 'held' && count($this->receipts) < 2, 'committed_read_frame');
        $this->requireCurrent(1);
        try {
            $identity = $this->context->held(fn (): IdentityHistoricalCommittedReceipt => $this->source->historicalCommittedReceipt(
                $this->reader, $this->deadlineNs, $this->witness));
            $receipt = ProductionTaxPaidOrderCommittedReadReceiptV2::fromObserver($this, $identity);
            $this->receipts[] = $receipt;

            return $receipt;
        } catch (Throwable $error) {
            $this->poison();
            throw $error;
        }
    }

    public function ownsIdentity(IdentityHistoricalCommittedReceipt $identity): bool
    {
        return $identity->belongsTo($this->witness, $this->reader, $this->deadlineNs);
    }

    public function requireClosed(): void
    {
        $this->requireCurrent(0);
        CheckoutException::require($this->phase === 'committed', 'committed_read_frame');
    }

    public function proveOriginalClosed(): void
    {
        $this->requireClosed();
        $this->source->proveReceiptRows($this->context, 0);
        $this->requireClosed();
    }

    public function closedIdentity(callable $operation): void
    {
        $this->context->closed($operation(...));
    }

    public function consumed(): void
    {
        if (count(array_filter($this->receipts, fn ($receipt): bool => ! $receipt->used())) === 0) {
            $this->releaseOwned();
        }
    }

    public function poison(): void
    {
        $this->phase = 'invalid';
        $this->witness->invalidate();
        foreach ($this->receipts as $receipt) {
            $receipt->invalidateIdentity();
        }
        // Keep a poisoned fence while physically held, including depth0 after a committing exception.
        if (! $this->context->primary()->inTransaction()) {
            $this->releaseOwned();
        }
    }

    public function releaseOwned(): void
    {
        if ($this->context->connection()->getEventDispatcher() === $this) {
            $this->context->connection()->setEventDispatcher($this->delegate);
        }
    }

    public function abortOwnedFrame(): void
    {
        $this->poison();
        $this->context->abortOwnedFrame();
        $this->releaseOwned();
    }

    private function requireCurrent(int $depth): void
    {
        $this->proveContextOwner($depth);
        $this->context->prove($depth);
    }

    /** Pure nonrecursive owner admission, also used by the caller's terminal held Source fence. */
    public function proveContextOwner(int $depth): void
    {
        CheckoutException::require(hrtime(true) < $this->deadlineNs
            && $this->context->connection()->getEventDispatcher() === $this
            && in_array($this->phase, $depth === 1 ? ['held', 'prepared'] : ['prepared', 'committed'], true), 'committed_read_frame');
    }

    public function dispatch($event, $payload = [], $halt = false)
    {
        $owned = is_object($event) && property_exists($event, 'connection') && $event->connection === $this->context->connection();
        $committing = $owned && $event instanceof TransactionCommitting;
        $committed = $owned && $event instanceof TransactionCommitted;
        if (($committing || $committed) && $this->dispatchingCommit) {
            $this->poison();
            throw new CheckoutException('committed_read_frame');
        }
        if ($owned && ($event instanceof TransactionBeginning || $event instanceof TransactionRolledBack)) {
            $this->poison();
        }
        try {
            if ($committing || $committed) {
                $this->requireCurrent($committing ? 1 : 0);
                CheckoutException::require($this->phase === ($committing ? 'held' : 'prepared'), 'committed_read_frame');
                $this->dispatchingCommit = true;
            }
            $response = $this->delegate->dispatch($event, $payload, $halt);
            if ($committing) {
                $this->requireCurrent(1);
                $this->context->held(function (): void {
                    $this->admission?->proveCurrent($this->context->primary());
                    $this->source->proveRetainedCurrent($this->reader);
                    foreach ($this->receipts as $receipt) {
                        $receipt->sealOriginalCommit();
                    }
                });
                // The same captured PURE consumer capsule closes NEW eligibility after the last source/
                // historical decryption callback. The historical read alone deliberately ignores current access.
                $this->admission?->proveCurrent($this->context->primary());
                // Last callbacks are above. Only captured native configuration/raw/identity/anchor state follows.
                $this->requireCurrent(1);
                $this->source->proveReceiptRows($this->context, 1);
                $this->witness->assertHeld();
                $this->context->sealTerminal();
                $this->witness->prepareOriginalCommit();
                $this->requireCurrent(1);
                $this->phase = 'prepared';
            } elseif ($committed) {
                // Managed afterCommit already ran; ordinary committed delegates just ran too.
                $this->requireCurrent(0);
                $this->source->proveReceiptRows($this->context, 0);
                $this->context->closed(fn () => $this->witness->observeOriginalPositiveCommit());
                foreach ($this->receipts as $receipt) {
                    $this->context->closed(fn () => $receipt->observeOriginalCommitted());
                }
                $this->requireCurrent(0);
                $this->phase = 'committed';
            }

            return $response;
        } catch (Throwable $error) {
            if ($committing || $committed) {
                $this->poison();
            }
            throw $error;
        } finally {
            if ($committing || $committed) {
                $this->dispatchingCommit = false;
            }
        }
    }

    public function listen($events, $listener = null)
    {
        $this->delegate->listen($events, $listener);
    }

    public function hasListeners($eventName)
    {
        return $this->delegate->hasListeners($eventName);
    }

    public function subscribe($subscriber)
    {
        return $this->delegate->subscribe($subscriber);
    }

    public function until($event, $payload = [])
    {
        return $this->dispatch($event, $payload, true);
    }

    public function push($event, $payload = [])
    {
        $this->delegate->push($event, $payload);
    }

    public function flush($event)
    {
        $this->delegate->flush($event);
    }

    public function forget($event)
    {
        $this->delegate->forget($event);
    }

    public function forgetPushed()
    {
        $this->delegate->forgetPushed();
    }

    private function __clone() {}

    public function __serialize(): never
    {
        throw new LogicException('Tax commit observers cannot be serialized.');
    }

    public function __unserialize(array $data): never
    {
        throw new LogicException('Tax commit observers cannot be deserialized.');
    }

    public function jsonSerialize(): never
    {
        throw new LogicException('Tax commit observers are internal server objects.');
    }

    public function __debugInfo(): array
    {
        return ['authority' => 'original_tax_commit_observer'];
    }
}
