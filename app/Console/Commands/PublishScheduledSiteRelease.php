<?php

namespace App\Console\Commands;

use App\Domain\SiteBuilder\SiteContent;
use Illuminate\Console\Command;
use Throwable;

/** Scheduler entry point. It accepts no clock or release input; the service decides from retained state. */
final class PublishScheduledSiteRelease extends Command
{
    protected $signature = 'vasey:publish-scheduled-site-release';

    protected $description = 'Publish the due scheduled site release, or record why it cannot be published';

    public function handle(SiteContent $site): int
    {
        try {
            $result = $site->runDueSchedule();
        } catch (Throwable $exception) {
            // The transaction rolled back, so the schedule stays pending and the next run retries within its grace window.
            // Only the class is printed: messages can carry SQL, paths or configuration.
            $this->error('UNAVAILABLE '.class_basename($exception));

            return self::FAILURE;
        }
        // A competing runner may resolve the schedule first; that is not a failure of this run.
        if ($result === null || in_array($result['outcome'], ['not_due', 'already_resolved'], true)) {
            $this->line('NOTHING_DUE');

            return self::SUCCESS;
        }
        if ($result['outcome'] === 'published') {
            $this->line('PUBLISHED schedule='.$result['schedule_id'].' revision='.$result['publication_revision']);

            return self::SUCCESS;
        }
        $this->error(($result['outcome'] === 'grace_expired' ? 'EXPIRED' : 'FAILED '.$result['outcome']).' schedule='.$result['schedule_id']);

        return self::FAILURE;
    }
}
