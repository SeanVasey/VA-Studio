<?php

namespace App\Domain\SupportAttachments;

use App\Models\User;

/** Minted by a server route, never deserialized from an HTTP body. */
final class AttachmentActor
{
    private function __construct(public readonly string $audience, public readonly ?User $user,
        public readonly mixed $principal, private readonly ?string $ownerHash) {}

    public static function visitor(string $serverOwnerHash, ?User $user = null): self
    {
        AttachmentException::require(preg_match('/\A[a-f0-9]{64}\z/D', $serverOwnerHash) === 1);
        return new self('visitor', $user, null, $serverOwnerHash);
    }

    public static function customer(User $trustedUser, object $serverPrincipal): self { return new self('customer', $trustedUser, $serverPrincipal, null); }
    public static function operator(User $trustedUser): self { return new self('operator', $trustedUser, null, null); }
    public function ownerHash(): ?string { return $this->ownerHash; }
    public function __serialize(): array { throw new \LogicException('Attachment actor cannot be serialized.'); }
    public function __debugInfo(): array { return ['audience' => $this->audience]; }
}
