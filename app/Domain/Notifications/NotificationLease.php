<?php

namespace App\Domain\Notifications;

use Carbon\CarbonImmutable;
use SensitiveParameter;

/** Internal claim evidence; neither the token nor private capture bytes is an HTTP projection. */
final readonly class NotificationLease implements \JsonSerializable
{
    public function __construct(
        public string $notificationId,
        public string $attemptId,
        public CarbonImmutable $expiresAt,
        #[SensitiveParameter] private string $token,
        #[SensitiveParameter] private string $capture,
    ) {}

    public function token(): string
    {
        return $this->token;
    }

    public function capture(): string
    {
        return $this->capture;
    }

    public function __debugInfo(): array
    {
        return ['notificationId' => $this->notificationId, 'attemptId' => $this->attemptId,
            'expiresAt' => $this->expiresAt->toIso8601ZuluString()];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Private notification claims must not be serialized.');
    }

    public function jsonSerialize(): never
    {
        throw new \LogicException('Private notification claims are not public projections.');
    }
}
