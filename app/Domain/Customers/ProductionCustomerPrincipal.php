<?php

namespace App\Domain\Customers;

use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Models\User;
use App\Support\CanonicalJson;
use JsonSerializable;
use LogicException;
use SensitiveParameter;

/** Opaque verified account control. Legal name/address and purchase ownership are separate declarations. */
final readonly class ProductionCustomerPrincipal implements JsonSerializable
{
    private function __construct(
        public int $accountId,
        public int $userId,
        public int $accessVersion,
        public string $provenance,
        #[SensitiveParameter] private string $ownerDigest,
        #[SensitiveParameter] private string $credentialBinding,
        private array $binding,
    ) {}

    /** The only mint reads actual server authority. No caller supplies provenance or raw evidence. */
    public static function forUser(User $trustedActor): self
    {
        $proof = app(ProductionCustomerAccess::class)->source($trustedActor);

        return new self((int) $proof['account']['id'], (int) $proof['user']['id'], (int) $proof['account']['access_version'],
            $proof['origin']['provenance'], $proof['owner_digest'], $proof['credential_binding'], $proof['durable_binding']);
    }

    public function matchesPrivate(string $ownerDigest, string $credentialBinding): bool
    {
        return hash_equals($this->ownerDigest, $ownerDigest) && hash_equals($this->credentialBinding, $credentialBinding);
    }

    /** Safe internal historical projection, never credentials, recipient or a bearer proof. */
    public function durableBinding(): array
    {
        return $this->binding;
    }

    /**
     * Only server session state; never a client DTO or durable order binding.
     * Intentionally current-key-only: after an APP_KEY rotation the marker no longer matches and the customer
     * signs in again, which re-issues it. Retained identity evidence still verifies under previous keys.
     */
    public function sessionBindingDigest(): string
    {
        return IdentityPolicy::digest('session', $this->ownerDigest."\0".$this->credentialBinding."\0".$this->accessVersion."\0".CanonicalJson::encode($this->binding));
    }

    public function __debugInfo(): array
    {
        return ['accountId' => $this->accountId, 'userId' => $this->userId,
            'accessVersion' => $this->accessVersion, 'provenance' => $this->provenance];
    }

    public function __serialize(): never
    {
        throw new LogicException('Production customer authority must not be serialized.');
    }

    public function jsonSerialize(): never
    {
        throw new LogicException('Production customer authority is not an HTTP projection.');
    }
}
