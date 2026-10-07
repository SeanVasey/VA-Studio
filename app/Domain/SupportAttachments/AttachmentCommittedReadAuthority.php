<?php

namespace App\Domain\SupportAttachments;

/** Optional retained-read closure. This receipt never renews the transaction token or permits mutation. */
interface AttachmentCommittedReadAuthority extends AttachmentSourceAuthority
{
    public function committedReadReceipt(AttachmentSourceProof $proof, AttachmentRows $rows): AttachmentCommittedReadReceipt;
}
