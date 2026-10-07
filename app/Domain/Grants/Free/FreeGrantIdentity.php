<?php

namespace App\Domain\Grants\Free;

use App\Models\User;

/** Server-owned current identity adapter; principals and raw proofs are never accepted from HTTP. */
interface FreeGrantIdentity
{
    public function principal(User $actor): object;

    public function lock(object $principal, User $actor, FreeGrantRows $rows): array;

    public function proveCurrent(object $principal, User $actor, FreeGrantRows $rows, array $expected): void;

    /** Final callback-free qualified raw identity fence after source decryption/projection preparation. */
    public function provePrimary(object $principal, User $actor, FreeGrantRows $rows, array $expected): void;

    public function durableBinding(object $principal): array;
}
