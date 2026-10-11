<?php

namespace App\Domain\Staff;

use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\Environment\TestEnvironment;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Server-side staff authority for domain writes that the admin panel's route middleware would otherwise be the
 * only guard for (#68 review condition C2).
 *
 * The panel challenges the TOTP code inside its login page and, where its rule is required, forces enrollment
 * before any page; it records no further per-request or per-session flag. The state a verified panel session
 * leaves behind is therefore enrollment under the panel's required rule: `EnsureMultiFactorAuthenticationIsEnabled`
 * checks exactly that on every panel request, and `AdminMultiFactor` evaluates it (with its own re-read) for
 * staff-attributed domain actions. `require()` applies the same panel rule to the row the calling service has
 * already read under its own lock: required in staging and production (`TestEnvironment::requiresStaffMfa()`
 * drives the panel rule), optional in local and testing, with no environment variable of its own.
 *
 * A trusted interactive console has no challenge. `attest()` is its only alternative: it records the operator's
 * explicit attestation as append-only audit evidence (who, when, which scope, action and requested operation)
 * before the write and returns an immutable value that the domain service verifies against the write inside its
 * transaction. An attestation admits one write: the first admitted write appends a consumption record in its own
 * transaction, and every later use is refused. An attestation never admits a write outside the test-commerce
 * environments.
 */
final class StaffWriteAuthority
{
    public const ATTESTATION_ACTION = 'staff.console_attestation';

    /** Append-only record of the one write an attestation admitted; its subject is the attestation's audit row. */
    public const CONSUMPTION_ACTION = 'staff.console_attestation_consumed';

    public const REFUSAL = 'Staff multi-factor authentication is required for this write.';

    public const CONSOLE_ONLY = 'A staff attestation can only be recorded from the console.';

    /** An attestation covers the console invocation that recorded it, not a later one. */
    public const ATTESTATION_LIFETIME_SECONDS = 900;

    private const ADMIN_PANEL = 'admin';

    private function __construct(
        public readonly int $operatorId,
        public readonly string $command,
        public readonly string $action,
        public readonly string $scopeKey,
        public readonly ?int $revisionId,
        private readonly string $referenceHmac,
        public readonly ?bool $blocked,
        public readonly ?int $expectedVersion,
        public readonly CarbonImmutable $attestedAt,
        public readonly int $auditEventId,
    ) {}

    /** True where the admin panel requires the staff factor, so a console write needs an attestation. */
    public static function attestationRequired(): bool
    {
        return Filament::getPanel(self::ADMIN_PANEL)->isMultiFactorAuthenticationRequired();
    }

    /**
     * Records a console operator's explicit attestation and returns it. Call this after the operator's password
     * has been confirmed and outside the write transaction, so a refused write still leaves the attempt on record.
     * The record names the operator, the moment, the command, the action, the scope key, the offer revision, the
     * requested blocked state and expected control version (block only), a keyed hash of the evidence reference
     * (never the reference itself), and whether the panel rule was required and the operator enrolled.
     *
     * Only a console process may record one: an HTTP request has the panel and its challenge, so an attestation
     * there could only bypass the panel rule. Queue workers and the scheduler also run in the console; the
     * attestation records who made the write and is not a second factor (see the lane's threat note).
     */
    public static function attest(User $operator, string $command, string $action, string $scopeKey, ?int $revisionId,
        string $reference, ?bool $blocked = null, ?int $expectedVersion = null): self
    {
        if (! app()->runningInConsole()) {
            throw new AuthorizationException(self::CONSOLE_ONLY);
        }
        $current = $operator->exists ? User::find($operator->getKey()) : null;
        if ($current === null) {
            throw new AuthorizationException(self::REFUSAL);
        }
        $panel = Filament::getPanel(self::ADMIN_PANEL);
        $enrolled = self::enrolled($panel, $current);
        $at = CarbonImmutable::now()->utc()->startOfSecond();
        $referenceHmac = self::referenceHmac($reference);
        $record = AuditEvent::create([
            'actor_id' => $current->id, 'action' => self::ATTESTATION_ACTION,
            'subject_type' => User::class, 'subject_id' => $current->id,
            'context' => [
                'schema_version' => 2, 'authority' => 'trusted_interactive_console', 'command' => $command,
                'action' => $action, 'scope_key' => $scopeKey, 'offer_revision_id' => $revisionId,
                'reference_hmac' => $referenceHmac, 'blocked' => $blocked, 'expected_version' => $expectedVersion,
                'attested_at' => $at->toIso8601String(), 'environment' => app()->environment(),
                'mfa_required' => $panel->isMultiFactorAuthenticationRequired(), 'mfa_enrolled' => $enrolled,
            ],
            'created_at' => $at,
        ]);

        return new self($current->id, $command, $action, $scopeKey, $revisionId, $referenceHmac, $blocked, $expectedVersion, $at, $record->id);
    }

    /**
     * Panel-session rule: the persisted staff row must satisfy the admin panel's multi-factor rule, exactly as
     * `EnsureMultiFactorAuthenticationIsEnabled` checks it on every panel request (rule required by the panel; an
     * enabled provider on the row). `$current` is the row the calling service read moments ago inside its own
     * transaction, under its own lock where it writes: a locking read returns the committed row and holds it until
     * that transaction ends, so no further read is made here. A locked re-read would add a third `users` lock to
     * the rights-scope writer's audited lock sequence, and a non-locking one could return an older REPEATABLE READ
     * snapshot in which a withdrawn enrollment still appears.
     */
    public static function require(User $current): void
    {
        $panel = Filament::getPanel(self::ADMIN_PANEL);
        if (! $current->exists || ($panel->isMultiFactorAuthenticationRequired() && ! self::enrolled($panel, $current))) {
            throw new AuthorizationException(self::REFUSAL);
        }
    }

    /**
     * A write that a console may reach: without an attestation the panel-session rule applies; with one, the
     * attestation must be recorded, current, bound to this operator, action, scope key, revision, reference,
     * requested blocked state and expected version, and not yet used.
     *
     * Call it inside the write's transaction after locking `$current` FOR UPDATE: admission appends the
     * consumption record in that transaction, so it commits or rolls back with the write. Every use of one
     * attestation locks the same operator row first (the binding requires it), which serializes the
     * already-used check against a concurrent use. A write refused after admission rolls the record back, so the
     * attestation may then retry only the identical operation it binds.
     */
    public static function requireFor(User $current, ?self $attestation, string $action, ?string $scopeKey, ?int $revisionId,
        string $reference, ?bool $blocked = null, ?int $expectedVersion = null): void
    {
        if ($attestation === null) {
            self::require($current);

            return;
        }
        if (! TestEnvironment::admitsTestCommerce() || DB::transactionLevel() === 0
            || ! $attestation->binds($current, $action, $scopeKey, $revisionId, $reference, $blocked, $expectedVersion)
            || ! $attestation->recorded() || ! $attestation->consume()) {
            throw new AuthorizationException(self::REFUSAL);
        }
    }

    /** Context that links a write's own audit event to the attestation that admitted it. */
    public function auditContext(): array
    {
        return ['attestation_audit_id' => $this->auditEventId];
    }

    private function binds(User $current, string $action, ?string $scopeKey, ?int $revisionId, string $reference, ?bool $blocked, ?int $expectedVersion): bool
    {
        return $current->exists && (int) $current->getKey() === $this->operatorId
            && $this->action === $action
            && $scopeKey !== null && hash_equals($this->scopeKey, $scopeKey)
            && $this->revisionId === $revisionId
            && hash_equals($this->referenceHmac, self::referenceHmac($reference))
            && $this->blocked === $blocked
            && $this->expectedVersion === $expectedVersion
            && CarbonImmutable::now()->utc()->lessThanOrEqualTo($this->attestedAt->addSeconds(self::ATTESTATION_LIFETIME_SECONDS));
    }

    /**
     * Keyed, so the audit row does not expose a guessable evidence reference to anyone who can read audit
     * history; the attestation lives 900 seconds, well inside any application key rotation.
     */
    private static function referenceHmac(string $reference): string
    {
        return hash_hmac('sha256', $reference, (string) config('app.key'));
    }

    /** The panel's own enrollment test: any configured provider enabled on this row. */
    private static function enrolled(Panel $panel, User $user): bool
    {
        foreach ($panel->getMultiFactorAuthenticationProviders() as $provider) {
            if ($provider->isEnabled($user)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The record must still exist and say what this value says: an attestation written inside a rolled-back
     * transaction admits nothing, and neither does one whose record names another action, scope key, revision,
     * reference, blocked state or expected version.
     */
    private function recorded(): bool
    {
        $record = AuditEvent::query()->whereKey($this->auditEventId)
            ->where('action', self::ATTESTATION_ACTION)
            ->where('actor_id', $this->operatorId)
            ->where('subject_type', User::class)
            ->where('subject_id', $this->operatorId)
            ->first();
        $context = $record?->context;
        if (! is_array($context) || ! is_string($context['action'] ?? null) || ! is_string($context['scope_key'] ?? null)
            || ! is_string($context['reference_hmac'] ?? null)) {
            return false;
        }

        return $context['action'] === $this->action
            && hash_equals($this->scopeKey, $context['scope_key'])
            && ($context['offer_revision_id'] ?? null) === $this->revisionId
            && hash_equals($this->referenceHmac, $context['reference_hmac'])
            && ($context['blocked'] ?? null) === $this->blocked
            && ($context['expected_version'] ?? null) === $this->expectedVersion;
    }

    /**
     * Spends the attestation on the write that is being admitted: refused when a consumption record already
     * exists, otherwise appends one in the caller's transaction. Audit rows are append-only, so the marker is a
     * new row (subject: the attestation's own row) rather than a change to the attestation. The query is narrowed
     * by the operator, whose row the caller holds FOR UPDATE.
     */
    private function consume(): bool
    {
        $used = AuditEvent::query()->where('actor_id', $this->operatorId)
            ->where('action', self::CONSUMPTION_ACTION)
            ->where('subject_type', AuditEvent::class)
            ->where('subject_id', $this->auditEventId)
            ->exists();
        if ($used) {
            return false;
        }
        AuditEvent::create([
            'actor_id' => $this->operatorId, 'action' => self::CONSUMPTION_ACTION,
            'subject_type' => AuditEvent::class, 'subject_id' => $this->auditEventId,
            'context' => ['schema_version' => 1, 'action' => $this->action, 'scope_key' => $this->scopeKey,
                'offer_revision_id' => $this->revisionId, 'environment' => app()->environment()],
            'created_at' => CarbonImmutable::now()->utc()->startOfSecond(),
        ]);

        return true;
    }
}
