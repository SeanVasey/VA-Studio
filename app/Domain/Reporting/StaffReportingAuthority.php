<?php

namespace App\Domain\Reporting;

use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Fresh staff authority for every reporting read and export. The user row is re-read, so a model retained by a
 * mounted Livewire component cannot outlive a removed role or a withdrawn MFA enrollment. Hiding a widget, page or
 * navigation item is never the control; each entry point calls this on every request.
 */
final class StaffReportingAuthority
{
    /** The catalog gate plus the admin panel's MFA rule (required in staging and production). */
    public function staff(?User $actor): User
    {
        $current = $actor?->exists ? User::find($actor->getKey()) : null;
        if ($current === null || ! Gate::forUser($current)->allows('administer-catalog') || ! AdminMultiFactor::satisfiedBy($current)) {
            throw new AuthorizationException;
        }

        return $current;
    }

    /**
     * Exports leave the application, so they additionally require an enrolled MFA provider in every environment,
     * even where the panel does not yet require enrollment.
     */
    public function exporter(?User $actor): User
    {
        $current = $this->staff($actor);
        $panel = Filament::getPanel('admin');
        foreach ($panel->getMultiFactorAuthenticationProviders() as $provider) {
            if ($provider->isEnabled($current)) {
                return $current;
            }
        }

        throw new AuthorizationException;
    }

    /** The signed-in panel user, or null. */
    public static function currentUser(): ?User
    {
        $user = Filament::auth()->user();

        return $user instanceof User ? $user : null;
    }

    /** Non-throwing check for navigation and widget visibility only; entry points still call staff(). */
    public static function allows(): bool
    {
        try {
            app(self::class)->staff(self::currentUser());

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }

    /** Livewire boot hook: refuses the request with 403 when authority is missing now. */
    public static function abortUnlessStaff(): void
    {
        abort_unless(self::allows(), 403);
    }
}
