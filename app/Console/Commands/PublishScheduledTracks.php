<?php

namespace App\Console\Commands;

use App\Domain\Catalog\TrackPublicationSchedules;
use Illuminate\Console\Command;
use Throwable;

/**
 * Scheduler entry point for scheduled track publication. It accepts no clock or track input. A due schedule whose
 * publication guards fail stays scheduled; the non-zero exit is what the scheduler-health signal records as a failure,
 * which is how staff learn that a track did not go live (SQ-15).
 */
final class PublishScheduledTracks extends Command
{
    protected $signature = 'vasey:publish-scheduled-tracks';

    protected $description = 'Publish due scheduled tracks, or record why a track could not be published';

    public function handle(TrackPublicationSchedules $schedules): int
    {
        try {
            $results = $schedules->runDue();
        } catch (Throwable $exception) {
            try {
                report($exception);
            } catch (Throwable) {
                // A failing log must not replace the class-only output below with an exception message.
            }
            $this->error('UNAVAILABLE '.class_basename($exception));

            return self::FAILURE;
        }
        $failed = false;
        $acted = false;
        foreach ($results as $result) {
            if (in_array($result['outcome'], ['not_due', 'already_resolved'], true)) {
                continue;
            }
            $acted = true;
            if ($result['outcome'] === 'published') {
                $this->line('PUBLISHED schedule='.$result['schedule_id'].' track='.$result['track_id']);

                continue;
            }
            $failed = true;
            // The stored reason is staff-facing publication text; an unexpected error shows only its class.
            $this->error(strtoupper($result['outcome']).' schedule='.$result['schedule_id'].' track='.$result['track_id']
                .(isset($result['reason']) ? ' reason='.$result['reason'] : ''));
        }
        if (! $acted) {
            $this->line('NOTHING_DUE');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
