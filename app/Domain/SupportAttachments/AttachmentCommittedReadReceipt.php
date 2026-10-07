<?php

namespace App\Domain\SupportAttachments;

/** Source-owned opaque evidence, minted before terminal proof and closed after the original positive commit. */
interface AttachmentCommittedReadReceipt
{
    /** Finish provider callbacks first; then callback-free exact source/actor/config proof on the same idle primary. */
    public function proveClosed(): void;
}
