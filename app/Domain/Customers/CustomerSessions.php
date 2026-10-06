<?php

namespace App\Domain\Customers;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Timebox;
use LogicException;
use SensitiveParameter;

final class CustomerSessions
{
    /** Verify the credential under the same user fence as reset/withdrawal; never stamp a later password. */
    public function authenticate(#[SensitiveParameter] string $email, #[SensitiveParameter] string $password): ?array
    {
        app(CustomerAccessPolicy::class)->requireEnabled();
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Customer sign-in requires a standalone transaction.');
        }

        return (new Timebox)->call(function () use ($email, $password): ?array {
            // Do the same configured hashing work for an unknown address. This value grants no identity.
            $dummy = Hash::make('Synthetic invalid customer credential');

            return DB::transaction(function () use ($email, $password, $dummy): ?array {
                $users = User::whereRaw('LOWER(email) = ?', [strtolower(trim($email))])->lockForUpdate()->get();
                $user = $users->count() === 1 && strtolower(trim($users->first()->email)) === strtolower(trim($email)) ? $users->first() : null;
                $hash = $user?->getAuthPassword() ?? $dummy;
                if (! Hash::check($password, $hash) || ! $user) {
                    return null;
                }
                try {
                    $access = app(CustomerAccess::class);
                    $principal = $access->principal($user);
                    $access->lock($principal, $principal->ownerKey, $user);
                    if (! hash_equals($hash, $user->fresh()->getAuthPassword())) {
                        throw new CustomerAccessException;
                    }
                    // Rehash while the exact verified credential remains fenced, before deriving the session stamp.
                    if (Hash::needsRehash($hash)) {
                        $user->password = Hash::make($password);
                        $user->save();
                    }
                    $principal = $access->principal($user);
                    $access->lock($principal, $principal->ownerKey, $user);

                    return ['user' => $user, 'principal' => $principal];
                } catch (CustomerAccessException) {
                    return null;
                }
            }, 5);
        }, 200000);
    }
}
