<?php

namespace App\Domain\Customers\ProductionIdentity;

use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Timebox;
use SensitiveParameter;

final class ProductionCustomerSessions
{
    public function authenticate(#[SensitiveParameter] string $email, #[SensitiveParameter] string $password): ?array
    {
        $policy = new IdentityPolicy;
        $policy->requireEnabled();
        $policy->outsideTransactions();
        $email = IdentityPolicy::email($email);
        if (strlen($password) < 1 || strlen($password) > 72 || str_contains($password, "\0")) {
            return null;
        }

        return (new Timebox)->call(function () use ($email, $password): ?array {
            try {
                $database = new IdentityDatabase;
                $database->close(false);
                $dummy = Hash::make('Synthetic invalid identity credential123');
                $user = $database->connection->transaction(function () use ($database, $email, $password, $dummy): ?array {
                    $users = $database->rows->rows('users', 'LOWER(email) = ?', [$email], 2);
                    $user = count($users) === 1 ? $users[0] : [];
                    $valid = Hash::check($password, $user['password'] ?? $dummy);
                    if (! $valid || $user === []) {
                        return null;
                    }
                    $database->same($user, $database->rows->one('users', (int) $user['id']));
                    $database->close(true);

                    return $user;
                });
                if ($user === null) {
                    return null;
                }
                try {
                    $actor = (new User)->newFromBuilder($user);
                    $access = new ProductionCustomerAccess;
                    $principal = $access->principal($actor);
                    $proof = $access->current($principal, $actor);
                    // Pin the credential actually checked. Rehashing would require a new retained observation.
                    $database->same($user, $proof['user']);
                    $database->close(false);

                    return ['user' => $actor, 'principal' => $principal];
                } catch (IdentityException) {
                    return null;
                }
            } catch (IdentityException) {
                return null;
            }
        }, 200000);
    }

    public function principal(Request $request): ProductionCustomerPrincipal
    {
        $actor = Auth::guard('customer')->user();
        $marker = $request->session()->get('_production_customer_identity');
        if (! $actor instanceof User || ! is_array($marker) || array_keys($marker) !== ['binding_digest'] || ! is_string($marker['binding_digest'])) {
            throw new IdentityException;
        }
        $principal = (new ProductionCustomerAccess)->principal($actor);
        if (! hash_equals($principal->sessionBindingDigest(), $marker['binding_digest'])) {
            throw new IdentityException;
        }
        (new ProductionCustomerAccess)->current($principal, $actor);

        return $principal;
    }
}
