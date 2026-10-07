<?php

namespace App\Jobs;

use App\Domain\Customers\ProductionIdentity\Notifications\WorkIdentityNotice;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class DeliverProductionIdentityNotice implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $noticeId)
    {
        $this->afterCommit();
    }

    public function handle(WorkIdentityNotice $worker): void
    {
        $worker->process($this->noticeId);
    }
}
