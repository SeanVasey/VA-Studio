<?php

namespace App\Domain\Staff;

use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\Environment\TestEnvironment;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Auth\Access\AuthorizationException;

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
 * explicit attestation as append-only audit evidence (who, when, which scope and action) before the write and
 * returns an immutable value that the domain service verifies against the write inside its transaction. An
 * attestation never admits a write outside the test-commerce environments.
 */
final class StaffWriteAuthority
{
    public const ATTESTATION_ACTION = 'staff.console_attestation';

    public const REFUSAL = 'Staff multi-factor authentication is required for this write.';

    /** An attestation covers the console invocation that recorded it, not a later one. */
    public const ATTESTATION_LIFETIME_SECONDS = 900;

    private const ADMIN_PANEL = 'admin';

    private function __construct(
        public readonly int $operatorId,
        public readonly string $command,
        public readonly string $action,
        public readonly string $scopeKey,
        public readonly ?int $revisionId,
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
     * The record names the operator, the moment, the command, the action, the scope key and the offer revision,
     * and whether the panel rule was required and the operator enrolled at that moment.
     */
    public static function attest(User $operator, string $command, string $action, string $scopeKey, ?int $revisionId = null): self
    {
        $current = $operator->exists ? User::find($operator->getKey()) : null;
        if ($current === null) {
            throw new AuthorizationException(self::REFUSAL);
        }
        $panel = Filament::getPanel(self::ADMIN_PANEL);
        $enrolled = self::enrolled($panel, $current);
        $at = CarbonImmutable::now()->utc()->startOfSecond();
        $record = AuditEvent::create([
            'actor_id' => $current->id, 'action' => self::ATTESTATION_ACTION,
            'subject_type' => User::class, 'subject_id' => $current->id,
            'context' => [
                'schema_version' => 1, 'authority' => 'trusted_interactive_console', 'command' => $command,
                'action' => $action, 'scope_key' => $scopeKey, 'offer_revision_id' => $revisionId,
                'attested_at' => $at->toIso8601String(), 'environment' => app()->environment(),
                'mfa_required' => $panel->isMultiFactorAuthenticationRequired(), 'mfa_enrolled' => $enrolled,
            ],
            'created_at' => $at,
        ]);

        return new self($current->id, $command, $action, $scopeKey, $revisionId, $at, $record->id);
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
     * attestation must be recorded, current and bound to this operator, action, scope key and revision.
     */
    public static function requireFor(User $current, ?self $attestation, string $action, ?string $scopeKey, ?int $revisionId = null): void
    {
        if ($attestation === null) {
            self::require($current);

            return;
        }
        if (! TestEnvironment::admitsTestCommerce() || ! $attestation->binds($current, $action, $scopeKey, $revisionId) || ! $attestation->recorded()) {
            throw new AuthorizationException(self::REFUSAL);
        }
    }

    /** Context that links a write's own audit event to the attestation that admitted it. */
    public function auditContext(): array
    {
        return ['attestation_audit_id' => $this->auditEventId];
    }

    private function binds(User $current, string $action, ?string $scopeKey, ?int $revisionId): bool
    {
        return $current->exists && (int) $current->getKey() === $this->operatorId
            && $this->action === $action
            && $scopeKey !== null && hash_equals($this->scopeKey, $scopeKey)
            && $this->revisionId === $revisionId
            && CarbonImmutable::now()->utc()->lessThanOrEqualTo($this->attestedAt->addSeconds(self::ATTESTATION_LIFETIME_SECONDS));
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

    /** The record must still exist: an attestation written inside a rolled-back transaction admits nothing. */
    private function recorded(): bool
    {
        return AuditEvent::query()->whereKey($this->auditEventId)
            ->where('action', self::ATTESTATION_ACTION)
            ->where('actor_id', $this->operatorId)
            ->where('subject_type', User::class)
            ->where('subject_id', $this->operatorId)
            ->exists();
    }
}
