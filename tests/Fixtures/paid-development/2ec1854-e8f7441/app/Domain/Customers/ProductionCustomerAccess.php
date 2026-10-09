<?php

namespace App\Domain\Customers;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Customers\ProductionIdentity\IdentityCommittedFrame;
use App\Domain\Customers\ProductionIdentity\IdentityEvidence;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Customers\ProductionIdentity\IdentityHistoricalPlainRows;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\IdentityRows;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;

/** User -> account -> immutable origin/verification before pricing, inventory or order locks. */
final class ProductionCustomerAccess
{
    public function principal(User $trustedActor): ProductionCustomerPrincipal
    {
        return ProductionCustomerPrincipal::forUser($trustedActor);
    }

    /** Standalone captured-primary proof used to mint and before/after provider I/O. */
    public function source(User $trustedActor): array
    {
        $policy = new IdentityPolicy;
        $policy->outsideTransactions();
        $connection = DB::connection();
        $primary = $connection->getPdo();
        if (! in_array($connection->getDriverName(), ['sqlite', 'mysql'], true) || $primary->inTransaction()) {
            throw new IdentityException;
        }

        $actorId = $trustedActor->id;
        $reader = new CurrentRows($primary, $connection->getDriverName());
        $proof = $connection->transaction(fn (): array => $this->read($trustedActor, $reader));
        // TransactionCommitted callbacks are still application callbacks: close them before minting.
        if ($trustedActor->id !== $actorId || DB::connection() !== $connection || $connection->getPdo() !== $primary || $primary->inTransaction()) {
            throw new IdentityException;
        }
        $policy->outsideTransactions();
        $terminal = $this->read($trustedActor, $reader);
        if ($this->strings($proof) !== $this->strings($terminal)) {
            throw new IdentityException;
        }
        $policy->outsideTransactions();
        $policy->requireEnabled();

        return $terminal;
    }

    public function current(ProductionCustomerPrincipal $principal, User $trustedActor): array
    {
        $proof = $this->source($trustedActor);
        $this->match($principal, $trustedActor, $proof);

        return $proof;
    }

    public function lock(ProductionCustomerPrincipal $principal, User $trustedActor, CurrentRows $reader): array
    {
        if (DB::transactionLevel() === 0) {
            throw new IdentityException('transaction_required');
        }
        $proof = $this->read($trustedActor, $reader);
        $this->match($principal, $trustedActor, $proof);

        return $proof;
    }

    /** Complete direct primary comparison after all framework/decrypt/audit callbacks. */
    public function proveCurrent(ProductionCustomerPrincipal $principal, User $trustedActor, CurrentRows $reader, array $expectedRaw): void
    {
        $proof = $this->lock($principal, $trustedActor, $reader);
        if (CanonicalJson::encode($this->strings($proof)) !== CanonicalJson::encode($this->strings($expectedRaw))) {
            throw new IdentityException;
        }
    }

    /** Revalidate the original evidence in an owned read-only frame; this mints no renewed principal. */
    public function proveCommitted(ProductionCustomerPrincipal $principal, User $trustedActor, IdentityCommittedFrame $frame, array $expectedRaw): void
    {
        $reader = IdentityRows::committed($frame);
        $proof = $this->read($trustedActor, $frame->reader(), $reader);
        $this->match($principal, $trustedActor, $proof);
        if ($this->strings($proof) !== $this->strings($expectedRaw)) {
            throw new IdentityException;
        }
        // Deep history/permanent-floor work is complete. Close mutable authority with fresh direct reads.
        if ($this->strings($reader->one('users', $principal->userId)) !== $this->strings($expectedRaw['user'])
            || $this->strings($reader->one('customer_accounts', $principal->accountId)) !== $this->strings($expectedRaw['account'])) {
            throw new IdentityException;
        }
    }

    public function durableBinding(ProductionCustomerPrincipal $principal): array
    {
        return $principal->durableBinding();
    }

    /** Original authenticated act, including its immutable prefix; this confers no current access. */
    public function verifyHistoricalBinding(array $binding, CurrentRows $reader): array
    {
        return $this->historical($binding, IdentityRows::from($reader));
    }

