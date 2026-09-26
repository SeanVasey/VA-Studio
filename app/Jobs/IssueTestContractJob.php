<?php

namespace App\Jobs;

use App\Domain\Contracts\RenderTestContract;
use App\Domain\Contracts\RequestTestContract;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Only a trusted internal grant locator enters the queue; frozen private input stays in the database. */
final class IssueTestContractJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 90;

    public function __construct(public readonly int $grantId)
    {
        $this->onQueue('contracts');
        $this->afterCommit();
    }

    public function handle(RequestTestContract $requests, RenderTestContract $renderer): void
    {
        try {
            $request = $requests->handle($this->grantId);
            $renderer->handle($request->id);
        } catch (Throwable) {
            // Durable grant/request scanning recovers interrupted work. Never log private input or paths here.
        }
    }
}
