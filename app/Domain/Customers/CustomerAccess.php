<?php

namespace App\Domain\Customers;

use App\Domain\Customers\Models\CustomerAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;
use SensitiveParameter;

final class CustomerAccess
{
    public function principal(User $user): CustomerPrincipal
    {
        app(CustomerAccessPolicy::class)->requireEnabled();
        $current = $user->exists ? User::find($user->getKey()) : null;
        $account = $current ? CustomerAccount::where('user_id', $current->id)->first() : null;
        $this->eligible($current, $account);

        return new CustomerPrincipal($account->id, $current->id, $account->owner_key, $account->access_version, $this->stamp($current));
    }

    public function current(CustomerPrincipal $principal): User
    {
        app(CustomerAccessPolicy::class)->requireEnabled();
        $user = User::find($principal->userId);
        $account = CustomerAccount::find($principal->accountId);
        $this->verify($principal, $user, $account);

        return $user;
    }

    /** User -> account always precedes quote/order/control locks. No I/O or HTTP authentication occurs here. */
    public function lock(?CustomerPrincipal $principal, #[SensitiveParameter] string $ownerKey, ?User $actor = null): void
    {
        if ($principal === null) {
            return;
        }
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Customer access requires the command transaction.');
        }
        app(CustomerAccessPolicy::class)->requireEnabled();
        $user = User::whereKey($principal->userId)->lockForUpdate()->first();
        $account = CustomerAccount::whereKey($principal->accountId)->lockForUpdate()->first();
        $this->verify($principal, $user, $account);
        if (! hash_equals($principal->ownerKey, $ownerKey) || ! $actor?->exists || $actor->getKey() !== $principal->userId) {
            throw new CustomerAccessException;
        }
    }

    public function stamp(User $user): string
    {
        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            throw new CustomerAccessException;
        }

        return hash_hmac('sha256', "customer-credential-v1\0".$user->getAuthPassword(), $key);
    }

    private function verify(CustomerPrincipal $principal, ?User $user, ?CustomerAccount $account): void
    {
        $this->eligible($user, $account);
        if ($account->user_id !== $principal->userId || $account->access_version !== $principal->accessVersion
            || ! hash_equals($account->owner_key, $principal->ownerKey)
            || ! hash_equals($this->stamp($user), $principal->credentialStamp)) {
            throw new CustomerAccessException;
        }
    }

    private function eligible(?User $user, ?CustomerAccount $account): void
    {
        if (! $user || ! $account || $account->user_id !== $user->id || ! $account->active || $account->access_version < 1
            || $user->is_admin || $user->email_verified_at === null || ! preg_match('/\A[a-f0-9]{64}\z/D', $account->owner_key)) {
            throw new CustomerAccessException;
        }
    }
}
