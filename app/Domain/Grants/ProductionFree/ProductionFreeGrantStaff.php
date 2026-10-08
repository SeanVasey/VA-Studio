<?php

namespace App\Domain\Grants\ProductionFree;

use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use Illuminate\Support\Facades\Gate;

/** Server-side staff authority, as the existing staff writers prove it. A hidden UI action is not authority. */
final class ProductionFreeGrantStaff
{
    private const PINNED = ['password', 'email', 'email_verified_at', 'remember_token', 'is_admin',
        'app_authentication_secret', 'app_authentication_recovery_codes'];

    public function lock(User $actor, ProductionFreeGrantRows $rows): array
    {
        ProductionFreeGrantException::require($actor->exists && (int) $actor->getKey() > 0, 'staff_refused');
        $raw = $rows->parent('users', (int) $actor->getKey());
        ProductionFreeGrantException::require($raw !== [] && in_array($raw['is_admin'], [true, 1, '1'], true)
            && $raw['email_verified_at'] !== null, 'staff_refused');
        foreach (self::PINNED as $field) {
            ProductionFreeGrantException::require((string) ($raw[$field] ?? '') === (string) ($actor->getRawOriginal($field) ?? ''), 'staff_refused');
        }
        $this->proveCurrent($actor, $rows, $raw);

        return $raw;
    }

    /** Gate, enrolled MFA under the admin panel's rule and the exact locked row, after every callback. */
    public function proveCurrent(User $actor, ProductionFreeGrantRows $rows, array $expected): void
    {
        $user = new User;
        $user->setRawAttributes($expected, true);
        $user->exists = true;
        ProductionFreeGrantException::require(Gate::forUser($user)->allows('administer-catalog', [true]), 'staff_refused');
        ProductionFreeGrantException::require(AdminMultiFactor::satisfiedBy($user, lockForUpdate: true), 'mfa_required');
        ProductionFreeGrantException::require(ProductionFreeGrantRecords::strings($rows->parent('users', (int) $actor->getKey()))
            === ProductionFreeGrantRecords::strings($expected), 'staff_refused');
        $rows->assertCurrent();
    }
}
