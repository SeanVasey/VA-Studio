<?php

namespace App\Domain\Customers\ProductionIdentity\Notifications;

use App\Domain\Customers\ProductionIdentity\IdentityDatabase;
use App\Domain\Customers\ProductionIdentity\IdentityEvidence;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Throwable;

/** Append-only claim/outcome recovery. Unknown submission is terminal and is never automatically retried. */
final class WorkIdentityNotice
{
    public const LEASE_SECONDS = 120;

    public function process(int $noticeId): void
    {
        $policy = new IdentityPolicy;
        if (! $policy->enabled() || config('production-customer-identity.notifications_enabled', false) !== true
            || ! app()->bound(IdentityNoticeTransport::class)) {
            return;
        }
        $policy->outsideTransactions();
        $database = new IdentityDatabase;
        $database->close(false);
        $transport = app(IdentityNoticeTransport::class); // Resolve all adapter/container hooks before terminal proof.
        $scope = $transport->provenance();
        $capability = $transport->capabilityVersion();
        if ($scope !== $policy->provenance() || $capability !== config('production-customer-identity.transport_capability')) {
            return;
        }
        $selectorStatement = $database->primary->prepare('SELECT * FROM '.$database->rows->table('production_identity_notices').' WHERE id=?');
        $selectorStatement->execute([$noticeId]);
        $selectedNotice = $selectorStatement->fetch(\PDO::FETCH_ASSOC);
        if (! is_array($selectedNotice)) {
            return;
        }
        IdentityEvidence::verify('notice', $selectedNotice, 'notice_hash');
        $selectorStatement = $database->primary->prepare('SELECT * FROM '.$database->rows->table('production_identity_challenges').' WHERE id=?');
        $selectorStatement->execute([(int) $selectedNotice['challenge_id']]);
        $selectedChallenge = $selectorStatement->fetch(\PDO::FETCH_ASSOC);
        if (! is_array($selectedChallenge)) {
            return;
        }
        IdentityEvidence::verify('challenge', $selectedChallenge, 'challenge_hash');
        $payload = Crypt::decryptString($selectedChallenge['payload_ciphertext']);
        if (! IdentityPolicy::matches('payload', $payload, $selectedChallenge['payload_hash'])) {
            throw new IdentityException;
        }
        $decoded = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
        if (($decoded['schema_version'] ?? null) !== 1 || ($decoded['purpose'] ?? null) !== $selectedChallenge['purpose']) {
            throw new IdentityException;
        }
        $email = IdentityPolicy::email($decoded['recipient'] ?? '');
        if (! IdentityPolicy::matches('recipient', $email, $selectedChallenge['recipient_hmac'])) {
            throw new IdentityException;
        }
        $mail = new IdentityMail($selectedNotice['public_id'], $selectedChallenge['purpose'], $scope, $email, $decoded['url'] ?? '');
        $token = bin2hex(random_bytes(32));
        $now = $this->now();
        $claim = $database->connection->transaction(function () use ($database, $policy, $noticeId, $now, $token, $selectedNotice, $selectedChallenge, $email): ?array {
            $database->close(true);
            // Selectors above held no locks; lock order is address -> user -> account -> origin -> challenge -> notice.
            $proof = $this->authority($database, $selectedNotice, $selectedChallenge, $email, $now, false);
            $notice = $database->rows->one('production_identity_notices', $noticeId);
            $database->same($selectedNotice, $notice);
            $attempts = $database->rows->rows('production_identity_attempts', 'notice_id = ?', [$noticeId], 4);
            if ($attempts !== []) {
                $last = $attempts[array_key_last($attempts)];
                $outcomes = $database->rows->rows('production_identity_outcomes', 'attempt_id = ?', [(int) $last['id']], 2);
                if ($outcomes === []) {
                    if ($now >= $last['lease_expires_at']) {
                        $this->outcome($database, $last, 'unknown', 'lease_expired', IdentityEvidence::EMPTY_HASH, $now);
                    }

                    return null;
                }
                if (count($outcomes) !== 1 || $outcomes[0]['status'] !== 'definitely_not_submitted' || $now < $outcomes[0]['next_attempt_at'] || count($attempts) >= 3) {
                    return null;
                }
            }
            $this->authority($database, $notice, $selectedChallenge, $email, $now, true);
            $policy->requireEnabled();
            if (config('production-customer-identity.notifications_enabled', false) !== true) {
                return null;
            }
            $attempt = ['public_id' => (string) Str::uuid(), 'notice_id' => $noticeId, 'number' => count($attempts) + 1,
                'token_hash' => IdentityPolicy::digest('lease', $token), 'notice_hash' => $notice['notice_hash'],
                'started_at' => $now, 'lease_expires_at' => gmdate('Y-m-d H:i:s', strtotime($now.' UTC') + self::LEASE_SECONDS)];
            $attempt['id'] = $database->insert('production_identity_attempts', $attempt);
            $database->close(true);

            return ['attempt' => $attempt, 'notice' => $notice, 'authority' => $proof];
        });
        if ($claim === null) {
            return;
        }
        $attempt = $claim['attempt'];
        try {
            // Every application/crypto/adapter hook above is closed by direct reads against the captured writer.
            $database->close(false);
            $terminalClockStarted = hrtime(true);
            $terminalNow = $this->now();
            $database->same($claim['authority'], $this->authority($database, $claim['notice'], $selectedChallenge, $email, $terminalNow, true));
            $database->same($claim['notice'], $database->rows->one('production_identity_notices', $noticeId));
            $database->same($attempt, $database->rows->one('production_identity_attempts', (int) $attempt['id']));
            if ($database->rows->rows('production_identity_outcomes', 'attempt_id = ?', [(int) $attempt['id']], 1) !== []
                || ! IdentityPolicy::matches('lease', $token, $attempt['token_hash']) || $terminalNow >= $attempt['lease_expires_at']) {
                throw new IdentityException;
            }
            $database->close(false);
            $policy->requireEnabled();
            if ($scope !== $policy->provenance() || config('production-customer-identity.notifications_enabled', false) !== true
                || $capability !== config('production-customer-identity.transport_capability')) {
                throw new IdentityException('configuration_withdrawn');
            }
            // No Date/container callback after proof. Account conservatively for metadata elapsed time.
            $handoffNow = gmdate('Y-m-d H:i:s', strtotime($terminalNow.' UTC') + (int) ceil((hrtime(true) - $terminalClockStarted) / 1_000_000_000));
            if ($handoffNow < $attempt['started_at'] || $handoffNow >= $attempt['lease_expires_at']
                || $handoffNow < $selectedChallenge['created_at'] || $handoffNow >= $selectedChallenge['expires_at']) {
                throw new IdentityException;
            }
        } catch (Throwable) {
            $this->finish($database, $attempt, 'blocked', 'authority_withdrawn', IdentityEvidence::EMPTY_HASH);

            return;
        }
        // No framework query/container/model/crypto callbacks between the terminal proof and I/O.
        try {
            $accepted = $transport->submit($mail);
            $this->finish($database, $attempt, 'accepted', 'smtp_accepted', $accepted->receiptHash);
        } catch (DefinitelyNotSubmitted) {
            $this->finish($database, $attempt, 'definitely_not_submitted', 'transport_not_submitted', IdentityEvidence::EMPTY_HASH);
        } catch (Throwable) {
            $this->finish($database, $attempt, 'unknown', 'transport_uncertain', IdentityEvidence::EMPTY_HASH);
        }
    }

