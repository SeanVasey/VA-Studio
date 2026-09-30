<?php

namespace App\Support\Access;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Panel;

/** The admin panel's MFA enrollment rule, shared by staff pages and staff-attributed background actions. */
final class AdminMultiFactor
{
    /** A missing panel fails closed. Without a request, the admin panel's own configuration applies. */
    public static function satisfiedBy(User $user, ?Panel $panel = null): bool
    {
        $panel ??= Filament::getPanel('admin');
        if ($panel === null) {
            return false;
        }
        if (! $panel->isMultiFactorAuthenticationRequired()) {
            return true;
        }
        foreach ($panel->getMultiFactorAuthenticationProviders() as $provider) {
            if ($provider->isEnabled($user)) {
                return true;
            }
        }

        return false;
    }
}
