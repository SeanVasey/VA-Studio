<?php

namespace App\Domain\Grants\Member;

/** Internal physical values only. Public construction confers no source or readiness authority. */
final readonly class MemberOriginalFileObservation
{
    public function __construct(
        public string $relativePath,
        public string $renderSourceHash,
        public string $sha256,
        public int $sizeBytes,
        public int $pageCount,
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
