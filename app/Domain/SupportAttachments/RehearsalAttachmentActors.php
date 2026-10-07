<?php

namespace App\Domain\SupportAttachments;

use App\Support\CommerceRequestIdentity;
use Illuminate\Http\Request;
use Throwable;

/** Existing rehearsal identity remains distinct from new verified production principals. */
final class RehearsalAttachmentActors implements AttachmentActorResolver
{
    public function __construct(private CommerceRequestIdentity $identity) {}

    public function customer(Request $request, string $sourceKind, string $sourceId): AttachmentActor
    {
        try {
            $principal = $this->identity->principal($request);
            $actor = $this->identity->actor($request);
        } catch (Throwable) {
            throw new AttachmentException(403);
        }
        AttachmentException::require($principal !== null && $actor !== null, 403);

        return AttachmentActor::customer($actor, $principal);
    }
}
