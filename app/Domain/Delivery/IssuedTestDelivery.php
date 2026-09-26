<?php

namespace App\Domain\Delivery;

use Carbon\CarbonImmutable;
use SensitiveParameter;

/** The secret exists only in the response to a newly committed authorization. Never log or serialize this DTO. */
final class IssuedTestDelivery
{
    public function __construct(public readonly string $authorizationId, #[SensitiveParameter] private readonly string $secret,
        public readonly CarbonImmutable $expiresAt, public readonly string $filename, public readonly string $mimeType) {}

    public function token(): string { return $this->secret; }
    public function __debugInfo(): array { return ['authorizationId' => $this->authorizationId, 'expiresAt' => $this->expiresAt]; }
    public function __serialize(): array { throw new \LogicException('Delivery secrets must not be serialized.'); }
}
