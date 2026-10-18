<?php

namespace App\Domain\Customers\TestIdentityLinks;

use Carbon\CarbonImmutable;
use SensitiveParameter;

/**
 * One captured test identity message that passed every check of {@see TestIdentityLinkRelay::messages()}: a plain
 * private file whose exact shape, origin, recipient, proof hash and expiry match its retained test challenge, for a
 * reserved synthetic address. The link is a bearer credential, so it is not a public property and never appears in a
 * dump; only {@see link()} returns it, and only the relay's print path calls that.
 */
final class TestIdentityLinkMessage
{
    public const PENDING = 'pending';

    public const EXPIRED = 'expired';

    public const USED = 'used';

    public function __construct(
        public readonly int $challengeKey,
        public readonly string $id,
        public readonly string $purpose,
        public readonly string $recipient,
        public readonly CarbonImmutable $createdAt,
        public readonly CarbonImmutable $expiresAt,
        public readonly ?CarbonImmutable $completedAt,
        public readonly string $status,
        #[SensitiveParameter] private readonly string $link,
    ) {}

    public function link(): string
    {
        return $this->link;
    }

    /** Debug output and dumps omit the link. */
    public function __debugInfo(): array
    {
        return ['id' => $this->id, 'purpose' => $this->purpose, 'status' => $this->status];
    }
}
