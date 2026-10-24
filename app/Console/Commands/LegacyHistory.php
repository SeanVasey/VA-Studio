<?php

namespace App\Console\Commands;

use App\Domain\Migration\History\HistoryDelta;
use App\Domain\Migration\History\HistorySourceFiles;
use App\Domain\Migration\History\LegacyArchivePolicy;
use App\Domain\Migration\History\LegacyArchiveRefusal;
use App\Domain\Migration\History\LegacyGrantReview;
use App\Domain\Migration\History\LegacyHistoryArchive;
use App\Domain\Staff\StaffWriteAuthority;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Operator entry point for the BeatStars history archive (lane MIG). Every rule lives in
 * App\Domain\Migration\History; this command admits the environment, reads one private source package, maps
 * outcomes to exit codes and prints counts, codes and hashes only (never an e-mail address, a name, a payment
 * reference or contract text).
 *
 * Every action is read-only unless `--write` is given. A write also needs the snapshot hash from the dry run
 * (import) or an evidence reference (review-grant), a staff account whose password is confirmed at a hidden prompt
 * in an interactive console, and `--attest`, which records a console attestation in the audit log first.
 *
 * Exit codes: 0 clean, complete or reconciled; 1 refused or failed; 2 import segment committed, more pending;
 * 3 findings or unexplained differences.
 */
final class LegacyHistory extends Command
{
    public const PASSWORD_PROMPT = 'Staff password (hidden)';

    public const PARTIAL = 2;

    public const UNRECONCILED = 3;

    protected $signature = 'vasey:legacy-history
        {action : dry-run, import, reconcile, delta or review-grant}
        {--source= : Absolute path of the private history source package (the baseline for delta)}
        {--later= : Absolute path of the later source package (delta only)}
        {--dispositions= : Absolute path of a private delta dispositions file (delta only)}
        {--snapshot= : Snapshot SHA-256 printed by the dry run; required with import --write}
        {--line= : Line record key to review (review-grant only)}
        {--limit=100 : Records per import segment (1-1000)}
        {--actor-id= : Staff user ID performing a write; the password is confirmed at a hidden prompt}
        {--reference= : Private evidence reference for a grant review (kept only as a keyed hash)}
        {--write : Perform the write; without it every action is a dry run}
        {--attest : Record a console attestation in the audit log before writing (required for every write)}';

    protected $description = 'Dry-run, archive, reconcile and delta-capture BeatStars order/contract/customer history (local/testing only; dry run by default).';

    private const OPTIONS = [
        'dry-run' => ['source'],
        'import' => ['source', 'snapshot', 'limit', 'actor-id'],
        'reconcile' => ['source'],
        'delta' => ['source', 'later', 'dispositions'],
        'review-grant' => ['line', 'actor-id', 'reference'],
    ];

    private const WRITES = ['import', 'review-grant'];

    /** The attestation this invocation recorded, so a later refusal does not claim that nothing was written. */
    private ?int $attestationAuditId = null;

    public function handle(LegacyArchivePolicy $policy): int
    {
        $action = $this->argument('action');
        try {
            $invalid = $this->invalid($action);
            if ($invalid !== null) {
                return $this->refuse('usage', $invalid);
            }
            $policy->admit();

            return match ($action) {
                'dry-run' => $this->dryRun(),
                'import' => $this->import($policy),
                'reconcile' => $this->reconcile(),
                'delta' => $this->delta(),
                'review-grant' => $this->reviewGrant(),
            };
        } catch (LegacyArchiveRefusal $refusal) {
            return $this->refuse($refusal->reason);
        } catch (InvalidArgumentException $error) {
            $code = preg_match('/\A[a-z0-9_]{1,64}\z/D', $error->getMessage()) === 1 ? $error->getMessage() : 'input_invalid';

            return $this->refuse($code);
        } catch (Throwable $error) {
            Log::warning('Legacy history command could not confirm the result.', ['exception_class' => $error::class]);
            $this->emit(['code' => 'failed', 'database_writes' => 'unconfirmed', 'attestation_audit_id' => $this->attestationAuditId]);
            $this->error('Stopped: the result is unconfirmed. Committed segments are kept; re-run the same command to resume.');

            return self::FAILURE;
        }
    }

