<?php

namespace App\Console\Commands;

use App\Domain\Media\MediaFailure;
use App\Domain\Migration\BeatStarsImport\BeatStarsCatalogImport;
use App\Domain\Migration\BeatStarsImport\BeatStarsImportPolicy;
use App\Domain\Migration\BeatStarsImport\BeatStarsImportRefusal;
use App\Domain\Migration\BeatStarsImport\BeatStarsImportSource;
use App\Domain\Migration\BeatStarsImport\BeatStarsMediaFiles;
use App\Domain\Migration\BeatStarsImport\BeatStarsReadinessReport;
use App\Domain\Migration\BeatStarsImport\BeatStarsTrackMedia;
use App\Domain\Staff\StaffWriteAuthority;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Imports Sean's BeatStars catalog into the staging store as UNPUBLISHED DRAFTS with their media, so Sean can review and
 * publish them himself. The drafts come from a staged dry-run snapshot through the reviewed draft importer; the files the
 * export lists are taken from an import root and enter through the ordinary media intake and processing services. It
 * creates no offer, license, rights declaration, price or publication.
 *
 * Default off. It refuses production and any environment mismatch, outside local, testing and staging, unless Stripe is in
 * test mode. Writes need two named staff accounts with MFA, both passwords at hidden prompts in an interactive session
 * (`--no-interaction` is refused; a piped password is accepted, the confirmed passwords are the gate) and, where staff MFA
 * is required, `--attest`. `--check` writes nothing. Stdout carries counts only: no path, title, identifier or personal
 * value; the per-track readiness report goes to a private file.
 */
final class StagingBeatStarsImport extends Command
{
    public const OPERATOR_PROMPT = 'Import operator password (hidden)';

    public const REVIEWER_PROMPT = 'Second staff account password (hidden)';

    private const LOCK = 'vasey:staging-beatstars-import';

    protected $signature = 'vasey:staging-beatstars-import
        {--operator= : Email of the staff account that authors every write}
        {--reviewer= : Email of the second named staff account that confirms the run}
        {--source= : Absolute path of the staged private snapshot directory (catalog.json and raw/<export>)}
        {--files-root= : Absolute path of the import root holding the media files at their listed paths}
        {--report= : Absolute path of the readiness report to write (a .json file in a private 0700 directory); required unless --check}
        {--limit=10 : At most this many drafts get media taken in this run (1 to 100)}
        {--from= : Start the media pass at this export track identifier, in identifier order}
        {--release-commit= : 40-character commit of the deployed code (default: git HEAD of this checkout)}
        {--release-tree= : 40-character tree of the deployed code (default: git HEAD^{tree} of this checkout)}
        {--attest : Record a console attestation for both accounts in the audit log before writing (required where staff MFA is required)}
        {--check : Run the admission checks and print the plan without writing anything}';

    protected $description = 'Import the BeatStars catalog as unpublished drafts with their media (local, testing or staging only; default off).';

    /** Audit rows of the attestations this invocation recorded, so a later stop does not imply that nothing changed. */
    private array $attestationAuditIds = [];

    /** True once the write phase began (the run lock is held); before it nothing can have been written. */
    private bool $writeStarted = false;

    public function handle(BeatStarsImportPolicy $policy): int
    {
        try {
            return $this->import($policy);
        } catch (Throwable $error) {
            // No message, path or trace leaves the console; the log keeps only the exception class.
            Log::warning('BeatStars import could not confirm the result.', ['exception_class' => $error::class]);
            $this->error($this->writeStarted
                ? 'Stopped: the result is unconfirmed. Re-run the command to resume; finished steps are kept and nothing is duplicated.'.$this->attestationNote()
                : 'Stopped: the command could not complete before its write phase began, so nothing was written. Re-run it.');

            return self::FAILURE;
        }
    }

