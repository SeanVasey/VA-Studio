<?php

namespace App\Support;

use App\Domain\SupportAttachments\AttachmentActor;
use App\Domain\SupportAttachments\AttachmentActorResolver;
use App\Domain\SupportAttachments\AttachmentException;
use App\Domain\SupportAttachments\RehearsalAttachmentActors;
use Illuminate\Http\Request;

/** Audience and kind are closed server route defaults, never body authority. */
final class SupportAttachmentRequestIdentity
{
    public function actor(Request $request): AttachmentActor
    {
        return match ($request->route('support_audience')) {
            'visitor' => AttachmentActor::visitor(app(InquiryOwner::class)->forRequest($request), $request->user()),
            'operator' => $request->user() === null ? throw new AttachmentException(403) : AttachmentActor::operator($request->user()),
            'customer' => $this->customer($request),
            default => throw new AttachmentException(403),
        };
    }

    private function customer(Request $request): AttachmentActor
    {
        $resolver = app()->bound(AttachmentActorResolver::class) ? app(AttachmentActorResolver::class) : app(RehearsalAttachmentActors::class);

        return $resolver->customer($request, $request->route('support_kind'), $request->route('source'));
    }
}
