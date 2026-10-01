<?php

namespace App\Support\Access;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Support\Facades\DB;

/** The admin panel's MFA enrollment rule, shared by staff pages and staff-attributed background actions. */
final class AdminMultiFactor
{
    /** A missing panel fails closed. Without a request, the admin panel's own configuration applies. */
    public static function satisfiedBy(User $user, ?Panel $panel = null, bool $lockForUpdate = false): bool
    {
        if ($lockForUpdate && DB::transactionLevel() === 0) {
            return false;
        }
        // Enrollment removal must take effect even when a caller retained the old secret.
        $current = $user->exists ? ($lockForUpdate
            ? User::query()->lockForUpdate()->find($user->getKey())
            : User::find($user->getKey())) : null;
        if ($current === null) {
            return false;
        }
        $panel ??= Filament::getPanel('admin');
        if ($panel === null) {
            return false;
        }
        if (! $panel->isMultiFactorAuthenticationRequired()) {
            return true;
        }
        foreach ($panel->getMultiFactorAuthenticationProviders() as $provider) {
            if ($provider->isEnabled($current)) {
                return true;
            }
        }

        return false;
    }
}
