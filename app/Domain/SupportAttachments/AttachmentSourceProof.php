<?php

namespace App\Domain\SupportAttachments;

final class AttachmentSourceProof
{
    public function __construct(public readonly AttachmentSourceToken $token) {}
    public function __serialize(): array { throw new \LogicException('Source proof cannot be serialized.'); }
    public function __debugInfo(): array { return ['proved' => true]; }
}
