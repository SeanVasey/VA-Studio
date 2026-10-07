<?php

namespace App\Domain\Customers\ProductionIdentity;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Customers\ProductionCustomerAccess;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use SensitiveParameter;

/** Mailbox possession creates a new origin or appends recovery to the original origin; never email adoption. */
final class CompleteIdentity
{
    public function complete(string $publicId, #[SensitiveParameter] string $proof, #[SensitiveParameter] string $password, string $name, #[SensitiveParameter] string $requestKey): array
    {
        $policy = new IdentityPolicy;
        $policy->requireEnabled();
        $policy->outsideTransactions();
        if (! preg_match('/\A[a-f0-9-]{36}\z/D', $publicId) || ! preg_match('/\A[a-f0-9]{64}\z/D', $proof)
            || ! preg_match('/\A[a-f0-9]{64}\z/D', $requestKey) || strlen($password) < 12 || strlen($password) > 72
            || str_contains($password, "\0") || ! preg_match('/[a-zA-Z]/', $password) || ! preg_match('/[0-9]/', $password)
            || trim($name) === '' || strlen($name) > 120 || preg_match('/[\x00-\x1f\x7f]/', $name)) {
            throw new IdentityException;
        }
        $database = new IdentityDatabase;
        $database->close(false);
        $initial = $database->rows->rows('production_identity_challenges', 'public_id = ?', [$publicId], 1)[0] ?? [];
        IdentityEvidence::verify('challenge', $initial, 'challenge_hash');
        if ($initial['availability'] !== 'pending' || ! hash_equals($initial['proof_hash'], IdentityPolicy::digest('proof', $proof))) {
            throw new IdentityException;
        }
        $payload = Crypt::decryptString($initial['payload_ciphertext']);
        if (! hash_equals($initial['payload_hash'], IdentityPolicy::digest('payload', $payload))) {
            throw new IdentityException;
        }
        $decoded = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
        $email = IdentityPolicy::email($decoded['recipient'] ?? '');
        if (! hash_equals($initial['recipient_hmac'], IdentityPolicy::digest('recipient', $email))) {
            throw new IdentityException;
        }
        $completionHash = IdentityPolicy::digest('completion', CanonicalJson::encode(['id' => $publicId, 'proof' => $proof, 'password' => $password, 'name' => $name, 'request_key' => $requestKey]));
        $passwordHash = Hash::make($password); // No password hashing callback after the final locked proof.
        $now = now()->utc()->format('Y-m-d H:i:s');
        $result = $database->connection->transaction(function () use ($database, $policy, $initial, $email, $completionHash, $passwordHash, $name, $now): array {
            $database->close(true);
            $policy->requireEnabled();
            $address = $database->rows->one('production_identity_addresses', (int) $initial['address_id']);
            if ($address === [] || ! hash_equals($address['address_hash'], IdentityPolicy::digest('address', $email))) {
                throw new IdentityException;
            }
            $users = $database->rows->rows('users', 'LOWER(email) = ?', [$email], 2);
            $user = count($users) === 1 ? $users[0] : [];
            $account = [];
            $origin = [];
            $observations = [];
            if ($user !== []) {
                $accounts = $database->rows->rows('customer_accounts', 'user_id = ?', [(int) $user['id']], 2);
                $account = count($accounts) === 1 ? $accounts[0] : [];
                if ($account !== []) {
                    $origins = $database->rows->rows('production_identity_origins', 'account_id = ?', [(int) $account['id']], 2);
                    $origin = count($origins) === 1 ? $origins[0] : [];
                    if ($origin !== []) {
                        $observations = $database->rows->rows('production_identity_verifications', 'origin_id = ?', [(int) $origin['id']], 129);
                    }
                }
            }
            $challenge = $database->rows->one('production_identity_challenges', (int) $initial['id']);
            $database->same($initial, $challenge);
            if ($challenge['provenance'] !== $policy->provenance() || $challenge['identity_policy_hash'] !== $policy->hash()) {
                throw new IdentityException;
            }
            $completed = $database->rows->rows('production_identity_verifications', 'challenge_id = ?', [(int) $challenge['id']], 1);
            if ($completed !== []) {
                $observation = $completed[0];
                IdentityEvidence::verify('verification', $observation, 'observation_hash');
                if ($origin === [] || $account === [] || ! hash_equals($observation['completion_hash'], $completionHash)
                    || (string) $user['is_admin'] !== '0' || $user['email_verified_at'] === null || (string) $account['active'] !== '1'
                    || (int) $account['access_version'] !== ($challenge['purpose'] === 'enroll' ? 1 : (int) $challenge['bound_access_version'])
                    || ! hash_equals($observation['credential_binding'], IdentityPolicy::digest('credential', $user['password']))
                    || ! hash_equals($observation['recipient_hmac'], IdentityPolicy::digest('recipient', $email))) {
                    throw new IdentityException;
                }

                return ['account_public_id' => $account['public_id'], 'user_id' => (int) $user['id']];
            }
            if ($now < $challenge['created_at'] || $now >= $challenge['expires_at']) {
                throw new IdentityException;
            }
            if ($challenge['purpose'] === 'enroll') {
                if ($users !== []) {
                    throw new IdentityException;
                } // Existing test/admin/user accounts are never adopted.
                $userId = $database->insert('users', ['name' => $name, 'email' => $email, 'email_verified_at' => $now,
                    'password' => $passwordHash, 'is_admin' => 0, 'remember_token' => null, 'created_at' => $now, 'updated_at' => $now]);
                $owner = bin2hex(random_bytes(32));
                $database->insert('quote_owners', ['owner_key' => $owner]);
                $accountId = $database->insert('customer_accounts', ['public_id' => (string) Str::uuid(), 'user_id' => $userId,
                    'owner_key' => $owner, 'active' => 1, 'access_version' => 1, 'created_at' => $now, 'updated_at' => $now]);
                $origin = ['public_id' => (string) Str::uuid(), 'provenance' => $challenge['provenance'], 'identity_policy_version' => IdentityPolicy::VERSION,
                    'identity_policy_hash' => $policy->hash(), 'account_id' => $accountId, 'user_id' => $userId,
                    'address_id' => (int) $address['id'], 'initial_challenge_id' => (int) $challenge['id'],
                    'owner_digest' => IdentityPolicy::digest('owner', $owner), 'recipient_hmac' => $challenge['recipient_hmac'], 'created_at' => $now];
                $origin['origin_hash'] = IdentityEvidence::hash('origin', $origin);
                $origin['id'] = $database->insert('production_identity_origins', $origin);
                $user = $database->rows->one('users', $userId);
                $account = $database->rows->one('customer_accounts', $accountId);
            } else {
                if ($origin === [] || $observations === [] || count($observations) >= 128 || (string) $user['is_admin'] !== '0'
                    || $user['email_verified_at'] === null || (string) $account['active'] !== '1'
                    || (int) $challenge['bound_origin_id'] !== (int) $origin['id'] || (int) $challenge['bound_user_id'] !== (int) $user['id']
                    || (int) $challenge['bound_account_id'] !== (int) $account['id'] || (int) $challenge['bound_access_version'] !== (int) $account['access_version']
                    || ! hash_equals($challenge['bound_credential_binding'], IdentityPolicy::digest('credential', $user['password']))) {
                    throw new IdentityException;
                }
                IdentityEvidence::verify('origin', $origin, 'origin_hash');
                $access = new ProductionCustomerAccess;
                $access->verifyHistoricalBinding(['schema_version' => 1, 'origin_id' => $origin['public_id'], 'provenance' => $origin['provenance'],
                    'account_id' => (int) $account['id'], 'account_public_id' => $account['public_id'], 'user_id' => (int) $user['id'],
                    'verification_observation_id' => $observations[array_key_last($observations)]['public_id'],
                    'verification_observation_hash' => $observations[array_key_last($observations)]['observation_hash'],
                    'identity_policy_version' => IdentityPolicy::VERSION, 'identity_policy_hash' => $policy->hash()], new CurrentRows($database->primary, $database->driver));
                // Direct current rows after any model creation callbacks, before credential replacement.
                $database->same($user, $database->rows->one('users', (int) $user['id']));
                $database->same($account, $database->rows->one('customer_accounts', (int) $account['id']));
                $statement = $database->primary->prepare('UPDATE '.$database->rows->table('users').' SET name=?, password=?, remember_token=NULL, updated_at=? WHERE id=?');
                $statement->execute([$name, $passwordHash, $now, (int) $user['id']]);
                $user = $database->rows->one('users', (int) $user['id']);
            }
            if ($user['password'] !== $passwordHash || $user['email'] !== $email || (string) $user['is_admin'] !== '0'
                || $user['email_verified_at'] === null || (string) $account['active'] !== '1') {
                throw new IdentityException;
            }
            $observation = ['public_id' => (string) Str::uuid(), 'provenance' => $challenge['provenance'], 'identity_policy_version' => IdentityPolicy::VERSION,
                'identity_policy_hash' => $policy->hash(), 'origin_id' => (int) $origin['id'], 'account_id' => (int) $account['id'],
                'user_id' => (int) $user['id'], 'challenge_id' => (int) $challenge['id'], 'sequence' => count($observations) + 1,
                'purpose' => $challenge['purpose'], 'recipient_hmac' => $challenge['recipient_hmac'], 'proof_hash' => $challenge['proof_hash'],
                'credential_binding' => IdentityPolicy::digest('credential', $user['password']), 'completion_hash' => $completionHash,
                'challenge_hash' => $challenge['challenge_hash'], 'prior_observation_hash' => $observations === [] ? IdentityEvidence::EMPTY_HASH : $observations[array_key_last($observations)]['observation_hash'], 'created_at' => $now];
            $observation['observation_hash'] = IdentityEvidence::hash('verification', $observation);
            $database->insert('production_identity_verifications', $observation);
            $database->insert('audit_events', ['actor_id' => (int) $user['id'], 'action' => 'customer.identity.'.$challenge['purpose'].'.verified',
                'subject_type' => 'production_customer_origin', 'subject_id' => (int) $origin['id'], 'context' => CanonicalJson::encode(['origin_id' => $origin['public_id'], 'provenance' => $origin['provenance'], 'observation_id' => $observation['public_id']]), 'created_at' => $now]);
            $database->same($user, $database->rows->one('users', (int) $user['id']));
            $database->same($account, $database->rows->one('customer_accounts', (int) $account['id']));
            IdentityEvidence::verify('origin', $database->rows->one('production_identity_origins', (int) $origin['id']), 'origin_hash');
            $database->close(true);
            $policy->requireEnabled();
            if ($policy->hash() !== $challenge['identity_policy_hash'] || $policy->provenance() !== $challenge['provenance']) {
                throw new IdentityException;
            }

            return ['account_public_id' => $account['public_id'], 'user_id' => (int) $user['id']];
        });
        $database->close(false);
        $policy->requireEnabled();

        // Completion is not a login. The caller may proceed through the distinct current-credential sign-in flow.
        return $result;
    }
}