    private function import(BeatStarsImportPolicy $policy): int
    {
        $check = (bool) $this->option('check');
        if ($check && $this->option('attest')) {
            return $this->refuse('--attest does not apply to --check.');
        }
        try {
            $policy->admit();
            [$operator, $reviewer] = $policy->operators($this->option('operator'), $this->option('reviewer'));
            $policy->media();
            [$limit, $from] = $this->batch();
            $loaded = $this->loadSource();
            $files = BeatStarsMediaFiles::open($this->path('files-root'));
            $report = new BeatStarsReadinessReport;
            if (! $check) {
                $report->admit($this->path('report'), [$this->path('source'), $this->path('files-root')]);
            }
            $release = $this->release();
        } catch (BeatStarsImportRefusal $refusal) {
            return $this->refuse($refusal->getMessage());
        }
        ['source' => $source, 'listings' => $listings] = $loaded;
        $import = new BeatStarsCatalogImport;
        $this->warn('BeatStars catalog import: unpublished drafts only, no offers, licenses, rights or prices. Environment: '.app()->environment().'.');
        if ($check) {
            return $this->plan($import, $source, $listings, $files, $release, $operator, $reviewer);
        }
        if (! $this->input->isInteractive()) {
            return $this->refuse('The BeatStars import requires an interactive session (not --no-interaction) to confirm both staff passwords at hidden prompts.');
        }
        if (! $this->confirmed($operator, self::OPERATOR_PROMPT) || ! $this->confirmed($reviewer, self::REVIEWER_PROMPT)) {
            return $this->refuse('Staff authority was refused: a staff account password was not confirmed.');
        }
        if (! $this->option('attest') && StaffWriteAuthority::attestationRequired()) {
            return $this->refuse('This environment requires staff multi-factor authentication and a console has no factor challenge. '
                .'Re-run with --attest to record a console attestation for both accounts in the audit log before any write.');
        }
        $lock = Cache::lock(self::LOCK, 7200);
        if (! $lock->get()) {
            return $this->refuse('Another BeatStars import run holds the lock.');
        }
        $this->writeStarted = true;
        $outcomes = [];
        $run = ['drafts_created' => 0, 'drafts_already_mapped' => 0];
        $reportPath = $this->path('report');
        $stopped = null;
        try {
            if ($this->option('attest')) {
                $this->attest($operator, $reviewer, $source['snapshot']['snapshot_id']);
            }
            $drafts = $import->drafts($source, $release, $operator);
            $run = ['drafts_created' => $drafts['created'], 'drafts_already_mapped' => $drafts['already_mapped']];
            $this->line('drafts created='.$drafts['created'].' already_mapped='.$drafts['already_mapped']);
            $import->media($source, $listings, new BeatStarsTrackMedia($files), $operator, $from, $limit, $outcomes, function (string $track, array $outcome): void {
                $this->line('draft #'.$track.'  '.implode('  ', array_map(
                    static fn (string $role): string => $role.'='.($outcome['roles'][$role]['outcome'] ?? '-').(isset($outcome['roles'][$role]['code']) ? ':'.$outcome['roles'][$role]['code'] : ''),
                    ['master_wav', 'artwork', 'stems_zip'])).'  association='.$outcome['stems_association']);
            });
        } catch (BeatStarsImportRefusal $refusal) {
            $stopped = $refusal->getMessage();
        } catch (ValidationException $exception) {
            $stopped = 'a domain guard refused: '.implode(' ', $exception->validator->errors()->all());
        } catch (AuthorizationException $exception) {
            $stopped = 'staff authorization refused: '.($exception->getMessage() ?: 'This action is unauthorized.');
        } catch (MediaFailure $failure) {
            $stopped = 'the media pipeline refused: '.$failure->failureCode.'.';
        } finally {
            // Whatever happened, the report shows the truth as the database now holds it; a failure to write it never hides the stop.
            try {
                $built = $report->build($source, $import->rows($source), $listings, $files, $outcomes, $report->previous($reportPath), $run);
                $report->write($reportPath, $built, [$this->path('source'), $this->path('files-root')]);
            } catch (Throwable $error) {
                Log::warning('BeatStars import readiness report was not written.', ['exception_class' => $error::class]);
                $built = null;
                $stopped ??= 'the readiness report could not be written; re-run the command to write it';
            }
            $lock->release();
        }
        if ($built !== null) {
            $this->summary($built);
        }
        if ($stopped !== null) {
            return $this->stop($stopped);
        }
        $failed = array_filter($outcomes, static fn (array $outcome): bool => str_starts_with($outcome['stems_association'], 'failed:')
            || array_filter($outcome['roles'], static fn (array $role): bool => in_array($role['outcome'] ?? '', ['failed', 'rejected'], true)) !== []);
        if ($failed !== []) {
            $this->error(count($failed).' draft(s) had media that was rejected or failed; the readiness report names each. Correct the cause and re-run; finished steps are kept.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** `--check`: the admission result and counts of the plan, with no write and no password. */
    private function plan(BeatStarsCatalogImport $import, array $source, array $listings, BeatStarsMediaFiles $files, array $release, User $operator, User $reviewer): int
    {
        $this->line('Admission passed. Operator #'.$operator->id.', reviewer #'.$reviewer->id.'. Nothing was written.');
        $rows = $import->rows($source);
        $mapped = count(array_filter($rows, static fn (array $row): bool => $row['track_id'] !== null));
        $this->line('records='.count($rows).' already_mapped='.$mapped.' to_create='.(count($rows) - $mapped));
        try {
            $review = $import->review($source, $release, $operator);
        } catch (ValidationException $exception) {
            return $this->refuse('a domain guard refused the review: '.implode(' ', $exception->validator->errors()->all()));
        }
        if ($review === null) {
            $this->line('review: every record is already a draft; only the media pass would run.');
        } else {
            $this->line('review: create_draft='.$review['counts']['create_draft'].' skip='.$review['counts']['skip'].' conflict='.$review['counts']['conflict']);
            foreach ($import->conflicts($review) as $reason => $count) {
                $this->line('  conflict '.$reason.' x'.$count);
            }
        }
        $found = [];
        foreach ($listings as $listing) {
            foreach ($listing as $role => $cell) {
                $state = $files->resolve($role, $cell);
                $key = $state['state'] === 'refused' ? 'refused' : $state['state'];
                $found[$role][$key] = ($found[$role][$key] ?? 0) + 1;
            }
        }
        foreach ($found as $role => $states) {
            ksort($states);
            $this->line('files '.$role.': '.implode(' ', array_map(static fn (string $state, int $count): string => $state.'='.$count, array_keys($states), $states)));
        }
        $this->line('The preview MP3 is only checked for presence: the pipeline generates the tagged preview from the master.');

        return ($review['counts']['conflict'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function summary(array $report): void
    {
        $this->line('summary '.json_encode($report['summary']['counts'], JSON_THROW_ON_ERROR));
        foreach ($report['summary']['publication_blockers'] as $blocker => $count) {
            $this->line('blocker x'.$count.'  '.$blocker);
        }
        $this->line('Nothing was published and no offer, license, rights declaration or price was created. Review the drafts in the admin; the readiness report holds the per-track detail.');
    }

    /** @return array{0: int, 1: ?string} */
    private function batch(): array
    {
        $limit = $this->option('limit');
        if (! is_string($limit) || preg_match('/\A[1-9][0-9]{0,2}\z/D', $limit) !== 1 || (int) $limit > 100) {
            throw new BeatStarsImportRefusal('--limit must be a whole number from 1 to 100.');
        }
        $from = $this->option('from');
        if ($from !== null && (! is_string($from) || $from === '' || mb_strlen($from) > 190 || preg_match('/[\x00-\x1f\x7f]/', $from) === 1)) {
            throw new BeatStarsImportRefusal('--from must be an export track identifier.');
        }

        return [(int) $limit, $from];
    }

    private function loadSource(): array
    {
        return BeatStarsImportSource::load($this->path('source'));
    }

    private function path(string $option): string
    {
        $value = $this->option($option);
        if (! is_string($value) || $value === '') {
            throw new BeatStarsImportRefusal('--'.$option.' is required.');
        }

        return $value;
    }

    /** The code identity the draft importer binds, from the options or this checkout's git HEAD, with the live schema proof. */
    private function release(): array
    {
        $commit = $this->option('release-commit');
        $tree = $this->option('release-tree');
        if ($commit === null && $tree === null) {
            $process = new Process(['git', 'rev-parse', 'HEAD', 'HEAD^{tree}'], base_path(), timeout: 10);
            $process->run();
            $lines = $process->isSuccessful() ? explode("\n", trim($process->getOutput())) : [];
            [$commit, $tree] = count($lines) === 2 ? $lines : [null, null];
        }
        foreach ([$commit, $tree] as $identity) {
            if (! is_string($identity) || preg_match('/\A[a-f0-9]{40}\z/D', $identity) !== 1) {
                throw new BeatStarsImportRefusal('The code identity could not be read. Pass --release-commit and --release-tree (40 hex characters each).');
            }
        }

        return (new BeatStarsCatalogImport)->release($commit, $tree);
    }

    /** As `vasey:staging-synthetic-catalog` confirms its accounts: hidden input with no visible fallback, current hash and authority. */
    private function confirmed(User $account, string $prompt): bool
    {
        $password = $this->secret($prompt, false);
        $current = User::query()->find($account->getKey());
        $valid = $current !== null && is_string($password) && $password !== '' && Hash::check($password, $current->getAuthPassword());
        unset($password);

        return $valid && Gate::forUser($current)->allows('administer-catalog');
    }

    /**
     * One append-only `staff.console_attestation` per account (who, when, this command, its role, both account IDs, and
     * whether the panel rule was required and the account enrolled), recorded before the first write. They are audit
     * evidence: no writer consumes them, and every domain service still requires each account's current enrollment.
     */
    private function attest(User $operator, User $reviewer, string $snapshotId): void
    {
        $scope = 'beatstars-import snapshot='.$snapshotId.' operator='.$operator->id.' reviewer='.$reviewer->id;
        $reference = 'beatstars-import-run:'.Str::uuid();
        foreach ([[$operator, 'beatstars_import.operator'], [$reviewer, 'beatstars_import.reviewer']] as [$account, $action]) {
            $attestation = StaffWriteAuthority::attest($account, (string) $this->getName(), $action, $scope, null, $reference);
            $this->attestationAuditIds[] = $attestation->auditEventId;
            $this->line('attestation=recorded audit_event='.$attestation->auditEventId.' account=#'.$account->id);
        }
    }

    private function refuse(string $message): int
    {
        $this->error('Refused: '.$message.' Nothing was written.');

        return self::FAILURE;
    }

    private function stop(string $message): int
    {
        $this->error('Stopped: '.$message.' Finished steps are kept; re-run the command to resume.'.$this->attestationNote());

        return self::FAILURE;
    }

    private function attestationNote(): string
    {
        return $this->attestationAuditIds === [] ? ''
            : ' The attestation audit records (audit_event='.implode(', ', $this->attestationAuditIds).') remain.';
    }
}
