<?php

namespace App\Domain\Customers\TestIdentityLinks;

use App\Domain\Customers\CustomerAccessException;
use App\Domain\Customers\CustomerAccessPolicy;
use App\Domain\Customers\CustomerIdentityCapture;
use App\Domain\Customers\CustomerIdentityPolicy;
use App\Domain\Customers\Models\CustomerIdentityChallenge;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\Audit\AuditEvent;
use App\Support\Environment\TestEnvironment;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use JsonException;

/**
 * Read-only operator relay for the test identity messages that {@see CustomerIdentityCapture}
 * writes to the private local disk, so a staging operator can hand one sign-in or recovery link to the tester who
 * asked for it without browsing the host filesystem.
 *
 * It never writes, renames, deletes, re-signs or extends a capture, a challenge or an account. Its only writes are
 * append-only audit rows: one per listing and one per relayed link (who, when, which challenge; never the link, the
 * proof or the address). A capture is shown only when every check passes: a plain private file (no symbolic link)
 * with the writer's exact shape, its link on this installation's origin, a retained test challenge with the same id,
 * purpose, policy, recipient, expiry and proof hash, and a recipient on a reserved, non-deliverable test domain that is
 * not a staff address. Anything else is withheld and only counted.
 *
 * Synthetic recipients: the schema has no per-account synthetic marker. Customer accounts exist only where
 * {@see CustomerAccessPolicy} admits them (local, testing, staging), and the private capture
 * transport sends no mail, so a tester needs no real mailbox. The relay therefore treats only the IANA-reserved names
 * of RFC 2606 and RFC 6761 as synthetic, as the repository's own test fixtures already do (`@example.test`,
 * `@example.invalid`). A link for any other address, which could be a real person's, is never relayed.
 */
final class TestIdentityLinkRelay
{
    public const COMMAND = 'vasey:test-identity-links';

    public const LISTED = 'customer.test_identity.links_listed';

    public const RELAYED = 'customer.test_identity.link_relayed';

    /** Reserved top-level names (RFC 2606 section 2, RFC 6761 section 6). */
    public const RESERVED_TOP_LEVEL = ['test', 'example', 'invalid', 'localhost'];

    /** Reserved second-level example domains (RFC 2606 section 3). */
    public const RESERVED_DOMAINS = ['example.com', 'example.net', 'example.org'];

    /** The writer's directory on the private local disk. Never printed. */
    private const DIRECTORY = 'customer-identity-capture';

    /** A real capture is well under 1 KiB; anything larger is not one. */
    private const MAX_BYTES = 8192;

    private const UUID = '[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}';

    /** The writer's exact keys, sorted. */
    private const KEYS = ['expiresAt', 'id', 'purpose', 'subject', 'testOnly', 'to', 'url'];

    /**
     * Environment and capability, before anything is read. Production is refused first, whether it is the configured
     * environment (`config('app.env')`, which a cached configuration keeps) or the running one; Artisan's `--env`
     * changes only the running environment, so the two must also agree. The capture capability is the existing
     * {@see CustomerIdentityPolicy}, unchanged: when it is off, nothing is captured and nothing may be relayed.
     */
    public function admit(): void
    {
        $configured = config('app.env');
        if ($configured === 'production' || app()->environment('production') || app()->isProduction()) {
            $this->refuse('The test identity link relay is refused in production.');
        }
        if (! is_string($configured) || $configured !== app()->environment()) {
            $this->refuse('The configured environment (APP_ENV) and the running environment differ, for example through --env; '
                .'the relay refuses an ambiguous environment.');
        }
        if (! TestEnvironment::admitsTestCommerce()) {
            $this->refuse('The test identity link relay runs only when APP_ENV is local, testing or staging.');
        }
        if (! app(CustomerIdentityPolicy::class)->enabled()) {
            $this->refuse('Test identity capture is not enabled. It needs VASEY_TEST_CUSTOMER_ACCOUNTS_ENABLED=true, '
                .'VASEY_TEST_CUSTOMER_IDENTITY_ENABLED=true and VASEY_TEST_CUSTOMER_IDENTITY_TRANSPORT=private_capture.');
        }
        if (config('filesystems.disks.local.driver') !== 'local' || config('filesystems.disks.local.visibility', 'private') !== 'private') {
            $this->refuse('Test identity captures are read only from the private local disk.');
        }
    }

