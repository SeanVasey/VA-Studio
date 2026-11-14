<?php

namespace App\Console\Commands;

use App\Domain\Migration\History\ExclusivityAuthority;
use App\Domain\Migration\History\HistorySourceFiles;
use App\Domain\Migration\History\LegacyArchivePolicy;
use App\Domain\Migration\History\LegacyArchiveRefusal;
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
 * Operator entry point for the exclusivity authority ledger used at cutover (runbook §7, §10). `status` is
 * read-only. Every transition is a dry run that only reports whether it would be admitted unless `--write` is
 * given; a write needs a staff account confirmed at a hidden prompt in an interactive console, `--attest` and a
 * private evidence reference (kept only as a keyed hash). A handover also needs the previous and the post-freeze
 * export packages, both archived and reconciled, and their delta reconciled.
 *
 * Exit codes: 0 admitted or recorded; 1 refused or failed.
 */
final class ExclusivityAuthorityCommand extends Command
{
    public const PASSWORD_PROMPT = 'Staff password (hidden)';

    protected $signature = 'vasey:exclusivity-authority
        {action : status, declare, freeze, handover, unfreeze or revert}
        {--actor-id= : Staff user ID recording the transition; the password is confirmed at a hidden prompt}
        {--reference= : Private evidence reference for the transition, such as where the BeatStars freeze is documented}
        {--baseline= : Absolute path of the previous archived source package (handover only)}
        {--later= : Absolute path of the post-freeze archived source package (handover only)}
        {--dispositions= : Absolute path of the private delta dispositions file (handover only)}
        {--write : Record the transition; without it the transition is only evaluated}
        {--attest : Record a console attestation in the audit log before writing (required for every write)}';

    protected $description = 'Show or change which single system may sell exclusives during the BeatStars cutover (local/testing only; dry run by default).';

    private ?int $attestationAuditId = null;

    public function handle(LegacyArchivePolicy $policy, ExclusivityAuthority $authority): int
    {
        $action = $this->argument('action');
        try {
            $invalid = $this->invalid($action);
            if ($invalid !== null) {
                return $this->refuse('usage', $invalid);
            }
            $policy->admit();
            if ($action === 'status') {
                $current = $authority->current();
                $this->emit(['code' => 'status', 'authority' => $current?->authority, 'sequence' => $current === null ? 0 : (int) $current->sequence,
                    'store_may_sell_exclusives' => $authority->storeMaySellExclusives(), 'database_writes' => 0]);

                return self::SUCCESS;
            }
            $handover = $action === 'handover' ? $this->handoverEvidence() : null;
            if (! $this->option('write')) {
                $actor = $this->actorForDryRun();
                $result = $authority->transition($action, $actor, '', null, false, $handover);
                $this->emit(['code' => 'transition_admitted', 'result' => $result, 'database_writes' => 0]);

                return self::SUCCESS;
            }
            $actor = $this->authorized();
            $attestation = StaffWriteAuthority::attest($actor, 'vasey:exclusivity-authority', ExclusivityAuthority::attestationAction($action),
                ExclusivityAuthority::ATTESTATION_SCOPE, null, (string) $this->option('reference'));
            $this->attestationAuditId = $attestation->auditEventId;
            $result = $authority->transition($action, $actor, (string) $this->option('reference'), $attestation, true, $handover);
            $this->emit(['code' => 'transition_recorded', 'result' => $result, 'attestation_audit_id' => $attestation->auditEventId]);

            return self::SUCCESS;
        } catch (LegacyArchiveRefusal $refusal) {
            return $this->refuse($refusal->reason);
        } catch (InvalidArgumentException $error) {
            return $this->refuse(preg_match('/\A[a-z0-9_]{1,64}\z/D', $error->getMessage()) === 1 ? $error->getMessage() : 'input_invalid');
        } catch (Throwable $error) {
            Log::warning('Exclusivity authority command could not confirm the result.', ['exception_class' => $error::class]);
            $this->emit(['code' => 'failed', 'database_writes' => 'unconfirmed', 'attestation_audit_id' => $this->attestationAuditId]);
            $this->error('Stopped: the result is unconfirmed. Run status before retrying.');

            return self::FAILURE;
        }
    }

    private function invalid(mixed $action): ?string
    {
        if (! is_string($action) || ! in_array($action, ['status', ...ExclusivityAuthority::TRANSITIONS], true)) {
            return 'The action must be status, declare, freeze, handover, unfreeze or revert.';
        }
        $write = (bool) $this->option('write');
        foreach (['actor-id', 'reference', 'baseline', 'later', 'dispositions'] as $name) {
            $value = $this->option($name);
            if ($value !== null && (! is_string($value) || $value === '')) {
                return '--'.$name.' must not be empty.';
            }
        }
        if ($action === 'status' && ($write || $this->option('attest') || $this->option('actor-id') !== null || $this->option('reference') !== null)) {
            return 'status takes no write options.';
        }
        if ($action !== 'handover' && ($this->option('baseline') !== null || $this->option('later') !== null || $this->option('dispositions') !== null)) {
            return '--baseline, --later and --dispositions apply only to handover.';
        }
        if ($action === 'handover' && ($this->option('baseline') === null || $this->option('later') === null)) {
            return 'handover needs --baseline and --later.';
        }
        if (! $write && $this->option('attest')) {
            return '--attest applies only with --write.';
        }
        if ($action !== 'status' && $write && ($this->option('actor-id') === null || $this->option('reference') === null)) {
            return 'A write needs --actor-id and --reference.';
        }
        if ($this->option('actor-id') !== null && preg_match('/\A[1-9][0-9]{0,17}\z/D', $this->option('actor-id')) !== 1) {
            return '--actor-id must be a positive whole-number ID.';
        }
        if ($this->option('reference') !== null && (strlen($this->option('reference')) > 500 || preg_match('/[\x00-\x1f\x7f]/', $this->option('reference')) === 1)) {
            return '--reference must be one line of at most 500 bytes.';
        }

        return null;
    }

    private function handoverEvidence(): array
    {
        $archive = new LegacyHistoryArchive;
        $baseline = $archive->load((string) $this->option('baseline'))['result']['snapshot'];
        $later = $archive->load((string) $this->option('later'))['result']['snapshot'];
        if ($baseline === null || $later === null) {
            throw new LegacyArchiveRefusal('handover_source_findings');
        }
        $dispositions = $this->option('dispositions') === null ? null : (new HistorySourceFiles)->sideFile((string) $this->option('dispositions'));

        return ['baseline' => $baseline, 'later' => $later, 'dispositions' => $dispositions];
    }

    /** A dry run names the actor it would write as, without a password; the write confirms it. */
    private function actorForDryRun(): User
    {
        $actor = $this->option('actor-id') === null ? null : User::query()->find((int) $this->option('actor-id'));
        if ($actor === null) {
            throw new LegacyArchiveRefusal('actor_required');
        }

        return $actor;
    }

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
