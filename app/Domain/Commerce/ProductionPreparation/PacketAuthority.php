<?php

namespace App\Domain\Commerce\ProductionPreparation;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Commerce\ProductionPolicy\MachinePolicyV1 as Check;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\CanonicalJson;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;

/** Historical staff evidence access, with no current purchase or capability authority. */
final class PacketAuthority
{
    public static function raw(CurrentRows $reader, int $id): array
    {
        $audits = $reader->audits(User::class, $id, 257);
        Check::require(count($audits) <= 256);

        return ['row' => $reader->one('users', $id), 'audits' => $audits];
    }

    public static function run(User $actor, Closure $prepare, Closure $proof): mixed
    {
        $connection = DB::connection();
        if ($connection->transactionLevel() !== 0 || ! in_array($connection->getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new LogicException('Production preparation requires a supported standalone transaction.');
        }
        $primary = $connection->getPdo();
        $driver = $connection->getDriverName();

        return $connection->transaction(function () use ($actor, $prepare, $proof, $primary, $driver): mixed {
            $reader = new CurrentRows($primary, $driver);
            $authority = self::authorize($actor, $reader);
            $result = $prepare($reader);
            $after = self::authorize($actor, $reader);
            if (CanonicalJson::encode($authority) !== CanonicalJson::encode($after)) {
                throw new AuthorizationException;
            }
            Check::require($proof($reader) === null);
            if (CanonicalJson::encode($authority) !== CanonicalJson::encode(self::raw($reader, $actor->id))) {
                throw new AuthorizationException;
            }

            return $result;
        });
    }

    private static function authorize(User $actor, CurrentRows $reader): array
    {
        $user = $actor->exists && is_int($actor->getKey()) && $actor->id > 0 ? User::query()->lockForUpdate()->find($actor->id) : null;
        if ($user === null) {
            throw new AuthorizationException;
        }
        Gate::forUser($user)->authorize('administer-catalog', [true]);
        if (! AdminMultiFactor::satisfiedBy($user, lockForUpdate: true)) {
            throw new AuthorizationException;
        }
        $authority = self::raw($reader, $user->id);
        if (CanonicalJson::encode($authority['row']) !== CanonicalJson::encode($user->getAttributes())) {
            throw new AuthorizationException;
        }

        return $authority;
    }
}
