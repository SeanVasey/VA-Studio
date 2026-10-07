<?php

namespace App\Domain\SupportAttachments;

/** Accepted only with the issuing adapter's private WeakMap proof. */
final class InquiryAttachmentToken implements AttachmentSourceToken
{
    private function __construct(private array $binding, private array $origin, private int $revision, private string $actor) {}

    public static function capture(array $binding, array $origin, int $version, string $actor): self
    {
        return new self($binding, $origin, $version, $actor);
    }

    public function binding(): array
    {
        return $this->binding;
    }

    public function originBinding(): array
    {
        return $this->origin;
    }

    public function version(): int
    {
        return $this->revision;
    }

    public function actorBinding(): string
    {
        return $this->actor;
    }

    public function __serialize(): array
    {
        throw new \LogicException('Source token cannot be serialized.');
    }

    public function __debugInfo(): array
    {
        return ['proved' => true];
    }
}
