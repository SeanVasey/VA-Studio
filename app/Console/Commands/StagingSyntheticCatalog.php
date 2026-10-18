<?php

namespace App\Console\Commands;

use App\Domain\Catalog\SyntheticRehearsal\SyntheticRehearsalBuilder;
use App\Domain\Catalog\SyntheticRehearsal\SyntheticRehearsalCatalog;
use App\Domain\Catalog\SyntheticRehearsal\SyntheticRehearsalPolicy;
use App\Domain\Catalog\SyntheticRehearsal\SyntheticRehearsalRefusal;
use App\Domain\Media\MediaFailure;
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
use Throwable;

/**
 * Seeds the labelled synthetic staging rehearsal catalog (about six tracks) through the real domain services, so testers
 * can rehearse catalog → player → quote → Stripe test checkout → verified payment → contract → download before real
 * tracks exist. Default off. It refuses when the configured or running environment is production or the two differ,
 * outside local, testing and staging, unless Stripe is in test mode, and on a catalog that already holds non-synthetic
 * published tracks or approved licenses. Writes need both staff passwords at hidden prompts in an interactive console.
 */
final class StagingSyntheticCatalog extends Command
{
    public const OPERATOR_PROMPT = 'Synthetic operator password (hidden)';

    public const REVIEWER_PROMPT = 'Synthetic reviewer password (hidden)';

    private const LOCK = 'vasey:staging-synthetic-catalog';

    protected $signature = 'vasey:staging-synthetic-catalog
        {--operator= : Email of the synthetic catalog operator (author, rights verifier and publisher)}
        {--reviewer= : Email of the independent synthetic license reviewer}
        {--attest : Record a console attestation for both accounts in the audit log before writing (required where staff MFA is required)}
        {--check : Run the admission checks and print the plan without writing anything}';

    protected $description = 'Create the labelled synthetic staging rehearsal catalog (local, testing or staging only; default off).';

    /** Audit rows of the attestations this invocation recorded, so a later stop does not imply that nothing changed. */
    private array $attestationAuditIds = [];

    public function handle(SyntheticRehearsalPolicy $policy, SyntheticRehearsalBuilder $builder): int
    {
        try {
            return $this->synthesize($policy, $builder);
        } catch (Throwable $error) {
            // No message, path or trace leaves the console; the log keeps only the exception class.
            Log::warning('Synthetic rehearsal catalog could not confirm the result.', ['exception_class' => $error::class]);
            $this->error('Stopped: the result is unconfirmed. Re-run the command to resume; finished steps are kept and nothing is duplicated.'
                .$this->attestationNote());

            return self::FAILURE;
        }
    }

    private function synthesize(SyntheticRehearsalPolicy $policy, SyntheticRehearsalBuilder $builder): int
    {
        if ($this->option('check') && $this->option('attest')) {
            return $this->refuse('--attest does not apply to --check.');
        }
        try {
            $policy->admit();
            [$operator, $reviewer] = $policy->operators($this->option('operator'), $this->option('reviewer'));
            $policy->media();
        } catch (SyntheticRehearsalRefusal $refusal) {
            return $this->refuse($refusal->getMessage());
        }
        $this->warn(SyntheticRehearsalCatalog::LABEL.'. Environment: '.app()->environment().'.');
        if ($this->option('check')) {
            $this->line('Admission passed. Operator #'.$operator->id.', reviewer #'.$reviewer->id.'. Nothing was written.');
            foreach (SyntheticRehearsalCatalog::licenses() as $license) {
                $this->line('license '.$license['slug'].'  '.$license['name'].'  '.number_format($license['price_minor'] / 100, 2).' '.SyntheticRehearsalCatalog::CURRENCY);
            }
            foreach (SyntheticRehearsalCatalog::tracks() as $track) {
                $this->line('track   '.$track['slug'].'  '.$track['title'].'  '.$track['duration'].' s');
            }

            return self::SUCCESS;
        }
        if (! $this->input->isInteractive()) {
            return $this->refuse('Synthetic catalog writes require an interactive trusted console to confirm both staff passwords.');
        }
        // Both accounts act in this run, so each password is confirmed. One shell still drives both accounts.
        if (! $this->confirmed($operator, self::OPERATOR_PROMPT) || ! $this->confirmed($reviewer, self::REVIEWER_PROMPT)) {
            return $this->refuse('Staff authority was refused: a synthetic account password was not confirmed.');
        }
        if (! $this->option('attest') && StaffWriteAuthority::attestationRequired()) {
            return $this->refuse('This environment requires staff multi-factor authentication and a console has no factor challenge. '
                .'Re-run with --attest to record a console attestation for both accounts in the audit log before any write.');
        }
        $lock = Cache::lock(self::LOCK, 3600);
        if (! $lock->get()) {
            return $this->refuse('Another synthetic catalog run holds the lock.');
        }
        try {
            if ($this->option('attest')) {
                $this->attest($operator, $reviewer);
            }
            $licenses = $builder->licenses($operator, $reviewer);
            foreach ($licenses as $key => $license) {
                $this->line('license '.SyntheticRehearsalCatalog::licenses()[$key]['slug'].'  '.$license['state'].'  version #'.$license['version']->id);
            }
            $rows = [];
            $ready = true;
            foreach (SyntheticRehearsalCatalog::tracks() as $definition) {
                $result = $builder->track($definition, $licenses, $operator);
                $ready = $ready && $result['ready'];
                $offers = [];
                foreach ($result['revisions'] as $key => $revision) {
                    $offers[] = strtoupper($key).' revision #'.$revision->id.' at '.number_format($revision->price_minor / 100, 2).' '.$revision->currency;
                }
                $rows[] = [$definition['slug'], $result['state'], implode('; ', $offers) ?: '-'];
                $this->line('track   '.$definition['slug'].'  '.$result['state']);
            }
        } catch (SyntheticRehearsalRefusal $refusal) {
            return $this->stop($refusal->getMessage());
        } catch (ValidationException $exception) {
            return $this->stop('a domain guard refused: '.implode(' ', $exception->validator->errors()->all()));
        } catch (AuthorizationException $exception) {
            return $this->stop('staff authorization refused: '.($exception->getMessage() ?: 'This action is unauthorized.'));
        } catch (MediaFailure $failure) {
            return $this->stop('the media pipeline refused: '.$failure->failureCode.'.');
        } finally {
            $lock->release();
        }
        $this->table(['Track', 'State', 'Offers (TEST PRICE)'], $rows);
        $this->line('Before \'Prepare order\', link each offer revision to a synthetic rights scope with `php artisan vasey:rights-scope` '
            .'(docs/ops/staging-test-purchase.md, setup step 5). Reset staging before loading real content (docs/ops/staging-runbook.md).');

        return $ready ? self::SUCCESS : self::FAILURE;
    }

    /** As `vasey:rights-scope` confirms its operator: hidden input with no visible fallback, current hash and authority. */
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
    private function attest(User $operator, User $reviewer): void
    {
        $scope = SyntheticRehearsalCatalog::MARKER.' operator='.$operator->id.' reviewer='.$reviewer->id;
        $reference = SyntheticRehearsalCatalog::MARKER.'-run:'.Str::uuid();
        foreach ([[$operator, 'synthetic_catalog.operator'], [$reviewer, 'synthetic_catalog.reviewer']] as [$account, $action]) {
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
        $this->error('Stopped: '.$message.$this->attestationNote());

        return self::FAILURE;
    }

    private function attestationNote(): string
    {
        return $this->attestationAuditIds === [] ? ''
            : ' The attestation audit records (audit_event='.implode(', ', $this->attestationAuditIds).') remain.';
    }
}
