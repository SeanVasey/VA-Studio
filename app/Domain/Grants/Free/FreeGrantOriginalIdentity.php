<?php

namespace App\Domain\Grants\Free;

use App\Models\User;

/** Optional immutable original-owner proof; a current account alone cannot adopt a retained origin. */
interface FreeGrantOriginalIdentity
{
    public function lockOriginal(object $principal, User $actor, array $binding, FreeGrantRows $rows): array;

    public function proveOriginalPrimary(object $principal, User $actor, array $binding, FreeGrantRows $rows, array $expected): void;
}
