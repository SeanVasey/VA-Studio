<?php

namespace App\Domain\Catalog\Discovery;

/** Internal capture, not a permanently current publication or commerce attestation. */
final readonly class EligibleTrackSnapshot
{
    public function __construct(private array $paths, private ?string $continuation, private string $evidence) {}

    public function paths(): array
    {
        return $this->paths;
    }

    public function continuation(): ?string
    {
        return $this->continuation;
    }

    public function evidence(): string
    {
        return $this->evidence;
    }
}
