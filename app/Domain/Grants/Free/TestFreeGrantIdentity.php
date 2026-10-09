<?php

namespace App\Domain\Grants\Free;

use App\Domain\Customers\CustomerAccess;
use App\Domain\Customers\CustomerAccessException;
use App\Domain\Customers\CustomerAccessPolicy;
use App\Domain\Customers\CustomerPrincipal;
use App\Models\User;
use App\Support\Environment\TestEnvironment;

/** Synthetic account provenance only. It cannot enroll, adopt or relabel an operative customer. */
final class TestFreeGrantIdentity implements FreeGrantIdentity
{
    public function principal(User $actor): object
    {
        $this->enabled();

        return app(CustomerAccess::class)->principal($actor);
    }

    public function lock(object $principal, User $actor, FreeGrantRows $rows): array
    {
        $this->enabled();
        $expected = $this->raw($principal, $actor, $rows);
        $this->proveCurrent($principal, $actor, $rows, $expected);

        return $expected;
    }

    public function proveCurrent(object $principal, User $actor, FreeGrantRows $rows, array $expected): void
    {
        FreeGrantException::require($principal instanceof CustomerPrincipal, 403);
        // Framework model callbacks finish before the qualified raw authority fence.
        try {
            app(CustomerAccess::class)->lock($principal, $principal->ownerKey, $actor);
        } catch (CustomerAccessException) {
            throw new FreeGrantException(403);
        }
        $this->provePrimary($principal, $actor, $rows, $expected);
    }

    public function provePrimary(object $principal, User $actor, FreeGrantRows $rows, array $expected): void
    {
        FreeGrantException::require($this->raw($principal, $actor, $rows) === $expected, 403);
        $this->enabled();
        $rows->assertCurrent();
    }

    private function raw(object $principal, User $actor, FreeGrantRows $rows): array
    {
        FreeGrantException::require($principal instanceof CustomerPrincipal && $actor->exists
            && (int) $actor->getKey() === $principal->userId, 403);
        $user = $rows->one('users', 'id = ?', [$principal->userId]);
        $account = $rows->one('customer_accounts', 'id = ?', [$principal->accountId]);
        $hydrated = new User;
        $hydrated->setRawAttributes($user, true);
        $hydrated->exists = true;
        FreeGrantException::require($user !== [] && $account !== [] && in_array($user['is_admin'], [false, 0, '0'], true) && $user['email_verified_at'] !== null
            && in_array($account['active'], [true, 1, '1'], true) && (int) $account['user_id'] === $principal->userId
            && (int) $account['access_version'] === $principal->accessVersion && $principal->accessVersion >= 1
            && hash_equals((string) $account['owner_key'], $principal->ownerKey)
            && hash_equals((new CustomerAccess)->stamp($hydrated), $principal->credentialStamp), 403);

        return compact('user', 'account');
    }

    public function durableBinding(object $principal): array
    {
        FreeGrantException::require($principal instanceof CustomerPrincipal, 403);

        return ['family' => 'test_customer_account_v1', 'user_id' => $principal->userId,
            'account_id' => $principal->accountId, 'provenance' => 'synthetic-local-account', 'legal_identity_verified' => false];
    }

    private function enabled(): void
    {
        FreeGrantException::require(TestEnvironment::admitsTestCommerce(), 404);
        (new CustomerAccessPolicy)->requireEnabled();
        (new FreeGrantPolicy)->requireEnabled();
    }
}
