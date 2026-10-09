<?php

namespace App\Domain\Customers\ProductionIdentity\Notifications;

use App\Domain\Customers\ProductionIdentity\IdentityException;
use JsonSerializable;
use LogicException;
use SensitiveParameter;

/** Synchronous private handoff only; tokens never enter queued jobs, HTTP projections or diagnostics. */
final readonly class IdentityMail implements JsonSerializable
{
    public function __construct(public string $noticeId, public string $purpose, public string $provenance,
        #[SensitiveParameter] private string $recipient, #[SensitiveParameter] private string $url)
    {
        if (! preg_match('/\A[a-f0-9-]{36}\z/D', $noticeId) || ! in_array($purpose, ['enroll', 'recover'], true)
            || preg_match('/[\r\n]/', $recipient.$url)) {
            throw new IdentityException;
        }
    }

    public function recipient(): string
    {
        return $this->recipient;
    }

    public function url(): string
    {
        return $this->url;
    }

    public function __debugInfo(): array
    {
        return ['noticeId' => $this->noticeId, 'purpose' => $this->purpose, 'provenance' => $this->provenance];
    }

    public function __serialize(): never
    {
        throw new LogicException('Identity mail must not be serialized.');
    }

    public function jsonSerialize(): never
    {
        throw new LogicException('Identity mail is private.');
    }
}