    public function verifyHistoricalBindingCommitted(array $binding, IdentityCommittedFrame $frame): array
    {
        return $this->historical($binding, IdentityRows::committed($frame));
    }

    public function proveHistoricalBindingCommitted(array $binding, IdentityCommittedFrame $frame, array $expectedRaw): void
    {
        if ($this->strings($this->verifyHistoricalBindingCommitted($binding, $frame)) !== $this->strings($expectedRaw)) {
            throw new IdentityException;
        }
    }

    /** Original historical facts using the receipt's fixed permanent nonlocking reader only. */
    public function verifyHistoricalBindingPlain(array $binding, IdentityHistoricalPlainRows $reader): array
    {
        return $this->historical($binding, $reader);
    }

    public function proveHistoricalBindingPlain(array $binding, IdentityHistoricalPlainRows $reader, array $expectedRaw): void
    {
        if ($this->strings($this->verifyHistoricalBindingPlain($binding, $reader)) !== $this->strings($expectedRaw)) {
            throw new IdentityException;
        }
    }

    private function historical(array $binding, IdentityRows|IdentityHistoricalPlainRows $reader): array
    {
        $keys = ['schema_version', 'origin_id', 'provenance', 'account_id', 'account_public_id', 'user_id', 'verification_observation_id', 'verification_observation_hash', 'identity_policy_version', 'identity_policy_hash'];
        $actual = array_keys($binding);
        sort($actual);
        sort($keys);
        if ($actual !== $keys || $binding['schema_version'] !== 1 || ! is_int($binding['account_id']) || ! is_int($binding['user_id'])
            || $binding['account_id'] < 1 || $binding['user_id'] < 1 || $binding['identity_policy_version'] !== IdentityPolicy::VERSION
            || ! hash_equals(IdentityPolicy::historicalHash($binding['provenance']), $binding['identity_policy_hash'])) {
            throw new IdentityException;
        }
        $user = $reader->one('users', $binding['user_id']);
        $account = $reader->one('customer_accounts', $binding['account_id']);
        $origins = $reader->rows('production_identity_origins', 'account_id = ?', [$binding['account_id']], 2);
        $origin = count($origins) === 1 ? $origins[0] : [];
        if ($user === [] || $account === [] || $origin === [] || (int) $account['user_id'] !== $binding['user_id']
            || $account['public_id'] !== $binding['account_public_id'] || $origin['public_id'] !== $binding['origin_id']
            || (int) $origin['user_id'] !== $binding['user_id'] || $origin['provenance'] !== $binding['provenance']
            || ! hash_equals($origin['owner_digest'], IdentityPolicy::digest('owner', $account['owner_key']))
            || $origin['identity_policy_version'] !== $binding['identity_policy_version'] || $origin['identity_policy_hash'] !== $binding['identity_policy_hash']) {
            throw new IdentityException;
        }
        IdentityEvidence::verify('origin', $origin, 'origin_hash');
        $history = $reader->rows('production_identity_verifications', 'origin_id = ?', [(int) $origin['id']], 129);
        if ($history === [] || count($history) > 128) {
            throw new IdentityException;
        }
        $prefix = [];
        $challenges = [];
        $prior = IdentityEvidence::EMPTY_HASH;
        $found = false;
        foreach ($history as $index => $entry) {
            IdentityEvidence::verify('verification', $entry, 'observation_hash');
            if ((int) $entry['sequence'] !== $index + 1 || (int) $entry['account_id'] !== $binding['account_id'] || (int) $entry['user_id'] !== $binding['user_id']
                || $entry['provenance'] !== $binding['provenance'] || $entry['identity_policy_version'] !== $binding['identity_policy_version']
                || $entry['identity_policy_hash'] !== $binding['identity_policy_hash'] || $entry['prior_observation_hash'] !== $prior) {
                throw new IdentityException;
            }
            $challenge = $reader->one('production_identity_challenges', (int) $entry['challenge_id']);
            IdentityEvidence::verify('challenge', $challenge, 'challenge_hash');
            if ($challenge['availability'] !== 'pending' || $challenge['purpose'] !== $entry['purpose'] || $challenge['provenance'] !== $entry['provenance']
                || $challenge['identity_policy_version'] !== $entry['identity_policy_version'] || $challenge['identity_policy_hash'] !== $entry['identity_policy_hash']
                || $challenge['proof_hash'] !== $entry['proof_hash'] || $challenge['challenge_hash'] !== $entry['challenge_hash']
                || $challenge['recipient_hmac'] !== $entry['recipient_hmac'] || $challenge['address_id'] != $origin['address_id']
                || $entry['created_at'] < $challenge['created_at'] || $entry['created_at'] >= $challenge['expires_at']
                || ($index === 0 && ($challenge['purpose'] !== 'enroll' || (int) $origin['initial_challenge_id'] !== (int) $challenge['id']))
                || ($index > 0 && ($challenge['purpose'] !== 'recover' || (int) $challenge['bound_origin_id'] !== (int) $origin['id']
                    || (int) $challenge['bound_account_id'] !== $binding['account_id'] || (int) $challenge['bound_user_id'] !== $binding['user_id']
                    || $challenge['bound_credential_binding'] !== $prefix[$index - 1]['credential_binding']))) {
                throw new IdentityException;
            }
            $prefix[] = $entry;
            $challenges[] = $challenge;
            $prior = $entry['observation_hash'];
            if ($entry['public_id'] === $binding['verification_observation_id']) {
                if (! hash_equals($entry['observation_hash'], $binding['verification_observation_hash'])) {
                    throw new IdentityException;
                }
                $found = true;
                break;
            }
        }
        if (! $found) {
            throw new IdentityException;
        }

        $reader->assertPermanent();

        return ['user_id' => (int) $user['id'], 'account' => ['id' => (int) $account['id'], 'public_id' => $account['public_id'],
            'user_id' => (int) $account['user_id'], 'owner_digest' => IdentityPolicy::digest('owner', $account['owner_key'])],
            'origin' => $origin, 'verification_prefix' => $prefix, 'challenges' => $challenges];
    }

