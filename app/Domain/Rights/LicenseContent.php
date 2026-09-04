<?php

namespace App\Domain\Rights;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class LicenseContent
{
    public function validate(array $content): array
    {
        $source = $content['authored_source'] ?? null;
        if (! is_string($source) || ! mb_check_encoding($source, 'UTF-8') || trim($source) === '' || strlen($source) > 100000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $source)) {
            throw ValidationException::withMessages(['authored_source' => 'Provide valid plain UTF-8 source text, at most 100,000 bytes, without control characters.']);
        }
        if (! is_array($content['structured_terms'] ?? null)) {
            throw ValidationException::withMessages(['structured_terms' => 'Structured terms must be an object.']);
        }
        $terms = app(LicenseTerms::class)->validate($content['structured_terms']);
        $dates = Validator::make($content, ['effective_from' => ['nullable', 'date'], 'effective_until' => ['nullable', 'date']])->validate();
        $from = $this->date($dates['effective_from'] ?? null);
        $until = $this->date($dates['effective_until'] ?? null);
        if ($until && $from && $until->lessThanOrEqualTo($from)) {
            throw ValidationException::withMessages(['effective_until' => 'Effective end must follow the start.']);
        }

        return ['authored_source' => $source, 'structured_terms' => $terms, 'effective_from' => $from, 'effective_until' => $until];
    }

    private function date(string|DateTimeInterface|null $date): ?CarbonImmutable
    {
        return $date === null || $date === '' ? null : CarbonImmutable::parse($date, 'UTC')->utc()->startOfSecond();
    }
}
