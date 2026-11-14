<?php

namespace App\Domain\Grants\Member;

/** Physical values only. Public construction never registers a retained receipt. */
final readonly class MemberRetainedOriginalObservation
{
    public function __construct(
        public string $relativePath,
        public string $renderSourceHash,
        public string $sha256,
        public int $sizeBytes,
        public string $profileHash,
        public string $storagePolicyHash,
        public int $device,
        public int $inode,
        public int $observationStartedNs,
        public int $observedAtNs,
    ) {}

    public function __debugInfo(): array
    {
        return ['sha256' => $this->sha256, 'sizeBytes' => $this->sizeBytes];
    }
}
