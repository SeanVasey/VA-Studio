<?php

namespace App\Jobs;

use App\Domain\Notifications\Mail\TransactionalMailAdapter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** One-attempt wake-up carrying only the delivery id; the retained delivery row decides whether anything is sent. */
final class DeliverTransactionalMail implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $deliveryId)
    {
        $this->afterCommit();
    }

    public function handle(TransactionalMailAdapter $adapter): void
    {
        $adapter->deliver($this->deliveryId);
    }
}
