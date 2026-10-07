<?php

namespace App\Domain\SupportAttachments;

use Illuminate\Http\Request;

/** Server registration seam for a separately reviewed identity family. Never derives ownership from an email. */
interface AttachmentActorResolver
{
    public function customer(Request $request, string $sourceKind, string $sourceId): AttachmentActor;
}
