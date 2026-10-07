<?php

namespace App\Domain\SupportAttachments;

interface AttachmentSourceAuthority
{
    public function lock(string $sourceId, ?int $expectedVersion, string $purpose, AttachmentActor $actor, AttachmentRows $rows): AttachmentSourceProof;
    public function proveCurrent(AttachmentSourceProof $proof, AttachmentRows $rows): void;
}
