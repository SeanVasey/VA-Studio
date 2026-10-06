<?php

namespace App\Domain\Customers;

use SensitiveParameter;

/** Internal access evidence, never a buyer identity assertion or an HTTP projection. */
final readonly class CustomerPrincipal implements \JsonSerializable
{
    public function __construct(
        public int $accountId,
        public int $userId,
        #[SensitiveParameter] public string $ownerKey,
        public int $accessVersion,
        #[SensitiveParameter] public string $credentialStamp,
    ) {}

    public function __debugInfo(): array
    {
        return ['accountId' => $this->accountId, 'userId' => $this->userId, 'accessVersion' => $this->accessVersion];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Customer access evidence must not be serialized.');
    }

    public function jsonSerialize(): never
    {
        throw new \LogicException('Customer access evidence is not a public projection.');
    }
}