    /** The named staff account: an administrator with a verified email who satisfies the admin panel's MFA rule here. */
    public function operator(mixed $email): User
    {
        if (! is_string($email) || $email === '') {
            $this->refuse('Name the staff account relaying links with --operator=EMAIL.');
        }
        $user = User::query()->where('email', $email)->first();
        if ($user === null) {
            $this->refuse('No staff account has the operator email. Create it first with `php artisan vasey:create-admin`.');
        }
        if (! Gate::forUser($user)->allows('administer-catalog')) {
            $this->refuse('The operator account lacks staff authority (an administrator with a verified email).');
        }
        if (! AdminMultiFactor::satisfiedBy($user)) {
            $this->refuse("The operator account does not satisfy the admin panel's MFA rule here. "
                .'Sign in at /admin as that account and enroll the authenticator app first.');
        }

        return $user;
    }

    /** Operator-facing UTC time. */
    public static function time(CarbonImmutable $time): string
    {
        return $time->utc()->format('Y-m-d H:i:s');
    }

    public static function isMessageId(mixed $id): bool
    {
        return is_string($id) && preg_match('/\A'.self::UUID.'\z/D', $id) === 1;
    }

    /** True only for a lowercase address on a reserved, non-deliverable test name. */
    public static function syntheticAddress(string $email): bool
    {
        $at = strrpos($email, '@');
        $domain = $at === false ? '' : substr($email, $at + 1);
        if ($domain === '' || $domain !== strtolower($domain)) {
            return false;
        }
        $labels = explode('.', $domain);
        if (count($labels) >= 2 && in_array($labels[array_key_last($labels)], self::RESERVED_TOP_LEVEL, true)) {
            return true;
        }
        foreach (self::RESERVED_DOMAINS as $reserved) {
            if ($domain === $reserved || str_ends_with($domain, '.'.$reserved)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every verified capture for a synthetic recipient, newest first, and how many entries were withheld.
     *
     * @return array{messages: list<TestIdentityLinkMessage>, withheld: int}
     */
    public function messages(): array
    {
        $origin = $this->origin();
        $directory = $this->directory();
        if ($directory === null) {
            return ['messages' => [], 'withheld' => 0];
        }
        $names = @scandir($directory);
        if ($names === false) {
            $this->refuse('The private capture directory could not be read.');
        }
        $messages = [];
        $withheld = 0;
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $message = preg_match('/\A('.self::UUID.')\.json\z/D', $name, $match) === 1 ? $this->verified($directory, $match[1], $origin) : null;
            if ($message === null) {
                $withheld++;

                continue;
            }
            $messages[] = $message;
        }
        usort($messages, fn (TestIdentityLinkMessage $a, TestIdentityLinkMessage $b): int => [$b->createdAt->getTimestamp(), $b->id] <=> [$a->createdAt->getTimestamp(), $a->id]);

        return ['messages' => $messages, 'withheld' => $withheld];
    }

    /** The one verified capture with this id; a missing or withheld capture refuses alike. */
    public function message(string $id): TestIdentityLinkMessage
    {
        if (! self::isMessageId($id)) {
            $this->refuse('The message id must be the lowercase UUID that the list prints.');
        }
        $origin = $this->origin();
        $directory = $this->directory();
        $message = $directory === null ? null : $this->verified($directory, $id, $origin);
        if ($message === null) {
            $this->refuse('No relayable capture has that id. It does not exist, or it is withheld because it is not a verified message for a synthetic test address.');
        }

        return $message;
    }

    /**
     * Records that the operator relayed this pending message, then returns its link. Used and expired messages are
     * refused. The audit row is committed before the link is returned, so an unrecorded relay prints nothing.
     *
     * @return array{link: string, audit_event: int}
     */
    public function relay(User $operator, TestIdentityLinkMessage $message, ?int $attestationAuditId): array
    {
        $this->requireRelayable($message->status, $message->expiresAt, $message->completedAt);
        $auditId = DB::transaction(function () use ($operator, $message, $attestationAuditId): int {
            $current = $this->currentOperator($operator);
            // The retained challenge is read again, so a completion or expiry since the capture was checked refuses.
            $challenge = CustomerIdentityChallenge::query()->whereKey($message->challengeKey)->first();
            if ($challenge === null || $challenge->public_id !== $message->id) {
                $this->refuse('That message changed while it was being checked. List again.');
            }
            $this->requireRelayable($this->status($challenge) ?? 'unavailable', $challenge->expires_at, $challenge->completed_at);

            return AuditEvent::create([
                'actor_id' => $current->id, 'action' => self::RELAYED,
                'subject_type' => CustomerIdentityChallenge::class, 'subject_id' => $challenge->id,
                'context' => [
                    'schema_version' => 1, 'command' => self::COMMAND, 'challenge_id' => $challenge->public_id,
                    'purpose' => $challenge->purpose, 'policy_version' => $challenge->policy_version,
                    'account_id' => $challenge->account_id, 'expires_at' => $challenge->expires_at->toIso8601String(),
                    'test_only' => true, 'environment' => app()->environment(), 'attestation_audit_id' => $attestationAuditId,
                ],
                'created_at' => CarbonImmutable::now()->utc()->startOfSecond(),
            ])->id;
        });

        return ['link' => $message->link(), 'audit_event' => $auditId];
    }

    /**
     * Records the listing before it is shown (counts only, never addresses), as staff reads of private inquiries are
     * audited. A failed record shows nothing.
     *
     * @param  array{messages: list<TestIdentityLinkMessage>, withheld: int}  $result
     */
    public function recordListing(User $operator, array $result, ?int $attestationAuditId): int
    {
        return DB::transaction(function () use ($operator, $result, $attestationAuditId): int {
            $current = $this->currentOperator($operator);
            $pending = count(array_filter($result['messages'], fn (TestIdentityLinkMessage $message): bool => $message->status === TestIdentityLinkMessage::PENDING));

            return AuditEvent::create([
                'actor_id' => $current->id, 'action' => self::LISTED,
                'subject_type' => User::class, 'subject_id' => $current->id,
                'context' => [
                    'schema_version' => 1, 'command' => self::COMMAND, 'shown' => count($result['messages']),
                    'pending' => $pending, 'withheld' => $result['withheld'], 'test_only' => true,
                    'environment' => app()->environment(), 'attestation_audit_id' => $attestationAuditId,
                ],
                'created_at' => CarbonImmutable::now()->utc()->startOfSecond(),
            ])->id;
        });
    }

    private function requireRelayable(string $status, CarbonImmutable $expiresAt, ?CarbonImmutable $completedAt): void
    {
        if ($status === TestIdentityLinkMessage::USED) {
            $this->refuse('That link was already used'.($completedAt ? ' at '.self::time($completedAt).' UTC' : '')
                .'. It works only once; the tester requests a new one from the sign-in page if needed.');
        }
        if ($status === TestIdentityLinkMessage::EXPIRED || $expiresAt->lessThanOrEqualTo(now())) {
            $this->refuse('That link expired at '.self::time($expiresAt).' UTC. The tester requests a new one from the sign-in page.');
        }
        if ($status !== TestIdentityLinkMessage::PENDING) {
            $this->refuse('That message is no longer pending. List again.');
        }
    }

    /** Current authority under a lock, as the inquiry administration reads it, so a withdrawn operator records nothing. */
    private function currentOperator(User $operator): User
    {
        $current = User::query()->lockForUpdate()->find($operator->getKey());
        if ($current === null || ! Gate::forUser($current)->allows('administer-catalog', [true])
            || ! AdminMultiFactor::satisfiedBy($current, lockForUpdate: true)) {
            $this->refuse('Operator authority was refused.');
        }

        return $current;
    }

    /** The capture directory's absolute path, or null when none exists yet. A linked or non-directory entry refuses. */
    private function directory(): ?string
    {
        $path = rtrim(Storage::disk('local')->path(self::DIRECTORY), '/');
        $stat = @lstat($path);
        if ($stat === false) {
            return null;
        }
        if (($stat['mode'] & 0170000) !== 0040000) {
            $this->refuse('The private capture directory is a symbolic link or not a directory. Captures are read only from a plain private directory.');
        }

        return $path;
    }

    /** The writer's own origin rule, so only links for this installation can match. */
    private function origin(): string
    {
        $origin = rtrim((string) config('app.url'), '/');
        $parts = parse_url($origin);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || ! isset($parts['host'])) {
            $this->refuse('APP_URL is not a plain http(s) origin, so no captured link can be matched to this installation.');
        }

        return $origin;
    }

    private function verified(string $directory, string $id, string $origin): ?TestIdentityLinkMessage
    {
        $data = $this->read($directory.'/'.$id.'.json');
        if ($data === null) {
            return null;
        }
        $keys = array_keys($data);
        sort($keys);
        if ($keys !== self::KEYS || $data['testOnly'] !== true || $data['id'] !== $id || ! in_array($data['purpose'], ['enroll', 'recover'], true)
            || ! is_string($data['to']) || ! is_string($data['subject']) || strlen($data['subject']) > 200
            || ! is_string($data['url']) || ! is_string($data['expiresAt'])) {
            return null;
        }
        $recipient = $this->recipient($data['to']);
        $prefix = $origin.'/account/access#'.$data['purpose'].'.'.$id.'.';
        $proof = substr($data['url'], strlen($prefix));
        if ($recipient === null || ! str_starts_with($data['url'], $prefix) || preg_match('/\A[a-f0-9]{64}\z/D', $proof) !== 1) {
            return null;
        }
        $challenge = CustomerIdentityChallenge::query()->where('public_id', $id)->first();
        if ($challenge === null || $challenge->public_id !== $id || $challenge->policy_version !== CustomerIdentityPolicy::VERSION
            || $challenge->purpose !== $data['purpose'] || ! hash_equals($challenge->proof_hash, hash('sha256', $proof))
            || $challenge->expires_at->toISOString() !== $data['expiresAt']) {
            return null;
        }
        try {
            $email = $challenge->email;
        } catch (DecryptException) {
            return null;
        }
        $status = $this->status($challenge);
        if (! is_string($email) || ! hash_equals($email, $recipient) || $status === null
            || User::query()->whereRaw('LOWER(email) = ?', [$recipient])->where('is_admin', true)->exists()) {
            return null;
        }

        return new TestIdentityLinkMessage($challenge->id, $id, $challenge->purpose, $recipient, $challenge->created_at,
            $challenge->expires_at, $challenge->completed_at, $status, $data['url']);
    }

    /** The writer stores a normalized address; only an exact normalized synthetic address qualifies. */
    private function recipient(string $to): ?string
    {
        try {
            $normalized = CustomerIdentityPolicy::email($to);
        } catch (CustomerAccessException) {
            return null;
        }

        return $normalized === $to && self::syntheticAddress($to) ? $to : null;
    }

    /** Pending, expired or used; null for any other state, which never has a capture. */
    private function status(CustomerIdentityChallenge $challenge): ?string
    {
        return match ($challenge->state) {
            'completed' => TestIdentityLinkMessage::USED,
            'pending' => $challenge->expires_at->lessThanOrEqualTo(now()) ? TestIdentityLinkMessage::EXPIRED : TestIdentityLinkMessage::PENDING,
            default => null,
        };
    }

    /**
     * Opens only a regular file that is not a symbolic link, and confirms that the opened handle is that same file
     * (the private media reader's lstat/fstat pattern), so a swapped link cannot redirect the read. The bytes are
     * decoded as one flat JSON object and are never printed.
     */
    private function read(string $path): ?array
    {
        $before = @lstat($path);
        if ($before === false || ($before['mode'] & 0170000) !== 0100000 || $before['size'] > self::MAX_BYTES) {
            return null;
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        try {
            $opened = fstat($handle);
            if ($opened === false || $opened['ino'] !== $before['ino'] || $opened['dev'] !== $before['dev'] || ($opened['mode'] & 0170000) !== 0100000) {
                return null;
            }
            $raw = stream_get_contents($handle, self::MAX_BYTES + 1);
        } finally {
            fclose($handle);
        }
        if (! is_string($raw) || strlen($raw) > self::MAX_BYTES) {
            return null;
        }
        try {
            $data = json_decode($raw, true, 2, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    private function refuse(string $message): never
    {
        throw new TestIdentityLinkRefusal($message);
    }
}
