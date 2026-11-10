<?php

namespace App\Domain\SiteBuilder;

use Illuminate\Validation\ValidationException;

/** A validation refusal, so every existing publication caller already reports it; the findings stay machine-readable. */
final class LegalPublicationRefused extends ValidationException
{
    public const REASON = LegalPublicationGuard::REASON;

    /** @var list<array{document: string, field: string, class: string, excerpt: string}> */
    private array $findings = [];

    public static function because(array $findings): self
    {
        $refusal = self::withMessages(['legal' => [LegalPublicationGuard::describe($findings)]]);
        $refusal->findings = $findings;

        return $refusal;
    }

    /** @return list<array{document: string, field: string, class: string, excerpt: string}> */
    public function findings(): array
    {
        return $this->findings;
    }
}
