<?php

namespace App\Domain\Customers;

use App\Domain\Customers\Models\CustomerAccount;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Internal synthetic-fixture provisioning only. No public registration, email matching or guest claim. */
final class CustomerAccounts
{
    public function provision(User $user): CustomerAccount
    {
        app(CustomerAccessPolicy::class)->requireEnabled();

        return DB::transaction(function () use ($user): CustomerAccount {
            $current = $user->exists ? User::whereKey($user->getKey())->lockForUpdate()->first() : null;
            if (! $current || $current->is_admin || $current->email_verified_at === null) {
                throw new CustomerAccessException;
            }
            $existing = CustomerAccount::where('user_id', $current->id)->lockForUpdate()->first();
            if ($existing) {
                return $existing; // Never revive withdrawn access or change the owner on a provisioning retry.
            }
            $owner = bin2hex(random_bytes(32));
            DB::table('quote_owners')->insert(['owner_key' => $owner]);
            $account = CustomerAccount::create(['public_id' => (string) Str::uuid(), 'user_id' => $current->id,
                'owner_key' => $owner, 'active' => true, 'access_version' => 1]);
            AuditEvent::recordAttributed('customer.test_account.provisioned', $account, ['public_id' => $account->public_id, 'test_only' => true], null);

            return $account;
        }, 5);
    }
}