    private function invalid(mixed $action): ?string
    {
        if (! is_string($action) || ! isset(self::OPTIONS[$action])) {
            return 'The action must be dry-run, import, reconcile, delta or review-grant.';
        }
        $write = (bool) $this->option('write');
        if (! in_array($action, self::WRITES, true) && ($write || $this->option('attest'))) {
            return '--write and --attest apply only to import and review-grant.';
        }
        if (! $write && $this->option('attest')) {
            return '--attest applies only with --write.';
        }
        foreach (['source', 'later', 'dispositions', 'snapshot', 'line', 'actor-id', 'reference'] as $name) {
            $value = $this->option($name);
            if ($value !== null && ! in_array($name, self::OPTIONS[$action], true)) {
                return '--'.$name.' does not apply to '.$action.'.';
            }
            if ($value !== null && (! is_string($value) || $value === '')) {
                return '--'.$name.' must not be empty.';
            }
        }
        $required = match ($action) {
            'dry-run', 'reconcile', 'import' => ['source'],
            'delta' => ['source', 'later'],
            'review-grant' => ['line'],
        };
        if ($write) {
            $required = [...$required, 'actor-id', ...($action === 'import' ? ['snapshot'] : ['reference'])];
        }
        foreach ($required as $name) {
            if ($this->option($name) === null) {
                return '--'.$name.' is required for '.$action.($write ? ' --write' : '').'.';
            }
        }
        if ($this->option('actor-id') !== null && preg_match('/\A[1-9][0-9]{0,17}\z/D', $this->option('actor-id')) !== 1) {
            return '--actor-id must be a positive whole-number ID.';
        }
        if ($this->option('actor-id') !== null && ! $write) {
            return '--actor-id applies only with --write.';
        }
        if (preg_match('/\A(?:[1-9][0-9]{0,2}|1000)\z/D', (string) $this->option('limit')) !== 1) {
            return '--limit must be a whole number from 1 to 1000.';
        }
        foreach (['snapshot', 'line'] as $name) {
            if ($this->option($name) !== null && preg_match('/\A[a-f0-9]{64}\z/D', $this->option($name)) !== 1) {
                return '--'.$name.' must be a lowercase SHA-256.';
            }
        }

        return null;
    }

    private function dryRun(): int
    {
        $loaded = (new LegacyHistoryArchive)->load((string) $this->option('source'));
        $result = $loaded['result'];
        $summary = ['code' => $result['snapshot'] === null ? 'dry_run_findings' : 'dry_run_clean', 'counts' => $result['counts'],
            'findings' => $result['findings'], 'snapshot_sha256' => $result['snapshot_sha256'], 'database_writes' => 0];
        if ($result['snapshot'] !== null) {
            $summary['plan'] = (new LegacyHistoryArchive)->plan($result['snapshot'], $result['snapshot_sha256']);
            $summary['exceptions'] = $this->exceptionCounts($result['snapshot']);
            $summary['eligible_for_review'] = count(array_filter($result['snapshot']['eligibility'], static fn (array $line): bool => $line['eligible']));
            $summary['classification'] = 'archive_only';
        }
        $this->emit($summary);

        return $result['snapshot'] === null ? self::UNRECONCILED : self::SUCCESS;
    }

    private function import(LegacyArchivePolicy $policy): int
    {
        $archive = new LegacyHistoryArchive;
        $loaded = $archive->load((string) $this->option('source'));
        $result = $loaded['result'];
        if ($result['snapshot'] === null) {
            $this->emit(['code' => 'import_refused_findings', 'counts' => $result['counts'], 'findings' => $result['findings'], 'database_writes' => 0]);

            return self::UNRECONCILED;
        }
        $policy->admitSource($result['snapshot']);
        if (! $this->option('write')) {
            $this->emit(['code' => 'import_dry_run', 'snapshot_sha256' => $result['snapshot_sha256'],
                'plan' => $archive->plan($result['snapshot'], $result['snapshot_sha256']), 'database_writes' => 0]);

            return self::SUCCESS;
        }
        // The write must archive exactly the snapshot the operator reviewed in the dry run.
        if (! hash_equals((string) $this->option('snapshot'), $result['snapshot_sha256'])) {
            return $this->refuse('snapshot_mismatch');
        }
        $actor = $this->authorized();
        $attestation = StaffWriteAuthority::attest($actor, 'vasey:legacy-history', LegacyHistoryArchive::ATTESTATION_ACTION,
            LegacyHistoryArchive::attestationScope($result['snapshot_sha256']), null, $result['snapshot_sha256']);
        $this->attestationAuditId = $attestation->auditEventId;
        $outcome = $archive->import($result['snapshot'], $result['snapshot_sha256'], $loaded['source']['contracts'], $actor,
            $attestation, (int) $this->option('limit'));
        $this->emit(['code' => $outcome['complete'] ? 'import_complete' : 'import_partial', 'snapshot_sha256' => $result['snapshot_sha256'],
            'batch_id' => $outcome['batch_id'], 'processed' => $outcome['processed'], 'pending' => $outcome['pending'], 'total' => $outcome['total'],
            'attestation_audit_id' => $attestation->auditEventId, 'entitlements_created' => 0]);

        return $outcome['complete'] ? self::SUCCESS : self::PARTIAL;
    }

