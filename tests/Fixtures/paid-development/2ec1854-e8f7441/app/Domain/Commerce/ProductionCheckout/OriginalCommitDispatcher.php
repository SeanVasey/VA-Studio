<?php

namespace App\Domain\Commerce\ProductionCheckout;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Customers\ProductionIdentity\IdentityHistoricalCommittedReceipt;
use App\Domain\Customers\ProductionIdentity\IdentityOriginalCommitWitness;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\Events\TransactionRolledBack;
use Throwable;

/** Connection-local observer: existing callbacks run before the original producer's final commit seal. */
final class OriginalCommitDispatcher implements Dispatcher
{
    private string $phase = 'held';

    private array $receipts = [];

    private function __construct(
        private readonly Dispatcher $delegate,
        private readonly ProductionPaidOrderSourceV1 $source,
        private readonly CurrentRows $reader,
        private readonly CommittedReadContext $context,
        private readonly int $deadlineNs,
        private readonly IdentityOriginalCommitWitness $witness,
        private readonly ?ProductionPaidOrderConsumerCommitAdmissionV1 $admission,
    ) {}

    public static function capture(ProductionPaidOrderSourceV1 $source, CurrentRows $reader, CommittedReadContext $context, int $deadlineNs, ?ProductionPaidOrderConsumerCommitAdmissionV1 $admission = null): self
    {
        CheckoutException::require($source->committedReadContext($reader) === $context, 'committed_read_frame');
        $context->prove($reader, 1);
        $delegate = $context->connection()->getEventDispatcher();
        if ($delegate instanceof self) {
            CheckoutException::require($delegate->phase === 'held' && $delegate->source === $source && $delegate->reader === $reader
                && $delegate->context === $context && $delegate->deadlineNs === $deadlineNs
                && $delegate->admission === $admission, 'committed_read_frame');

            return $delegate;
        }
        CheckoutException::require($delegate instanceof Dispatcher, 'committed_read_frame');
        $observer = new self($delegate, $source, $reader, $context, $deadlineNs,
            IdentityOriginalCommitWitness::capture($reader, $deadlineNs), $admission);
        $context->connection()->setEventDispatcher($observer);

        return $observer;
    }

    public function historicalReceipt(): IdentityHistoricalCommittedReceipt
    {
        $this->requireCurrent(1);
        CheckoutException::require($this->phase === 'held' && count($this->receipts) < 2, 'committed_read_frame');

        return $this->source->historicalCommittedReceipt($this->reader, $this->deadlineNs, $this->witness);
    }

    public function register(ProductionPaidOrderCommittedReadReceiptV1 $receipt): void
    {
        CheckoutException::require($this->phase === 'held' && count($this->receipts) < 2 && $receipt->belongsTo($this->source, $this->reader), 'committed_read_frame');
        $this->receipts[] = $receipt;
    }

    public function matches(ProductionPaidOrderSourceV1 $source, CurrentRows $reader): bool
    {
        return $source === $this->source && $reader->identityPrimary() === $this->reader->identityPrimary()
            && $reader->identityDriver() === $this->reader->identityDriver();
    }

    public function rows(): Records
    {
        $this->requireCurrent(0);
        CheckoutException::require(in_array($this->phase, ['prepared', 'committed'], true), 'committed_read_frame');

        return Records::committed($this->reader);
    }

    public function requireClosed(): void
    {
        $this->requireCurrent(0);
        CheckoutException::require($this->phase === 'committed', 'committed_read_frame');
    }

    public function consumed(): void
    {
        if (count(array_filter($this->receipts, fn ($receipt): bool => ! $receipt->used())) === 0) {
            $this->invalidate();
        }
    }

    private function requireCurrent(int $depth): void
    {
        CheckoutException::require($this->phase !== 'invalid' && hrtime(true) < $this->deadlineNs
            && $this->context->connection()->getEventDispatcher() === $this, 'committed_read_frame');
        $this->context->prove($this->reader, $depth);
    }

    public function invalidate(): void
    {
        $this->phase = 'invalid';
        $this->witness->invalidate();
        foreach ($this->receipts as $receipt) {
            $receipt->invalidate();
        }
        if ($this->context->connection()->getEventDispatcher() === $this) {
            $this->context->connection()->setEventDispatcher($this->delegate);
        }
    }

    public function dispatch($event, $payload = [], $halt = false)
    {
        $owned = is_object($event) && property_exists($event, 'connection') && $event->connection === $this->context->connection();
        $committing = $owned && $event instanceof TransactionCommitting;
        $committed = $owned && $event instanceof TransactionCommitted;
        if ($owned && ($event instanceof TransactionBeginning || $event instanceof TransactionRolledBack
            || ($committing && $this->phase !== 'held') || ($committed && $this->phase !== 'prepared'))) {
            $this->invalidate();
        }
        try {
            $response = $this->delegate->dispatch($event, $payload, $halt);
            if ($committing) {
                $this->requireCurrent(1);
                $this->admission?->proveCurrent($this->reader->identityPrimary());
                $this->requireCurrent(1);
                $this->source->proveRetainedCurrent($this->reader);
                foreach ($this->receipts as $receipt) {
                    $receipt->sealOriginalCommit();
                }
                // No callback-capable application service follows the last original source/anchor fence.
                $this->source->proveRetainedCurrent($this->reader);
                $this->witness->prepareOriginalCommit();
                $this->requireCurrent(1);
                $this->phase = 'prepared';
            } elseif ($committed) {
                $this->requireCurrent(0);
                foreach ($this->receipts as $receipt) {
                    $this->source->proveCommittedOriginal($receipt, $this->rows());
                }
                $this->requireCurrent(0);
                $this->witness->observeOriginalPositiveCommit();
                foreach ($this->receipts as $receipt) {
                    $receipt->observeOriginalCommitted();
                }
                $this->requireCurrent(0);
                $this->phase = 'committed';
            }

            return $response;
        } catch (Throwable $error) {
            if ($committing || $committed) {
                $this->invalidate();
            }
            throw $error;
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

    public function __debugInfo(): array
    {
        return ['authority' => 'original_commit_observer'];
    }
}
