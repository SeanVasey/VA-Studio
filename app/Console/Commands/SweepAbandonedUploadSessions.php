<?php

namespace App\Console\Commands;

use App\Domain\Media\Lifecycle\AbandonedUploadSessions;
use App\Domain\Media\Lifecycle\UploadSweepRefused;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Cleans abandoned resumable uploads (FP-034 tier 2): track-media and sound-kit upload sessions that expired while still
 * uploading, or were cancelled without their cleanup finishing, and that are older than --older-than seconds (at least
 * 24 hours, the upload lifetime; 48 hours by default). A dry run is the default and changes nothing: no row, audit
 * event or file. Only --execute marks each session `cancelled` (system audit event), removes its flat chunk directory
 * under `resumable/` on the private disk and records `cleaned_at`. Rows are retained; completed sessions, media assets,
 * quarantine originals and kit revisions are never touched. See {@see AbandonedUploadSessions} for the checks and order.
 *
 * It refuses to run as root and touches only entries its own effective user owns. Prints counts only, never a path or a
 * session identity. Exits non-zero when anything was refused or left for inspection.
 */
final class SweepAbandonedUploadSessions extends Command
{
    /** Test seam: a container binding that supplies the effective uid, honoured only in `testing`. */
    public const EUID_BINDING = 'vasey.upload-session-sweep.euid';

    protected $signature = 'vasey:upload-sessions-sweep
        {--execute : Mark and clean the abandoned sessions; without it this is a dry run}
        {--older-than= : Minimum age in seconds, at least 86400 (default: media.upload_session_sweep.min_age_seconds)}';

    protected $description = 'Dry-run (default) or clean the chunk files of expired or interrupted resumable upload sessions.';

    public function handle(): int
    {
        $option = $this->option('older-than') ?? config('media.upload_session_sweep.min_age_seconds', AbandonedUploadSessions::DEFAULT_AGE_SECONDS);
        $age = filter_var($option, FILTER_VALIDATE_INT);
        if ($age === false || $age < AbandonedUploadSessions::MIN_AGE_SECONDS) {
            $this->error('Invalid --older-than: at least '.AbandonedUploadSessions::MIN_AGE_SECONDS.' seconds.');

            return self::INVALID;
        }
        $euid = $this->laravel->environment('testing') && $this->laravel->bound(self::EUID_BINDING) ? $this->laravel->make(self::EUID_BINDING)
            : (function_exists('posix_geteuid') ? posix_geteuid() : null);
        if (! is_int($euid)) {
            $this->error('The effective user cannot be determined (the posix extension is missing); nothing was swept.');

            return self::FAILURE;
        }
        $execute = (bool) $this->option('execute');
        try {
            $counts = app(AbandonedUploadSessions::class)->sweep($execute, $age, $euid, CarbonImmutable::now());
        } catch (UploadSweepRefused $refused) {
            $this->error('Refused: '.$refused->getMessage().' Nothing was changed.');

            return self::FAILURE;
        } catch (Throwable $error) {
            // Class only: a query exception quotes its bindings.
            $this->error(sprintf('The sweep failed (%s).', $error::class));

            return self::FAILURE;
        }
        $this->info(sprintf('%s: %d expired and %d interrupted-cancel session(s) older than %d s; %d marked cancelled.',
            $execute ? 'Executed' : 'Dry run (nothing changed; use --execute)', $counts['sessions_expired'],
            $counts['sessions_cancelled_pending'], $age, $counts['sessions_marked']));
        $this->info(sprintf('Directories %s: %d (%d file(s)); already absent: %d; left in place: %d.',
            $execute ? 'removed' : 'to remove', $counts['directories_removed'], $counts['files_removed'],
            $counts['directories_absent'], $counts['directories_left']));
        $this->info(sprintf('Not touched: %d completed session(s) awaiting their own cleanup; %d foreign entr%s under the temp root.',
            $counts['completed_cleanup_pending'], $counts['foreign_entries'], $counts['foreign_entries'] === 1 ? 'y' : 'ies'));

        return $counts['directories_left'] === 0 && $counts['foreign_entries'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