    private function authority(IdentityDatabase $database, array $notice, array $selector, string $email, string $now, bool $requireEligible): array
    {
        IdentityEvidence::verify('notice', $notice, 'notice_hash');
        IdentityEvidence::verify('challenge', $selector, 'challenge_hash');
        $address = $database->rows->one('production_identity_addresses', (int) $selector['address_id']);
        $users = $database->rows->rows('users', 'LOWER(email) = ?', [$email], 2);
        $user = $users[0] ?? [];
        $account = [];
        $origin = [];
        $history = [];
        if ($selector['purpose'] === 'recover') {
            $account = $database->rows->one('customer_accounts', (int) $selector['bound_account_id']);
            $origin = $database->rows->one('production_identity_origins', (int) $selector['bound_origin_id']);
            if ($requireEligible && ($user === [] || $account === [] || $origin === [] || (string) $user['is_admin'] !== '0' || $user['email_verified_at'] === null
                || (string) $account['active'] !== '1' || (int) $account['user_id'] !== (int) $user['id']
                || (int) $account['access_version'] !== (int) $selector['bound_access_version'] || (int) $origin['account_id'] !== (int) $account['id']
                || (int) $origin['user_id'] !== (int) $user['id'] || $origin['provenance'] !== $selector['provenance']
                || ! IdentityPolicy::matches('credential', $user['password'], $selector['bound_credential_binding'])
                || ! IdentityPolicy::matches('recipient', IdentityPolicy::email($user['email']), $selector['recipient_hmac']))) {
                throw new IdentityException;
            }
            if ($origin !== []) {
                IdentityEvidence::verify('origin', $origin, 'origin_hash');
            }
            $history = $database->rows->rows('production_identity_verifications', 'origin_id = ?', [(int) ($origin['id'] ?? 0)], 129);
            if ($requireEligible && ($history === [] || count($history) > 128)) {
                throw new IdentityException;
            }
        }
        $challenge = $database->rows->one('production_identity_challenges', (int) $notice['challenge_id']);
        $database->same($selector, $challenge);
        $policy = new IdentityPolicy;
        $policy->requireEnabled();
        if ($requireEligible && ($address === [] || $challenge['availability'] !== 'pending' || $challenge['provenance'] !== $policy->provenance()
            || $challenge['identity_policy_hash'] !== $policy->hash() || $challenge['challenge_hash'] !== $notice['challenge_hash']
            || $now < $challenge['created_at'] || $now >= $challenge['expires_at']
            || $database->rows->rows('production_identity_verifications', 'challenge_id = ?', [(int) $challenge['id']], 1) !== [])) {
            throw new IdentityException;
        }
        if ($requireEligible && $challenge['purpose'] === 'enroll' && $users !== []) {
            throw new IdentityException;
        }

        return compact('address', 'user', 'account', 'origin', 'history', 'challenge');
    }

