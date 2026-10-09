<?php

namespace App\Domain\Customers\ProductionIdentity;

use App\Jobs\DeliverProductionIdentityNotice;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use SensitiveParameter;

/** Admission + immutable challenge + outbox in one transaction; the HTTP result has no address oracle. */
final class IdentityRequests
{
    public function request(string $purpose, #[SensitiveParameter] string $email, #[SensitiveParameter] string $requestKey): void
    {
        $policy = new IdentityPolicy;
        $policy->requireEnabled();
        $policy->outsideTransactions();
        $email = IdentityPolicy::email($email);
        if (! in_array($purpose, ['enroll', 'recover'], true) || ! preg_match('/\A[a-f0-9]{64}\z/D', $requestKey)) {
            throw new IdentityException;
        }
        $database = new IdentityDatabase;
        $database->close(false);
        $now = now()->utc()->format('Y-m-d H:i:s');
        $addressHash = IdentityPolicy::digest('address', $email);
        $requestHash = IdentityPolicy::digest('request', $purpose."\0".$email."\0".$requestKey);
        $publicId = (string) Str::uuid();
        $proof = bin2hex(random_bytes(32));
        $scope = $policy->provenance();
        $policyHash = $policy->hash();
        $payload = CanonicalJson::encode(['schema_version' => 1, 'recipient' => $email, 'purpose' => $purpose,
            'url' => $this->url($scope).'#'.$purpose.'.'.$publicId.'.'.$proof]);
        $ciphertext = Crypt::encryptString($payload); // Before the retained transaction and final authority reads.
        $noticeId = $database->connection->transaction(function () use ($database, $policy, $purpose, $email, $now, $addressHash, $requestHash, $publicId, $proof, $scope, $policyHash, $payload, $ciphertext): ?int {
            $database->close(true);
            $policy->requireEnabled();
            if ($policy->provenance() !== $scope || $policy->hash() !== $policyHash) {
                throw new IdentityException;
            }
            // The same opaque address fence serializes enrollment, recovery and rate admission.
            $addresses = $database->rows->rows('production_identity_addresses', 'address_hash = ?', [$addressHash], 1);
            if ($addresses === []) {
                $database->insert('production_identity_addresses', ['address_hash' => $addressHash]);
                $addresses = $database->rows->rows('production_identity_addresses', 'address_hash = ?', [$addressHash], 1);
            }
            $address = $addresses[0];
            $existing = $database->rows->rows('production_identity_challenges', 'request_hash = ?', [$requestHash], 1);
            if ($existing !== []) {
                IdentityEvidence::verify('challenge', $existing[0], 'challenge_hash');

                return null;
            }
            $recent = $database->rows->rows('production_identity_challenges', 'address_id = ? AND created_at >= ?', [(int) $address['id'], gmdate('Y-m-d H:i:s', strtotime($now.' UTC') - 3600)], 5);
            if (count($recent) >= IdentityPolicy::MAX_REQUESTS_PER_HOUR) {
                return null;
            }
            $users = $database->rows->rows('users', 'LOWER(email) = ?', [$email], 2);
            $user = count($users) === 1 ? $users[0] : [];
            $account = [];
            $origin = [];
            $observations = [];
            if ($user !== [] && $purpose === 'recover') {
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
            $available = $purpose === 'enroll' ? $users === [] : $this->recoverable($user, $account, $origin, $observations, $scope, $policyHash, $email);
            $challenge = ['public_id' => $publicId, 'provenance' => $scope, 'identity_policy_version' => IdentityPolicy::VERSION,
                'identity_policy_hash' => $policyHash, 'address_id' => (int) $address['id'], 'purpose' => $purpose,
                'request_hash' => $requestHash, 'recipient_hmac' => IdentityPolicy::digest('recipient', $email),
                'payload_ciphertext' => $ciphertext, 'payload_hash' => IdentityPolicy::digest('payload', $payload),
                'proof_hash' => IdentityPolicy::digest('proof', $proof), 'bound_user_id' => $available && $purpose === 'recover' ? (int) $user['id'] : 0,
                'bound_account_id' => $available && $purpose === 'recover' ? (int) $account['id'] : 0,
                'bound_origin_id' => $available && $purpose === 'recover' ? (int) $origin['id'] : 0,
                'bound_access_version' => $available && $purpose === 'recover' ? (int) $account['access_version'] : 0,
                'bound_credential_binding' => $available && $purpose === 'recover' ? IdentityPolicy::digest('credential', $user['password']) : IdentityEvidence::EMPTY_HASH,
                'availability' => $available ? 'pending' : 'unavailable', 'created_at' => $now,
                'expires_at' => gmdate('Y-m-d H:i:s', strtotime($now.' UTC') + IdentityPolicy::TTL_SECONDS)];
            $challenge['challenge_hash'] = IdentityEvidence::hash('challenge', $challenge);
            $challengeId = $database->insert('production_identity_challenges', $challenge);
            if (! $available) {
                return null;
            }
            $notice = ['public_id' => (string) Str::uuid(), 'provenance' => $scope, 'identity_policy_version' => IdentityPolicy::VERSION,
                'identity_policy_hash' => $policyHash, 'challenge_id' => $challengeId, 'challenge_hash' => $challenge['challenge_hash'],
                'template_version' => 'identity-'.$purpose.'-v1', 'created_at' => $now];
            $notice['notice_hash'] = IdentityEvidence::hash('notice', $notice);
            $id = $database->insert('production_identity_notices', $notice);
            // No model/decrypt/query dispatch after this closure; proof/request never enters a queued payload.
            $database->close(true);
            $policy->requireEnabled();
            if ($policy->provenance() !== $scope || $policy->hash() !== $policyHash) {
                throw new IdentityException;
            }

            return $id;
        }, 5);
        // A missed or failed wake-up leaves durable work for the bounded recovery command.
        if ($noticeId !== null) {
            try {
                DeliverProductionIdentityNotice::dispatch($noticeId);
            } catch (\Throwable) { /* no private transport diagnostics */
            }
        }
    }

    private function recoverable(array $user, array $account, array $origin, array $observations, string $scope, string $policyHash, string $email): bool
    {
        if ($origin === [] || $observations === [] || count($observations) > 128 || (string) $user['is_admin'] !== '0'
            || $user['email_verified_at'] === null || (string) $account['active'] !== '1' || $origin['provenance'] !== $scope
            || $origin['identity_policy_version'] !== IdentityPolicy::VERSION || ! hash_equals($origin['identity_policy_hash'], $policyHash)) {
            return false;
        }
        IdentityEvidence::verify('origin', $origin, 'origin_hash');
        $previous = IdentityEvidence::EMPTY_HASH;
        foreach ($observations as $index => $observation) {
            IdentityEvidence::verify('verification', $observation, 'observation_hash');
            if ((int) $observation['sequence'] !== $index + 1 || (int) $observation['account_id'] !== (int) $account['id']
                || (int) $observation['user_id'] !== (int) $user['id'] || $observation['provenance'] !== $scope
                || ! hash_equals($observation['prior_observation_hash'], $previous)) {
                throw new IdentityException;
            }
            $previous = $observation['observation_hash'];
        }
        $last = $observations[array_key_last($observations)];

        return hash_equals($last['credential_binding'], IdentityPolicy::digest('credential', $user['password']))
            && hash_equals($last['recipient_hmac'], IdentityPolicy::digest('recipient', $email));
    }

    private function url(string $scope): string
    {
        $origin = config('production-customer-identity.public_origin');
        if (! is_string($origin) || ! preg_match('~\Ahttps?://[a-zA-Z0-9.-]+(?::[0-9]{1,5})?\z~D', $origin)) {
            throw new IdentityException;
        }
        $parts = parse_url($origin);
        if ($scope === IdentityPolicy::REHEARSAL ? ! in_array($parts['host'], ['localhost', '127.0.0.1'], true) : $parts['scheme'] !== 'https') {
            throw new IdentityException;
        }

        return $origin.'/customer/access';
    }
}
