<?php

namespace App\Domain\Memberships\Production;

use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;

/** Current locked operator, catalogue permission and enrolled MFA on every authoring call. */
final class MembershipPolicyStaff
{
    private const PINNED = ['password', 'email', 'email_verified_at', 'remember_token', 'is_admin',
        'app_authentication_secret', 'app_authentication_recovery_codes'];

    public function lock(User $actor, MembershipRows $rows): array
    {
        MembershipException::require($actor->exists && (int) $actor->getKey() > 0, 'staff_refused');
        $raw = $rows->current()->one('users', (int) $actor->getKey());
        MembershipException::require($raw !== [] && in_array($raw['is_admin'], [true, 1, '1'], true)
            && $raw['email_verified_at'] !== null, 'staff_refused');
        foreach (self::PINNED as $field) {
            MembershipException::require((string) ($raw[$field] ?? '') === (string) ($actor->getRawOriginal($field) ?? ''), 'staff_refused');
        }
        $this->proveCurrent($actor, $rows, $raw);

        return $raw;
    }

    public function proveCurrent(User $actor, MembershipRows $rows, array $expected): void
    {
        $user = new User;
        $user->setRawAttributes($expected, true);
        $user->exists = true;
        MembershipException::require(Gate::forUser($user)->allows('administer-catalog', [true]), 'staff_refused');
        MembershipException::require(AdminMultiFactor::satisfiedBy($user, lockForUpdate: true), 'mfa_required');
        $current = User::query()->lockForUpdate()->find($actor->getKey());
        $enrolled = false;
        if ($current !== null) {
            foreach (Filament::getPanel('admin')->getMultiFactorAuthenticationProviders() as $provider) {
                $enrolled = $provider->isEnabled($current) || $enrolled;
            }
        }
        // The panel may not require challenges in testing; enrollment is still mandatory here.
        MembershipException::require($enrolled, 'mfa_required');
        MembershipException::require(MembershipRecords::strings($rows->current()->one('users', (int) $actor->getKey()))
            === MembershipRecords::strings($expected), 'staff_refused');
        $rows->assertCurrent();
    }
}
