<?php

namespace App\Domain\SupportAttachments;

/** Admit new bytes/work with the already locked source token; replay keeps retained-read authority. */
interface AttachmentMutationAuthority extends AttachmentSourceAuthority
{
    public function authorizeMutation(AttachmentSourceProof $proof, int $expectedVersion, string $purpose, AttachmentRows $rows): void;
}