    public function proveHistoricalBindingCurrent(array $binding, CurrentRows $reader, array $expectedRaw): void
    {
        if ($this->strings($this->verifyHistoricalBinding($binding, $reader)) !== $this->strings($expectedRaw)) {
            throw new IdentityException;
        }
    }

    private function read(User $actor, CurrentRows $sourceReader, ?IdentityRows $committedReader = null): array
    {
        $reader = $committedReader ?? IdentityRows::from($sourceReader);
        $policy = new IdentityPolicy;
        $policy->requireEnabled();
        if ($actor::class !== User::class || ! $actor->exists || ! is_int($actor->getKey()) || $actor->id < 1) {
            throw new IdentityException;
        }
        $user = $reader->one('users', $actor->id);
        $accounts = $reader->rows('customer_accounts', 'user_id = ?', [$actor->id], 2);
        $account = count($accounts) === 1 ? $accounts[0] : [];
        if ($user === [] || (string) $user['is_admin'] !== '0' || $user['email_verified_at'] === null
            || $account === [] || (string) $account['active'] !== '1' || (int) $account['access_version'] < 1
            || ! preg_match('/\A[a-f0-9]{64}\z/D', $account['owner_key'])) {
            throw new IdentityException;
        }
        $origins = $reader->rows('production_identity_origins', 'account_id = ?', [(int) $account['id']], 2);
        $origin = count($origins) === 1 ? $origins[0] : [];
        if ($origin === [] || (int) $origin['user_id'] !== (int) $user['id']
            || ! hash_equals($origin['owner_digest'], IdentityPolicy::digest('owner', $account['owner_key']))
            || $origin['provenance'] !== $policy->provenance() || $origin['identity_policy_version'] !== IdentityPolicy::VERSION
            || ! hash_equals($origin['identity_policy_hash'], $policy->hash())) {
            throw new IdentityException;
        }
        IdentityEvidence::verify('origin', $origin, 'origin_hash');
        $observations = $reader->rows('production_identity_verifications', 'origin_id = ?', [(int) $origin['id']], 129);
        if ($observations === [] || count($observations) > 128) {
            throw new IdentityException;
        }
        $observation = $observations[array_key_last($observations)];
        $prior = IdentityEvidence::EMPTY_HASH;
        foreach ($observations as $index => $entry) {
            IdentityEvidence::verify('verification', $entry, 'observation_hash');
            if ((int) $entry['sequence'] !== $index + 1 || (int) $entry['origin_id'] !== (int) $origin['id']
                || (int) $entry['account_id'] !== (int) $account['id'] || (int) $entry['user_id'] !== (int) $user['id']
                || $entry['provenance'] !== $origin['provenance'] || ! hash_equals($entry['prior_observation_hash'], $prior)) {
                throw new IdentityException;
            }
            $prior = $entry['observation_hash'];
        }
        $challenge = $reader->one('production_identity_challenges', (int) $observation['challenge_id']);
        IdentityEvidence::verify('challenge', $challenge, 'challenge_hash');
        if ($challenge['availability'] !== 'pending' || $challenge['purpose'] !== $observation['purpose']
            || $challenge['provenance'] !== $origin['provenance'] || $challenge['proof_hash'] !== $observation['proof_hash']
            || $challenge['challenge_hash'] !== $observation['challenge_hash']
            || $observation['created_at'] < $challenge['created_at'] || $observation['created_at'] >= $challenge['expires_at']
            || ($observation['sequence'] == 1 && (int) $origin['initial_challenge_id'] !== (int) $challenge['id'])
            || ($observation['sequence'] != 1 && ((int) $challenge['bound_origin_id'] !== (int) $origin['id']
                || (int) $challenge['bound_user_id'] !== (int) $user['id'] || (int) $challenge['bound_account_id'] !== (int) $account['id']))) {
            throw new IdentityException;
        }
        $credential = IdentityPolicy::digest('credential', $user['password']);
        $recipient = IdentityPolicy::digest('recipient', IdentityPolicy::email($user['email']));
        if ((int) $observation['sequence'] !== count($observations) || (int) $observation['account_id'] !== (int) $account['id']
            || (int) $observation['user_id'] !== (int) $user['id'] || $observation['provenance'] !== $origin['provenance']
            || $observation['identity_policy_version'] !== IdentityPolicy::VERSION || ! hash_equals($observation['identity_policy_hash'], $policy->hash())
            || ! hash_equals($observation['credential_binding'], $credential) || ! hash_equals($observation['recipient_hmac'], $recipient)) {
            throw new IdentityException;
        }
        $binding = ['schema_version' => 1, 'origin_id' => $origin['public_id'], 'provenance' => $origin['provenance'],
            'account_id' => (int) $account['id'], 'account_public_id' => $account['public_id'], 'user_id' => (int) $user['id'],
            'verification_observation_id' => $observation['public_id'], 'verification_observation_hash' => $observation['observation_hash'],
            'identity_policy_version' => IdentityPolicy::VERSION, 'identity_policy_hash' => $policy->hash()];

        $this->historical($binding, $reader);
        $reader->assertPermanent();

        return ['user' => $user, 'account' => $account, 'origin' => $origin, 'observation' => $observation, 'challenge' => $challenge,
            'verification_history' => $observations, 'owner_digest' => IdentityPolicy::digest('owner', $account['owner_key']),
            'credential_binding' => $credential, 'durable_binding' => $binding];
    }

    private function match(ProductionCustomerPrincipal $principal, User $actor, array $proof): void
    {
        if ($actor->id !== $principal->userId || (int) $proof['user']['id'] !== $principal->userId
            || (int) $proof['account']['id'] !== $principal->accountId || (int) $proof['account']['access_version'] !== $principal->accessVersion
            || ! $principal->matchesPrivate($proof['owner_digest'], $proof['credential_binding'])
            || CanonicalJson::encode($principal->durableBinding()) !== CanonicalJson::encode($proof['durable_binding'])) {
            throw new IdentityException;
        }
    }

    private function strings(array $proof): array
    {
        foreach ($proof as &$value) {
            $value = is_array($value) ? $this->strings($value) : ($value === null ? null : (string) $value);
        }
        ksort($proof);

        return $proof;
    }
}
