<?php

namespace App\Console\Commands;

use App\Domain\Media\MediaFailure;
use App\Domain\SoundKits\SoundKitCliImport;
use App\Domain\SoundKits\SoundKitCliImportRefusal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Imports a kit ZIP that is already on the host (for example `scp`'d to /tmp) through exactly the intake the admin upload uses,
 * so a staging operator does not need the browser. Local, testing and staging only; production and an ambiguous environment
 * are refused. The named staff actor must hold catalog authority and satisfy the panel's MFA rule; the domain re-reads both
 * under lock. A write confirms that actor's password at a hidden prompt in an interactive session (the same pattern as
 * the other staging host commands: host shell access plus the staff password), and `--check` writes nothing and asks
 * nothing. The operator's file is only read. Stdout names the draft public id, revision number and final state, never a path.
 */
final class SoundKitImport extends Command
{
    public const PASSWORD_PROMPT = 'Staff password (hidden)';

    protected $signature = 'vasey:sound-kit-import
        {zip : Absolute path of the kit ZIP, a direct child of /tmp or of the private kit-imports directory}
        {--actor= : Email of the staff account that imports the kit}
        {--title= : Title of the new kit draft (required without --draft)}
        {--description= : Optional description of the new kit draft}
        {--provenance= : Source/provenance reference of the new kit draft (required without --draft)}
        {--draft= : Public id of an existing kit draft to append a revision to}
        {--check : Validate only and write nothing}';

    protected $description = 'Import a kit ZIP already on this host through the admin upload\'s intake (local, testing or staging only).';

    public function handle(SoundKitCliImport $import): int
    {
        try {
            return $this->importKit($import);
        } catch (Throwable $error) {
            // No message, path or trace leaves the console; the log keeps only the exception class.
            Log::warning('Kit CLI import could not confirm the result.', ['exception_class' => $error::class]);
            $this->error('Stopped: '.class_basename($error).'. Inspect the kit under Admin → Sound kit drafts before re-running.');

            return self::FAILURE;
        }
    }

    private function importKit(SoundKitCliImport $import): int
    {
        $check = (bool) $this->option('check');
        try {
            $import->admit();
            $actor = $import->actor($this->option('actor'));
            $draft = $this->option('draft') === null ? null : $import->draft($this->option('draft'));
            $metadata = $this->metadata($draft !== null);
            $source = $import->source($this->argument('zip'));
        } catch (SoundKitCliImportRefusal $refusal) {
            return $this->refuse($refusal->getMessage());
        }
        if ($check) {
            try {
                $import->inspect($source);
            } catch (MediaFailure $failure) {
                return $this->refuse('the archive does not pass the kit profile ('.$failure->failureCode.').');
            }
            $this->line('Checked: '.$source['name'].' ('.$source['size'].' bytes) passes the path rules and the wav-sample-kit-zip-v1 archive inspection.');
            $this->line($draft === null ? 'Would create a new kit draft "'.$metadata['title'].'" and upload revision 1.'
                : 'Would append revision '.((int) $draft->revisions()->max('number') + 1).' to kit draft '.$draft->public_id.' (version '.$draft->version.').');
            $this->line('Checked only. Nothing was written.');

            return self::SUCCESS;
        }
        if (! $this->input->isInteractive()) {
            return $this->refuse('Importing a kit requires an interactive session (not --no-interaction) to confirm the staff password at a hidden prompt.');
        }
        if (! $this->confirmed($actor)) {
            return $this->refuse('Staff authority was refused: the password was not confirmed or the account may not administer the catalog.');
        }
        $this->warn('Kit import: a private draft revision only. No rights, publication or price is set. Environment: '.app()->environment().'.');
        $result = $import->import($actor, $source, $draft, $metadata);
        $this->line('kit='.$result['draft']->public_id.($result['created'] ? ' (new)' : ''));
        if ($result['error'] !== null) {
            return $this->stopped($result['error'], $result['created'], $result['draft']->public_id);
        }
        $revision = $result['revision'];
        $this->line('revision='.$revision->number.' ('.$revision->public_id.')');
        $this->line('state='.$revision->status.($revision->failure_code !== null ? ' failure_code='.$revision->failure_code : '')
            .($revision->status === 'ready' ? ' members='.count($revision->manifest['members'] ?? []).' manifest_sha256='.$revision->manifest_sha256 : ''));
        if ($revision->status === 'ready') {
            $this->line('Technically verified only: this is not rights approval, publication or delivery.');

            return self::SUCCESS;
        }
        $this->error($revision->status === 'failed'
            ? 'The archive failed verification ('.$revision->failure_code.'). Upload a corrected ZIP with --draft='.$result['draft']->public_id.'.'
            : 'Verification is waiting and retryable ('.($revision->failure_code ?? 'queued').'). Retry it under Admin → Sound kit drafts → Inspect revision.');

        return self::FAILURE;
    }

    /** @return array{title?: string, description?: string, provenance?: string} */
    private function metadata(bool $existing): array
    {
        $given = array_filter(['title' => $this->option('title'), 'description' => $this->option('description'), 'provenance' => $this->option('provenance')],
            static fn (mixed $value): bool => $value !== null);
        if ($existing) {
            if ($given !== []) {
                throw new SoundKitCliImportRefusal('--title, --description and --provenance apply only to a new draft; edit an existing draft in the admin.');
            }

            return [];
        }
        foreach (['title', 'provenance'] as $required) {
            if (! is_string($given[$required] ?? null) || trim($given[$required]) === '') {
                throw new SoundKitCliImportRefusal('Give --title and --provenance for a new draft, or --draft=<public id> to append a revision.');
            }
        }

        return $given;
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

    private function stopped(Throwable $error, bool $created, string $kit): int
    {
        $hint = $created ? ' The new draft '.$kit.' remains; re-run with --draft='.$kit.' and no metadata options.' : '';
        if ($error instanceof ValidationException) {
            $this->error('Refused by the intake: '.implode(' ', $error->validator->errors()->all()).$hint);
        } elseif ($error instanceof MediaFailure) {
            $this->error('The archive could not be accepted ('.$error->failureCode.').'.$hint);
        } elseif ($error instanceof AuthorizationException) {
            $this->error('Staff authorization refused: '.($error->getMessage() ?: 'This action is unauthorized.').$hint);
        } elseif ($error instanceof SoundKitCliImportRefusal) {
            $this->error('Refused: '.$error->getMessage().$hint);
        } else {
            Log::warning('Kit CLI import stopped.', ['exception_class' => $error::class]);
            $this->error('Stopped: '.class_basename($error).'. Inspect the kit under Admin → Sound kit drafts before re-running.'.$hint);
        }

        return self::FAILURE;
    }

    private function refuse(string $message): int
    {
        $this->error('Refused: '.$message.' Nothing was written.');

        return self::FAILURE;
    }
}