    private function reconcile(): int
    {
        $loaded = (new LegacyHistoryArchive)->load((string) $this->option('source'));
        $result = $loaded['result'];
        if ($result['snapshot'] === null) {
            $this->emit(['code' => 'reconcile_refused_findings', 'counts' => $result['counts'], 'findings' => $result['findings'], 'database_writes' => 0]);

            return self::UNRECONCILED;
        }
        $report = (new LegacyHistoryArchive)->reconcile($result['snapshot'], $result['snapshot_sha256']);
        $this->emit(['code' => $report['result'], 'report' => $report, 'database_writes' => 0]);

        return $report['result'] === 'reconciled' ? self::SUCCESS : self::UNRECONCILED;
    }

    private function delta(): int
    {
        $archive = new LegacyHistoryArchive;
        $baseline = $archive->load((string) $this->option('source'))['result'];
        $later = $archive->load((string) $this->option('later'))['result'];
        if ($baseline['snapshot'] === null || $later['snapshot'] === null) {
            $this->emit(['code' => 'delta_refused_findings', 'baseline_findings' => $baseline['counts']['findings'],
                'later_findings' => $later['counts']['findings'], 'database_writes' => 0]);

            return self::UNRECONCILED;
        }
        $dispositions = $this->option('dispositions') === null ? null : (new HistorySourceFiles)->sideFile((string) $this->option('dispositions'));
        $report = (new HistoryDelta)->compare($baseline['snapshot'], $later['snapshot'], $dispositions);
        $this->emit(['code' => $report['result'], 'report' => $report, 'database_writes' => 0]);

        return $report['result'] === 'reconciled' ? self::SUCCESS : self::UNRECONCILED;
    }

    private function reviewGrant(): int
    {
        $review = new LegacyGrantReview;
        $line = (string) $this->option('line');
        if (! $this->option('write')) {
            // A dry run evaluates without a reviewer identity: authority and separation of duties are checked at write time.
            $result = $review->inspect($line);
            $this->emit(['code' => 'review_dry_run', 'line_record_key' => $line, 'eligible' => $result['eligible'],
                'blockers' => $result['blockers'], 'classification' => $review->classification($line), 'database_writes' => 0]);

            return $result['eligible'] ? self::SUCCESS : self::UNRECONCILED;
        }
        $reviewer = $this->authorized();
        $attestation = StaffWriteAuthority::attest($reviewer, 'vasey:legacy-history', LegacyGrantReview::ATTESTATION_ACTION,
            LegacyGrantReview::attestationScope($line), null, (string) $this->option('reference'));
        $this->attestationAuditId = $attestation->auditEventId;
        $result = $review->review($line, $reviewer, (string) $this->option('reference'), $attestation, true);
        $this->emit(['code' => 'review_'.$result['outcome'], 'line_record_key' => $line, 'classification' => LegacyGrantReview::CLASSIFICATION,
            'evidence_sha256' => $result['evidence_sha256'], 'attestation_audit_id' => $attestation->auditEventId, 'entitlements_created' => 0]);

        return self::SUCCESS;
    }

    /** Interactive console, current password and staff authority; attestation is always required for a write. */
    private function authorized(): User
    {
        if (! $this->input->isInteractive()) {
            throw new LegacyArchiveRefusal('interactive_console_required');
        }
        if (! $this->option('attest')) {
            throw new LegacyArchiveRefusal('attestation_required');
        }
        $password = $this->secret(self::PASSWORD_PROMPT, false);
        $actor = User::query()->find((int) $this->option('actor-id'));
        $valid = $actor !== null && is_string($password) && $password !== '' && Hash::check($password, $actor->getAuthPassword());
        unset($password);
        if (! $valid || ! Gate::forUser($actor)->allows('administer-catalog')) {
            throw new LegacyArchiveRefusal('actor_refused');
        }

        return $actor;
    }

    private function exceptionCounts(array $snapshot): array
    {
        $counts = [];
        foreach ($snapshot['exceptions'] as $exception) {
            $counts[$exception['code']] = ($counts[$exception['code']] ?? 0) + 1;
        }
        ksort($counts, SORT_STRING);

        return $counts;
    }

    private function refuse(string $reason, ?string $message = null): int
    {
        $this->emit(['code' => 'refused', 'reason' => $reason, 'database_writes' => $this->attestationAuditId === null ? 0 : 'attestation_only',
            'attestation_audit_id' => $this->attestationAuditId]);
        if ($message !== null) {
            $this->error($message);
        }

        return self::FAILURE;
    }

    private function emit(array $summary): void
    {
        $this->line(CanonicalJson::encode($summary));
    }
}
