<?php

namespace App\Domain\SupportAttachments;

use App\Support\CanonicalJson;

/** An additive, explicit source/policy catalog. No inference from account or email. */
final class AttachmentRegistry
{
    public function __construct(private array $sources = [], private array $policies = []) {}

    public function source(string $kind): AttachmentSourceAuthority
    {
        $source = $this->sources[$kind] ?? null;
        AttachmentException::require($source instanceof AttachmentSourceAuthority);
        return $source;
    }

    public function policy(array $binding, ?string $expectedHash = null): AttachmentPolicy
    {
        $policy = $this->policies[$binding['family'] ?? ''] ?? null;
        AttachmentException::require($policy instanceof AttachmentPolicy);
        $policy->assertCurrent($binding);
        if ($expectedHash !== null) {
            AttachmentException::require(hash_equals($expectedHash, self::hash($policy->commitment())));
        }
        AttachmentException::require($policy->maxBytes() >= 1 && $policy->maxBytes() <= 5242880
            && $policy->maxFiles() >= 1 && $policy->maxFiles() <= 10 && $policy->lifetimeSeconds() >= 1 && $policy->lifetimeSeconds() <= 86400);
        return $policy;
    }

    public static function hash(array $value): string { return hash('sha256', CanonicalJson::encode($value)); }
}
