<?php

namespace App\Console\Commands;

use App\Domain\Inquiries\Notifications\InquiryNotificationWork;
use Illuminate\Console\Command;
use Throwable;

final class ProcessInquiryAlerts extends Command
{
    protected $signature = 'vasey:process-inquiry-alerts {--limit=25}';

    protected $description = 'Process bounded retained minimal inquiry alert intents without implying delivery.';

    public function handle(InquiryNotificationWork $work): int
    {
        $limit = $this->option('limit');
        if (! is_string($limit) || preg_match('/\A(?:[1-9]|[1-9][0-9]|100)\z/D', $limit) !== 1 || ! $work->enabled()) {
            $this->error('Inquiry notifications are disabled or the bounded request is invalid.');

            return self::FAILURE;
        }
        try {
            foreach ($work->eligible((int) $limit) as $id) {
                $this->line($id.' '.$work->process($id));
            }

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Retained inquiry notification processing could not be confirmed.');

            return self::FAILURE;
        }
    }
}
