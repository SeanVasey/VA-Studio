<?php

namespace App\Domain\SupportAttachments;

/** Registered server policy, not an HTTP switch. Future production policies are separate reviewed implementations. */
interface AttachmentPolicy
{
    public function commitment(): array;

    /** Callback-free configuration/clock assertions; no framework queries, network, or caller callbacks. */
    public function assertCurrent(array $sourceBinding): void;

    public function maxBytes(): int;

    public function maxFiles(): int;

    public function lifetimeSeconds(): int;

    public function allowsMime(string $mime): bool;

    public function allowsScanEngine(string $engine): bool;
}
