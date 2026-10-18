<?php

namespace App\Console\Commands;

use App\Domain\Customers\TestIdentityLinks\TestIdentityLinkMessage;
use App\Domain\Customers\TestIdentityLinks\TestIdentityLinkRefusal;
use App\Domain\Customers\TestIdentityLinks\TestIdentityLinkRelay;
use App\Domain\Staff\StaffWriteAuthority;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Lets a staging operator list captured test identity messages for synthetic test addresses and print one pending
 * sign-in or recovery link to relay to the tester who requested it. Read-only: it never consumes, extends, re-signs or
 * changes a link, a challenge or an account. Every rule (environment, capture capability, verification, synthetic
 * recipients, audit) is in {@see TestIdentityLinkRelay}; this command confirms the named staff account at a hidden
 * prompt, records a console attestation where the panel requires the staff factor, and maps outcomes.
 */
final class TestIdentityLinks extends Command
{
    public const PASSWORD_PROMPT = 'Staff password (hidden)';

    protected $signature = 'vasey:test-identity-links
        {action : list or print}
        {id? : The message id that list printed (print only)}
        {--operator= : Email of the staff account relaying links; its password is confirmed at a hidden prompt}
        {--attest : Record a console attestation in the audit log before any capture is read (required where staff MFA is required)}';

    protected $description = 'List captured test identity messages for synthetic test addresses and print one pending link to relay (local, testing or staging only).';

    /** The audit row of the attestation this invocation recorded, so a later refusal does not claim nothing was recorded. */
    private ?int $attestationAuditId = null;

    public function handle(TestIdentityLinkRelay $relay): int
    {
        try {
            return $this->relayLinks($relay);
        } catch (Throwable $error) {
            // No message, path, link or trace leaves the console; the log keeps only the exception class.
            Log::warning('Test identity link relay could not complete.', ['exception_class' => $error::class]);
            $this->error('Stopped: the test identity link relay is unavailable and printed no link.'.$this->attestationNote());

            return self::FAILURE;
        }
    }

    private function relayLinks(TestIdentityLinkRelay $relay): int
    {
        $action = $this->argument('action');
        $id = $this->argument('id');
        if (! in_array($action, ['list', 'print'], true)) {
            return $this->invalid('the action must be list or print.');
        }
        if ($action === 'list' && $id !== null) {
            return $this->invalid('list takes no message id.');
        }
        if ($action === 'print' && ! TestIdentityLinkRelay::isMessageId($id)) {
            return $this->invalid('print needs the message id that list printed (a lowercase UUID).');
        }
        try {
            $relay->admit();
            $operator = $relay->operator($this->option('operator'));
        } catch (TestIdentityLinkRefusal $refusal) {
            return $this->refuse($refusal->getMessage());
        }
        if (! $this->input->isInteractive()) {
            return $this->refuse('The relay requires an interactive trusted console to confirm the staff password.');
        }
        $operator = $this->confirmed($operator);
        if ($operator === null) {
            return $this->refuse('Operator authority was refused: the staff password was not confirmed.');
        }
        if (! $this->option('attest') && StaffWriteAuthority::attestationRequired()) {
            return $this->refuse('This environment requires staff multi-factor authentication and a console has no factor challenge. '
                .'Re-run with --attest to record a console attestation in the audit log before any capture is read.');
        }
        if ($this->option('attest')) {
            $this->attest($operator, $action, $id);
        }
        try {
            return $action === 'list' ? $this->list($relay, $operator) : $this->print($relay, $operator, $id);
        } catch (TestIdentityLinkRefusal $refusal) {
            return $this->refuse($refusal->getMessage());
        }
    }

    /** As `vasey:rights-scope` confirms its operator: hidden input with no visible fallback, current hash and authority. */
    private function confirmed(User $operator): ?User
    {
        $password = $this->secret(self::PASSWORD_PROMPT, false);
        $current = User::query()->find($operator->getKey());
        $valid = $current !== null && is_string($password) && $password !== '' && Hash::check($password, $current->getAuthPassword());
        unset($password);

        return $valid && Gate::forUser($current)->allows('administer-catalog') ? $current : null;
    }

    /**
     * One append-only `staff.console_attestation` (who, when, this command, list or print, the message id for print),
     * recorded after the password is confirmed and before any capture is read. It is audit evidence only: no writer
     * consumes it, as with `vasey:staging-synthetic-catalog`.
     */
    private function attest(User $operator, string $action, ?string $id): void
    {
        $attestation = StaffWriteAuthority::attest($operator, (string) $this->getName(), 'test_identity_links.'.$action,
            $id === null ? 'test-identity-messages' : 'test-identity-message:'.$id, null, 'test-identity-links-run:'.Str::uuid());
        $this->attestationAuditId = $attestation->auditEventId;
        $this->line('attestation=recorded audit_event='.$attestation->auditEventId);
    }

    private function list(TestIdentityLinkRelay $relay, User $operator): int
    {
        $result = $relay->messages();
        $audit = $relay->recordListing($operator, $result, $this->attestationAuditId);
        $this->warn('TEST ONLY. Environment: '.app()->environment().'. Captured test identity messages for synthetic test addresses, newest first.');
        if ($result['messages'] === []) {
            $this->line('No captured message for a synthetic test address.');
        } else {
            $this->table(['Message id', 'Recipient', 'Kind', 'Created (UTC)', 'Expires (UTC)', 'Status'],
                array_map(fn (TestIdentityLinkMessage $message): array => [$message->id, $message->recipient, $this->kind($message),
                    TestIdentityLinkRelay::time($message->createdAt), TestIdentityLinkRelay::time($message->expiresAt), $message->status], $result['messages']));
        }
        if ($result['withheld'] > 0) {
            $this->line($result['withheld'].' capture(s) withheld: not a verified message for a synthetic test address '
                .'(.test, .example, .invalid, .localhost, example.com, example.net or example.org). Nothing from them is shown.');
        }
        $this->line('listing audit_event='.$audit);
        $this->line('To relay one pending link: php artisan vasey:test-identity-links print MESSAGE_ID --operator=EMAIL'.($this->option('attest') ? ' --attest' : ''));

        return self::SUCCESS;
    }

    private function print(TestIdentityLinkRelay $relay, User $operator, string $id): int
    {
        $message = $relay->message($id);
        $relayed = $relay->relay($operator, $message, $this->attestationAuditId);
        $this->warn('TEST ONLY. Relay this '.$this->kind($message).' link only to the tester who requested it for '.$message->recipient
            .'. It works once and expires at '.TestIdentityLinkRelay::time($message->expiresAt).' UTC.');
        $this->line($relayed['link']);
        $this->line('relay audit_event='.$relayed['audit_event']);

        return self::SUCCESS;
    }

    private function kind(TestIdentityLinkMessage $message): string
    {
        return $message->purpose === 'enroll' ? 'create account' : 'reset password';
    }

    private function invalid(string $message): int
    {
        $this->error('Invalid input: '.$message.' Nothing was read.');

        return self::INVALID;
    }

    private function refuse(string $message): int
    {
        $this->error('Refused: '.$message.($this->attestationAuditId === null ? ' No link was printed and nothing was recorded.'
            : ' No link was printed; the attestation audit record (audit_event='.$this->attestationAuditId.') remains.'));

        return self::FAILURE;
    }

    private function attestationNote(): string
    {
        return $this->attestationAuditId === null ? ''
            : ' The attestation audit record (audit_event='.$this->attestationAuditId.') remains.';
    }
}
