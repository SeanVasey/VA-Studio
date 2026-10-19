<?php

namespace App\Domain\Memberships\Production;

use JsonSerializable;

/**
 * Server-only pre-bytes closure for one prebuilt customer index response. It holds no state of its own: the index
 * registers the original principal, actor, deadline, projection and gate baselines privately. It carries no browser
 * evidence, renews no deadline and is never membership, invoice or grant authority.
 */
final class MembershipCustomerRead implements JsonSerializable
{
    private function __construct() {}

    public static function begin(): self
    {
        return new self;
    }

    /** Call immediately before releasing the prebuilt body; any refusal means the body must not be sent. */
    public function proveBeforeBytes(): void
    {
        MembershipCustomerIndex::proveCapturedBeforeBytes($this);
    }

    public function __serialize(): array
    {
        throw new MembershipException('serialization_refused');
    }

    public function __unserialize(array $data): void
    {
        throw new MembershipException('serialization_refused');
    }

    public function jsonSerialize(): never
    {
        throw new MembershipException('serialization_refused');
    }

    public function __clone(): void
    {
        throw new MembershipException('clone_refused');
    }

    public function __debugInfo(): array
    {
        return ['membership_customer_read' => true];
    }
}
