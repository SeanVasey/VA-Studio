<?php

namespace App\Domain\Grants\ProductionFree;

use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
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

    /**
     * Gate, enrolled MFA and the exact locked row, after every callback. The admin panel requires MFA only in
     * production, where family 256 cannot run, so `AdminMultiFactor` alone would be a no-op here: enrollment is
     * required unconditionally for every 256 staff action. The session challenge stays at the panel mount.
     */
    public function proveCurrent(User $actor, ProductionFreeGrantRows $rows, array $expected): void
    {
        $user = new User;
        $user->setRawAttributes($expected, true);
        $user->exists = true;
        ProductionFreeGrantException::require(Gate::forUser($user)->allows('administer-catalog', [true]), 'staff_refused');
        ProductionFreeGrantException::require(AdminMultiFactor::satisfiedBy($user, lockForUpdate: true) && $this->enrolled($user), 'mfa_required');
        ProductionFreeGrantException::require(ProductionFreeGrantRecords::strings($rows->parent('users', (int) $actor->getKey()))
            === ProductionFreeGrantRecords::strings($expected), 'staff_refused');
        $rows->assertCurrent();
    }

    /** The current, locked row has an enabled MFA provider of the admin panel, whether or not the panel requires it. */
    private function enrolled(User $user): bool
    {
        if (DB::transactionLevel() === 0 || ! $user->exists) {
            return false;
        }
        $current = User::query()->lockForUpdate()->find($user->getKey());
        $panel = Filament::getPanel('admin');
        if ($current === null || $panel === null) {
            return false;
        }
        foreach ($panel->getMultiFactorAuthenticationProviders() as $provider) {
            if ($provider->isEnabled($current)) {
                return true;
            }
        }

        return false;
    }
}
