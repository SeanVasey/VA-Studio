<?php

namespace App\Domain\Customers;

use App\Domain\Customers\Models\CustomerAccount;
use App\Domain\Customers\Models\CustomerIdentityChallenge;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Illuminate\Validation\Rules\Password;
use LogicException;
use SensitiveParameter;

final class CustomerIdentityChallenges
{
    /** The address fence precedes User -> Account -> Challenge. No mail or filesystem I/O under locks. */
    public function request(string $purpose, #[SensitiveParameter] string $email, string $requestKey, #[SensitiveParameter] string $owner): void
    {
        $this->standalone();
        $email = CustomerIdentityPolicy::email($email);
        $this->purpose($purpose);
        $this->uuid($requestKey);
        if (! preg_match('/\A[a-f0-9]{64}\z/D', $owner)) {
            throw new CustomerAccessException;
        }
        (new Timebox)->call(function () use ($purpose, $email, $requestKey, $owner): void {
            // Lookups select stored rows under any configured key; new rows are written with the current key ([0]).
            $addresses = $this->digests('address', $email);
            $requestHashes = $this->digests('request', $owner."\0".$requestKey);
            $challenge = DB::transaction(function () use ($purpose, $email, $addresses, $requestHashes): ?CustomerIdentityChallenge {
                $address = $this->fence($addresses);
                if ($address === null) {
                    return null;
                }
                $user = $this->emailUser($email);
                $account = $user ? CustomerAccount::where('user_id', $user->id)->lockForUpdate()->first() : null;
                // One exact unique-key lookup per candidate keeps the single-key lock shape.
                $priors = array_values(array_filter(array_map(fn (string $hash): ?CustomerIdentityChallenge => CustomerIdentityChallenge::where('request_hash', $hash)->lockForUpdate()->first(), $requestHashes)));
                if (count($priors) > 1) {
                    return null; // Two key candidates selecting two requests is ambiguous; never pick one.
                }
                $prior = $priors[0] ?? null;
                if ($prior) {
                    // Changed request bytes never adopt another pending request or leak its identity.
                    return hash_equals($prior->address_key, $address) && $prior->purpose === $purpose && $prior->state === 'pending'
                        && $prior->expires_at->isFuture() && $this->eligibleRequest($prior, $user, $account) ? $prior : null;
                }
                // Each new request key is retained. Only the exact original request can resend its proof.
                // Successful completion invalidates other pending proofs through the user/credential binding.
                if (CustomerIdentityChallenge::where('address_key', $address)->where('created_at', '>', now()->subHour())->count() >= 4) {
                    return null;
                }
                $eligible = $purpose === 'enroll' ? $user === null && ! $this->emailExists($email) : $this->eligible($user, $account);
                $id = (string) Str::uuid();

                return CustomerIdentityChallenge::create(['public_id' => $id, 'address_key' => $address, 'request_hash' => $requestHashes[0],
                    'purpose' => $purpose, 'policy_version' => CustomerIdentityPolicy::VERSION, 'email' => $email,
                    'proof_hash' => hash('sha256', $this->proof($id)), 'user_id' => $purpose === 'recover' && $eligible ? $user->id : null,
                    'account_id' => $purpose === 'recover' && $eligible ? $account->id : null,
                    'access_version' => $purpose === 'recover' && $eligible ? $account->access_version : null,
                    'credential_stamp' => $purpose === 'recover' && $eligible ? app(CustomerAccess::class)->stamp($user) : null,
                    'state' => $eligible ? 'pending' : 'unavailable', 'created_at' => now()->startOfSecond(),
                    'expires_at' => now()->startOfSecond()->addSeconds(CustomerIdentityPolicy::TTL_SECONDS)]);
            }, 5);
            $proof = $challenge?->state === 'pending' ? $this->issuedProof($challenge) : null;
            if ($proof !== null) {
                // Exact request replay can reproduce a lost capture acknowledgement without retaining a raw proof in SQL.
                try {
                    app(CustomerIdentityCapture::class)->store($challenge, $proof);
                } catch (\Throwable $error) {
                    // Transport health must not turn this generic public acknowledgement into an address oracle.
                    try {
                        Log::error('Private customer identity capture failed.', ['exception_class' => $error::class]);
                    } catch (\Throwable) { /* No proof, recipient or private path enters a fallback log. */
                    }
                }
            }
        }, 200000);
    }

    public function complete(string $purpose, string $id, #[SensitiveParameter] string $proof, #[SensitiveParameter] string $name,
        #[SensitiveParameter] string $password, string $requestKey): void
    {
        $this->standalone();
        $this->purpose($purpose);
        $this->uuid($id);
        $this->uuid($requestKey);
        if (! preg_match('/\A[a-f0-9]{64}\z/D', $proof) || strlen($password) > 72 || str_contains($password, "\0") || ! mb_check_encoding($name, 'UTF-8') || ($purpose === 'recover' && $name !== '')
            || ($purpose === 'enroll' && (trim($name) === '' || mb_strlen($name) > 120 || preg_match('/[\x00-\x1f\x7f]/u', $name)))) {
            throw new CustomerAccessException;
        }
        Validator::make(['password' => $password], ['password' => ['required', 'string', 'max:1024', Password::min(12)->letters()->numbers()]])->validate();
        $completion = json_encode([$purpose, $id, $name, $password, $requestKey], JSON_THROW_ON_ERROR);
        $completionHash = $this->digest('completion', $completion);
        // This preliminary lookup locates the address fence; all bytes and authority are checked again under it.
        $locator = CustomerIdentityChallenge::where('public_id', $id)->first();
        if (! $locator || ! hash_equals($locator->public_id, $id) || ! hash_equals($locator->proof_hash, hash('sha256', $proof))) {
            throw new CustomerAccessException;
        }
        DB::transaction(function () use ($locator, $purpose, $id, $proof, $name, $password, $completion, $completionHash): void {
            $this->address($locator->address_key);
            $email = $locator->email;
            $user = $this->emailUser($email);
            $account = $user ? CustomerAccount::where('user_id', $user->id)->lockForUpdate()->first() : null;
            $challenge = CustomerIdentityChallenge::where('public_id', $id)->lockForUpdate()->first();
            if (! $challenge || $challenge->policy_version !== CustomerIdentityPolicy::VERSION || $challenge->purpose !== $purpose
                || ! hash_equals($challenge->public_id, $id) || ! hash_equals($challenge->address_key, $locator->address_key)
                || ! hash_equals($challenge->email, $email) || ! hash_equals($challenge->proof_hash, hash('sha256', $proof))) {
                throw new CustomerAccessException;
            }
            if ($challenge->state === 'completed') {
                if (! $this->matches('completion', $completion, $challenge->completion_hash) || ! $this->eligible($user, $account)
                    || $challenge->result_user_id !== $user->id || $challenge->result_account_id !== $account->id
                    || $challenge->result_access_version !== $account->access_version || ! hash_equals($challenge->result_stamp, app(CustomerAccess::class)->stamp($user))) {
                    throw new CustomerAccessException;
                }

                return; // A lost success response can be confirmed; no second credential write or new identity.
            }
            if ($challenge->state !== 'pending' || $challenge->expires_at->lessThanOrEqualTo(now()) || ! $this->eligibleRequest($challenge, $user, $account)) {
                throw new CustomerAccessException;
            }
            if ($purpose === 'enroll') {
                if ($this->emailExists($email)) {
                    throw new CustomerAccessException;
                }
                $user = User::create(['name' => trim($name), 'email' => $email, 'password' => Hash::make($password)]);
                $user->forceFill(['email_verified_at' => now(), 'is_admin' => false])->save();
                $account = app(CustomerAccounts::class)->provision($user);
            } else {
                $user->password = Hash::make($password);
                $user->save(); // All retained customer principals become invalid through their credential stamp.
            }
            $challenge->update(['state' => 'completed', 'completed_at' => now()->startOfSecond(), 'result_user_id' => $user->id,
                'result_account_id' => $account->id, 'result_access_version' => $account->access_version,
                'result_stamp' => app(CustomerAccess::class)->stamp($user), 'completion_hash' => $completionHash]);
            AuditEvent::recordAttributed('customer.test_identity.'.$purpose, $account,
                ['challenge_id' => $challenge->public_id, 'policy_version' => CustomerIdentityPolicy::VERSION, 'test_only' => true], $user->id);
        }, 5);
    }

    private function eligibleRequest(CustomerIdentityChallenge $challenge, ?User $user, ?CustomerAccount $account): bool
    {
        if ($challenge->purpose === 'enroll') {
            return $user === null && ! $this->emailExists($challenge->email);
        }

        return $this->eligible($user, $account) && $challenge->user_id === $user->id && $challenge->account_id === $account->id
            && $challenge->access_version === $account->access_version && hash_equals($challenge->credential_stamp, app(CustomerAccess::class)->stamp($user));
    }

    private function eligible(?User $user, ?CustomerAccount $account): bool
    {
        return $user !== null && ! $user->is_admin && $user->email_verified_at !== null && $account !== null
            && $account->user_id === $user->id && $account->active && $account->access_version > 0;
    }

    private function emailUser(string $email): ?User
    {
        $users = User::whereRaw('LOWER(email) = ?', [$email])->lockForUpdate()->get();

        // A collation-only or legacy-invalid match is unavailable, never a public validation oracle.
        return $users->count() === 1 && strtolower(trim($users[0]->email)) === $email ? $users[0] : null;
    }

    private function emailExists(string $email): bool
    {
        // Current read even when the caller established an older repeatable-read snapshot.
        return User::whereRaw('LOWER(email) = ?', [$email])->lockForUpdate()->get(['id'])->isNotEmpty();
    }

    /** The existing fence under any configured key, else the current-key fence; null when candidates are ambiguous. */
    private function fence(array $addresses): ?string
    {
        if (count($addresses) > 1) {
            $existing = array_values(array_filter(array_map(fn (string $address): ?string => DB::table('customer_identity_addresses')->where('address_key', $address)->lockForUpdate()->value('address_key'), $addresses)));
            if (count($existing) > 1) {
                return null;
            }
            if ($existing !== []) {
                return $existing[0];
            }
        }
        $this->address($addresses[0]);

        return $addresses[0];
    }

    private function address(string $address): void
    {
        DB::table('customer_identity_addresses')->insertOrIgnore(['address_key' => $address]);
        if (! DB::table('customer_identity_addresses')->where('address_key', $address)->lockForUpdate()->first()) {
            throw new CustomerAccessException;
        }
    }

    private function standalone(): void
    {
        app(CustomerIdentityPolicy::class)->requireEnabled();
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Customer identity commands own their transaction.');
        }
    }

    private function purpose(string $purpose): void
    {
        if (! in_array($purpose, ['enroll', 'recover'], true)) {
            throw new CustomerAccessException;
        }
    }

    private function uuid(string $value): void
    {
        if (! preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\z/D', $value)) {
            throw new CustomerAccessException;
        }
    }

    private function proof(string $id, ?string $key = null): string
    {
        // A PRF over an unpredictable UUID permits exact resend without storing the bearer secret in SQL.
        return $this->digest('proof', $id, $key);
    }

    /** The proof originally issued for this challenge, under whichever configured key issued it; null if none. */
    private function issuedProof(CustomerIdentityChallenge $challenge): ?string
    {
        $issued = null;
        foreach ($this->keys() as $key) {
            $proof = $this->proof($challenge->public_id, $key);
            $match = hash_equals($challenge->proof_hash, hash('sha256', $proof));
            $issued = $match && $issued === null ? $proof : $issued;
        }

        return $issued;
    }

    /** New values use the current key only. */
    private function digest(string $purpose, #[SensitiveParameter] string $value, #[SensitiveParameter] ?string $key = null): string
    {
        return hash_hmac('sha256', "customer-local-identity-v1\0".$purpose."\0".$value, $key ?? $this->keys()[0]);
    }

    /** @return non-empty-list<string> current-key digest first */
    private function digests(string $purpose, #[SensitiveParameter] string $value): array
    {
        return array_map(fn (string $key): string => $this->digest($purpose, $value, $key), $this->keys());
    }

    /** Constant-time over every configured key; no candidate short-circuits the others. */
    private function matches(string $purpose, #[SensitiveParameter] string $value, string $expected): bool
    {
        $matched = false;
        foreach ($this->keys() as $key) {
            $matched = hash_equals($this->digest($purpose, $value, $key), $expected) || $matched;
        }

        return $matched;
    }

    /** @return non-empty-list<string> */
    private function keys(): array
    {
        try {
            return IdentityPolicy::keys();
        } catch (IdentityException) {
            throw new CustomerAccessException;
        }
    }
}
