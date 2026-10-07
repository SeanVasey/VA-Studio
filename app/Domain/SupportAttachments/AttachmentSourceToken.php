<?php

namespace App\Domain\SupportAttachments;

/** Implemented by a server adapter's opaque, privately minted authority token. */
interface AttachmentSourceToken
{
    /** Minimal durable source provenance. Never includes credentials or an ownership capability. */
    public function binding(): array;

    /** Stable, server-derived origin/ownership commitment, distinct from mutable graph revision. */
    public function originBinding(): array;

    public function version(): int;

    /** Stable actor identity, used only for exact-request replay; never emitted in a browser DTO. */
    public function actorBinding(): string;
}
