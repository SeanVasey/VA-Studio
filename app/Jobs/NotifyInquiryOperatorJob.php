<?php

namespace App\Jobs;

use App\Domain\Inquiries\Notifications\InquiryNotificationWork;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Numeric intent locator only; the private inquiry never enters queue payloads. */
final class NotifyInquiryOperatorJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public readonly int $intentId)
    {
        $this->onQueue('inquiry-alerts');
        $this->afterCommit();
    }

    public function handle(InquiryNotificationWork $work): void
    {
        try {
            $work->process($this->intentId);
        } catch (Throwable) {
            // Retained pending/expired work is recovered by the bounded scanner; no private diagnostics.
        }
    }
}
