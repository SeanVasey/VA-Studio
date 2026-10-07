<?php

namespace App\Domain\Commerce\ProductionCheckout;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Commerce\ProductionPreparation\PacketAuthority;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use Illuminate\Support\Facades\Gate;

/** Authenticates an administrative action; does not prove tax expertise or any external fact. */
final class StaffProof
{
    public static function lock(User $actor, CurrentRows $reader): array
    {
        CheckoutException::require($actor->exists && is_int($actor->getKey()) && $actor->id > 0, 'authority', 403);
        $current = User::query()->lockForUpdate()->find($actor->id);
        CheckoutException::require($current !== null, 'authority', 403);
        Gate::forUser($current)->authorize('administer-catalog', [true]);
        CheckoutException::require(AdminMultiFactor::satisfiedBy($current, lockForUpdate: true), 'authority', 403);
        $raw = PacketAuthority::raw($reader, $actor->id);
        Evidence::same($current->getAttributes(), $raw['row']);

        return $raw;
    }

    public static function proveCurrent(User $actor, CurrentRows $reader, array $expected): void
    {
        Evidence::same($expected, PacketAuthority::raw($reader, $actor->id));
    }
}
