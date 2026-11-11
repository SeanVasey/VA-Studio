<?php

namespace App\Console\Commands;

use App\Domain\Media\RetagPreviews;
use App\Domain\Media\RetagPreviewsRefusal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queues fresh processing runs for draft tracks whose tagged preview was made under a different seller tag than the
 * configured one (staging: the synthetic chime, then Sean's approved tag). It reuses `QueueMediaProcessing` and the
 * unchanged `MediaProcessor`; see {@see RetagPreviews} for what is selected and why. Local, testing and staging only.
 * A write confirms the named staff actor's password at a hidden prompt in an interactive session, as the other staging
 * host commands do; `--check` writes nothing and asks nothing. Output names track ids and slugs, never a path.
 */
final class MediaRetagPreviews extends Command
{
    public const PASSWORD_PROMPT = 'Staff password (hidden)';

    protected $signature = 'vasey:media-retag-previews
        {--actor= : Email of the staff account that requests the reprocessing}
        {--check : List what would be reprocessed and write nothing}';

    protected $description = 'Queue reprocessing of draft tracks whose tagged preview does not carry the configured seller tag (local, testing or staging only).';

    public function handle(RetagPreviews $retag): int
    {
        try {
            return $this->retag($retag);
        } catch (Throwable $error) {
            // No message, path or trace leaves the console; the log keeps only the exception class.
            Log::warning('Preview retag could not confirm the result.', ['exception_class' => $error::class]);
            $this->error('Stopped: '.class_basename($error).'. Re-run with --check to see what is still pending; the run is safe to repeat.');

            return self::FAILURE;
        }
    }

    private function retag(RetagPreviews $retag): int
    {
        try {
            $retag->admit();
            $tag = $retag->configuredTag();
            $actor = $this->option('check') ? null : $retag->actor($this->option('actor'));
        } catch (RetagPreviewsRefusal $refusal) {
            return $this->refuse($refusal->getMessage());
        }
        $plan = $retag->plan($tag);
        $this->line('configured_tag_sha256='.$tag);
        $this->line('current='.$plan['current'].' stale_draft='.count($plan['stale']));
        foreach ($plan['stale'] as $entry) {
            $this->line('  track #'.$entry['track_id'].' ('.$entry['slug'].') '.$entry['state']);
        }
        foreach ($plan['skipped'] as $reason => $ids) {
            if ($ids !== []) {
                $this->line('skipped_'.$reason.'='.count($ids).' tracks #'.implode(', #', $ids));
            }
        }
        if ($plan['skipped']['live'] !== []) {
            $this->warn('Live tracks are never altered here. Unpublish a track, then run this again or use Media → Process / retry; a new preview needs a newly published offer snapshot.');
        }
        if ($actor === null) {
            $this->line('Checked only. Nothing was written.');

            return self::SUCCESS;
        }
        if (! $this->input->isInteractive()) {
            return $this->refuse('Retagging previews requires an interactive session (not --no-interaction) to confirm the staff password at a hidden prompt.');
        }
        if (! $this->confirmed($actor)) {
            return $this->refuse('Staff authority was refused: the password was not confirmed or the account may not administer the catalog.');
        }
        try {
            $result = $retag->queue($actor, $plan);
        } catch (AuthorizationException) {
            return $this->refuse('Staff authorization was refused while queueing.');
        }
        $this->line('queued='.count($result['queued']).' in_flight='.count($result['in_flight']).' refused='.count($result['refused']));
        $this->line('Reprocessing runs in the media queue worker; run --check again to follow it. Nothing was published or altered on a live track.');

        return $result['refused'] === [] ? self::SUCCESS : self::FAILURE;
    }

    /** As the other staging host commands confirm an account: hidden input, current password hash and current authority. */
    private function confirmed(User $account): bool
    {
        $password = $this->secret(self::PASSWORD_PROMPT, false);
        $current = User::query()->find($account->getKey());
        $valid = $current !== null && is_string($password) && $password !== '' && Hash::check($password, $current->getAuthPassword());
        unset($password);

        return $valid && Gate::forUser($current)->allows('administer-catalog');
    }

    private function refuse(string $message): int
    {
        $this->error('Refused: '.$message.' Nothing was written.');

        return self::FAILURE;
    }
}
