<?php

namespace App\Domain\Commerce\ProductionCheckout;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\Events\TransactionRolledBack;

/** ONE local new-write observer; ordinary callbacks precede fixed original admission. */
final class CheckoutCommandCommitDispatcher implements Dispatcher
{
    private string $phase = 'held';

    private function __construct(private readonly Dispatcher $delegate, private readonly CheckoutCommandFrame $frame,
        private readonly CheckoutCommitAdmission $admission) {}

    public static function capture(CheckoutCommandFrame $frame, CheckoutCommitAdmission $admission): self
    {
        $frame->prove(1);
        $delegate = $frame->connection()->getEventDispatcher();
        CheckoutException::require($delegate instanceof Dispatcher && ! $delegate instanceof self
            && $delegate::class !== __NAMESPACE__.'\\OriginalCommitDispatcher', 'write_frame');
        $observer = new self($delegate, $frame, $admission);
        $frame->connection()->setEventDispatcher($observer);

        return $observer;
    }

    public function dispatch($event, $payload = [], $halt = false)
    {
        $owned = is_object($event) && property_exists($event, 'connection') && $event->connection === $this->frame->connection();
        if ($owned && ($event instanceof TransactionBeginning || $event instanceof TransactionRolledBack)) {
            $this->phase = 'invalid';
        }
        $response = $this->delegate->dispatch($event, $payload, $halt);
        if ($owned && $event instanceof TransactionCommitting) {
            CheckoutException::require($this->phase === 'held', 'write_frame');
            $this->frame->prove(1);
            $this->admission->proveCurrent();
            // Pure fresh admission and the physical original-frame anchor are last.
            $this->admission->proveFresh();
            $this->frame->proveAnchor();
            $this->phase = 'prepared';
        } elseif ($owned && $event instanceof TransactionCommitted) {
            CheckoutException::require($this->phase === 'prepared', 'write_frame');
            $this->frame->markCommitted();
            $this->frame->prove(0);
            $this->phase = 'committed';
        }

        return $response;
    }

    public function restore(): void
    {
        if ($this->frame->connection()->getEventDispatcher() === $this) {
            $this->frame->connection()->setEventDispatcher($this->delegate);
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
}