    private function finish(IdentityDatabase $database, array $attempt, string $status, string $reason, string $receipt): void
    {
        try {
            $database->close(false);
            $database->connection->transaction(function () use ($database, $attempt, $status, $reason, $receipt): void {
                $database->close(true);
                $database->rows->one('production_identity_notices', (int) $attempt['notice_id']);
                $current = $database->rows->one('production_identity_attempts', (int) $attempt['id']);
                $database->same($attempt, $current);
                if ($database->rows->rows('production_identity_outcomes', 'attempt_id = ?', [(int) $attempt['id']], 1) !== []) {
                    return;
                }
                $now = $this->now();
                if ($now >= $attempt['lease_expires_at']) {
                    $status = 'unknown';
                    $reason = 'lease_expired';
                    $receipt = IdentityEvidence::EMPTY_HASH;
                }
                $this->outcome($database, $attempt, $status, $reason, $receipt, $now);
            });
        } catch (Throwable) { /* durable lease scanner seals ambiguity; do not log a private mail or retry a handoff */
        }
    }

    private function outcome(IdentityDatabase $database, array $attempt, string $status, string $reason, string $receipt, string $now): void
    {
        $database->insert('production_identity_outcomes', ['public_id' => (string) Str::uuid(), 'attempt_id' => (int) $attempt['id'],
            'status' => $status, 'reason' => $reason, 'receipt_hash' => $receipt, 'created_at' => $now,
            'next_attempt_at' => $status === 'definitely_not_submitted' ? gmdate('Y-m-d H:i:s', strtotime($now.' UTC') + 30 * (int) $attempt['number']) : $now]);
    }

    private function now(): string
    {
        return now()->utc()->format('Y-m-d H:i:s');
    }
}
