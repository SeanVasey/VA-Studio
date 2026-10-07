<?php

namespace App\Domain\Grants\Free;

use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use Illuminate\Support\Facades\Gate;

final class FreeGrantStaff
{
    public function lock(User $actor, FreeGrantRows $rows): array
    {
        FreeGrantException::require($actor->exists && (int) $actor->getKey() > 0, 403);
        $raw = $rows->one('users', 'id = ?', [(int) $actor->getKey()]);
        FreeGrantException::require($raw !== [] && in_array($raw['is_admin'], [true, 1, '1'], true) && in_array($actor->getRawOriginal('is_admin'), [true, 1, '1'], true) && $raw['email_verified_at'] !== null, 403);
        foreach (['password', 'email', 'email_verified_at', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes'] as $field) {
            FreeGrantException::require(($raw[$field] ?? null) === $actor->getRawOriginal($field), 403);
        }
        $this->proveCurrent($actor, $rows, $raw);

        return $raw;
    }

    public function proveCurrent(User $actor, FreeGrantRows $rows, array $expected): void
    {
        $user = new User;
        $user->setRawAttributes($expected, true);
        $user->exists = true;
        FreeGrantException::require(Gate::forUser($user)->allows('administer-catalog', [true])
            && AdminMultiFactor::satisfiedBy($user, lockForUpdate: true), 403);
        $this->provePrimary($actor, $rows, $expected);
    }

    public function provePrimary(User $actor, FreeGrantRows $rows, array $expected): void
    {
        FreeGrantException::require($rows->one('users', 'id = ?', [(int) $actor->getKey()]) === $expected, 403);
        (new FreeGrantPolicy)->requireEnabled();
        $rows->assertCurrent();
    }
}
