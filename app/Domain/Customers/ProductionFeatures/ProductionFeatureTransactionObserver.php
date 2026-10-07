<?php

namespace App\Domain\Customers\ProductionFeatures;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitting;
use PDO;
use ReflectionProperty;

/** One connection-local observer; ordinary delegates run once and retain their original order. */
final class ProductionFeatureTransactionObserver implements Dispatcher
{
    private ?ProductionFeatureTransaction $frame = null;

    private ?ProductionFeatureContext $context = null;

    private bool $proved = false;

    public function __construct(private readonly Connection $connection, private readonly PDO $primary,
        private readonly ProductionFeatureConfiguration $configuration, private readonly ?Dispatcher $delegate,
        private readonly DatabaseTransactionsManager $manager) {}

    public function frame(): ProductionFeatureTransaction
    {
        $this->admitBinding();
        if ($this->frame === null) {
            throw new ProductionFeatureException;
        }
        $this->frame->admit();

        return $this->frame;
    }

    public function prepare(ProductionFeatureContext $context): void
    {
        if ($this->context !== null || $context->reader()->identityPrimary() !== $this->primary
            || $this->connection->getEventDispatcher() !== $this) {
            throw new ProductionFeatureException;
        }
        $this->context = $context;
        $this->admitBinding();
        $context->admitOuter();
    }

    public function requireProof(): void
    {
        if (! $this->proved) {
            throw new ProductionFeatureException;
        }
    }

    public function abort(): void
    {
        if ($this->frame !== null) {
            if ($this->frame->protectFailure()) {
                // Ordinary Laravel rollback owns this physical source and its scoped callbacks.
                $this->connection->rollBack();
            }
        } else {
            // No marker existed: a pre-start callback may own this active PDO. Never roll it back.
            $this->connection->setPdo($this->connection->getRawPdo());
        }
    }

    public function dispatch($event, $payload = [], $halt = false)
    {
        $outerBeginning = ! $this->proved && $event instanceof TransactionBeginning && $event->connection === $this->connection
            && $this->connection->transactionLevel() === 1;
        $outerCommitting = ! $this->proved && $event instanceof TransactionCommitting && $event->connection === $this->connection
            && $this->connection->transactionLevel() === 1;
        // Later regular afterCommit/committed delegates may open their own transactions.
        // They are not our original frame; the subsequent read-only proof assesses their effects.
        if ($outerBeginning) {
            $this->admitBinding();
            if ($this->frame !== null || $this->connection->getRawPdo() !== $this->primary) {
                throw new ProductionFeatureException;
            }
            // Before the first beginning delegate can commit/reopen or replace the source.
            $this->frame = new ProductionFeatureTransaction($this->connection);
        }
        if ($outerCommitting) {
            $this->admitBinding();
        }
        $result = $this->delegate?->dispatch($event, $payload, $halt);
        if ($outerBeginning) {
            $this->frame()->admit();
        }
        if ($outerCommitting) {
            if ($this->context === null || $this->proved || $this->connection->getEventDispatcher() !== $this) {
                throw new ProductionFeatureException;
            }
            $this->admitBinding();
            $this->context->admitOuter();
            // Sole ordinary terminal proof AFTER all committing delegates, before PDO commit.
            $this->context->proveCurrent();
            $this->frame()->admit();
            $this->admitBinding();
            $this->proved = true;
        }

        return $result;
    }

    private function admitBinding(): void
    {
        $this->configuration->admit();
        ProductionFeatureConfiguration::plainPrimary($this->primary);
        if ($this->connection->getRawPdo() !== $this->primary || $this->connection->getEventDispatcher() !== $this
            || (new ReflectionProperty(Connection::class, 'transactionsManager'))->getValue($this->connection) !== $this->manager) {
            throw new ProductionFeatureException;
        }
    }

    public function listen($events, $listener = null)
    {
        $this->delegate?->listen($events, $listener);
    }

    public function hasListeners($eventName)
    {
        return $this->delegate?->hasListeners($eventName) ?? false;
    }

    public function subscribe($subscriber)
    {
        $this->delegate?->subscribe($subscriber);
    }

    public function until($event, $payload = [])
    {
        return $this->dispatch($event, $payload, true);
    }

    public function push($event, $payload = [])
    {
        $this->delegate?->push($event, $payload);
    }

    public function flush($event)
    {
        $this->delegate?->flush($event);
    }

    public function forget($event)
    {
        $this->delegate?->forget($event);
    }

    public function forgetPushed()
    {
        $this->delegate?->forgetPushed();
    }
}
