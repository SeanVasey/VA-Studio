<?php

namespace App\Domain\Grants\PaidTax;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Collection;
use LogicException;
use PDO;
use ReflectionProperty;

/** Retains one exact original birth before ordinary begun delegates; never a commit observer. */
final class PaidTaxBeginningDispatcher implements Dispatcher, \JsonSerializable
{
    private bool $captured = false;

    private bool $active = true;

    private readonly ReflectionProperty $hooksProperty;

    private readonly array $originalHooks;

    private readonly array $guardedHooks;

    private readonly DatabaseTransactionsManager $manager;

    private readonly string $name;

    private function __construct(
        private readonly Connection $connection,
        private readonly PDO $primary,
        private readonly Dispatcher $delegate,
        private readonly Closure $retain,
        private readonly PaidTaxRuntime $runtime,
    ) {
        $this->hooksProperty = new ReflectionProperty(Connection::class, 'beforeStartingTransaction');
        $hooks = $this->hooksProperty->getValue($connection);
        PaidTaxException::require(is_array($hooks));
        foreach ($hooks as $hook) {
            PaidTaxException::require($hook instanceof Closure);
        }
        $this->originalHooks = $hooks;
        $manager = (new ReflectionProperty(Connection::class, 'transactionsManager'))->getValue($connection);
        PaidTaxException::require($manager instanceof DatabaseTransactionsManager && $manager::class === DatabaseTransactionsManager::class);
        $this->manager = $manager;
        $this->name = $connection->getName();
        $this->proveUnopenedManager();
        $guard = function (): void {
            if (! $this->active) {
                // If a delegate retained a changed hook array, this exact former owned hook is inert.
                return;
            }
            $hooks = $this->hooksProperty->getValue($this->connection);
            PaidTaxException::require(is_array($hooks) && $hooks === $this->guardedHooks
                && $this->connection->getEventDispatcher() === $this);
            $this->runtime->outsideTransactions();
            $this->proveUnopenedManager();
        };
        $this->guardedHooks = [...$hooks, $guard];
    }

    public static function install(PaidTaxRuntime $runtime, Closure $retain): self
    {
        $delegate = $runtime->connection->getEventDispatcher();
        PaidTaxException::require($delegate instanceof Dispatcher && ! $delegate instanceof self);
        $observer = new self($runtime->connection, $runtime->primary, $delegate, $retain, $runtime);
        $observer->hooksProperty->setValue($runtime->connection, $observer->guardedHooks);
        $runtime->connection->setEventDispatcher($observer);

        return $observer;
    }

    public function releaseOwned(): void
    {
        $this->active = false;
        if ($this->hooksProperty->getValue($this->connection) === $this->guardedHooks) {
            $this->hooksProperty->setValue($this->connection, $this->originalHooks);
        }
        if ($this->connection->getEventDispatcher() === $this) {
            $this->connection->setEventDispatcher($this->delegate);
        }
    }

    private function proveUnopenedManager(): void
    {
        PaidTaxException::require((new ReflectionProperty(Connection::class, 'transactionsManager'))->getValue($this->connection) === $this->manager);
        $current = (new ReflectionProperty(DatabaseTransactionsManager::class, 'currentTransaction'))->getValue($this->manager);
        PaidTaxException::require(is_array($current) && ($current[$this->name] ?? null) === null);
        foreach (['pendingTransactions', 'committedTransactions'] as $property) {
            $records = (new ReflectionProperty(DatabaseTransactionsManager::class, $property))->getValue($this->manager);
            PaidTaxException::require($records instanceof Collection && $records::class === Collection::class);
            $items = (new ReflectionProperty(Collection::class, 'items'))->getValue($records);
            PaidTaxException::require(is_array($items));
            foreach ($items as $record) {
                PaidTaxException::require($record instanceof DatabaseTransactionRecord && $record::class === DatabaseTransactionRecord::class);
                $attributes = get_object_vars($record);
                PaidTaxException::require(is_string($attributes['connection'] ?? null) && $attributes['connection'] !== $this->name);
            }
        }
    }

    public function dispatch($event, $payload = [], $halt = false)
    {
        if ($event instanceof TransactionBeginning && $event::class === TransactionBeginning::class
            && $event->connection === $this->connection) {
            PaidTaxException::require($this->active && ! $this->captured && $this->connection->getEventDispatcher() === $this);
            // Laravel has just opened the PDO/root record. Retain its native anchor BEFORE callbacks,
            // independently of policy/deadline that those callbacks can withdraw or exhaust.
            $frame = PaidTaxOwnedFrame::capture($this->connection, $this->primary);
            $this->captured = true;
            ($this->retain)($frame);
        }

        return $this->delegate->dispatch($event, $payload, $halt);
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
        throw new LogicException('Private Tax transaction birth observers cannot be serialized.');
    }

    public function __unserialize(array $data): never
    {
        throw new LogicException('Private Tax transaction birth observers cannot be deserialized.');
    }

    public function jsonSerialize(): never
    {
        throw new LogicException('Private Tax transaction birth observers are internal server objects.');
    }
}
