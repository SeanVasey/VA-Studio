<?php

namespace App\Domain\Contracts\Production;

/**
 * The two license-constant placeholders. Their values are fixed per license source by its author (increment 1) and
 * handed to the binder by the caller; the binder never supplies a default, so no seller name or governing
 * jurisdiction is invented in code.
 */
final readonly class ContractLicenseConstants
{
    private const BYTES = 160;

    public function __construct(public string $producerAlias, public string $stateProvinceCountry)
    {
        self::text($producerAlias, 'PRODUCER_ALIAS');
        self::text($stateProvinceCountry, 'STATE_PROVINCE_COUNTRY');
    }

    /** Trimmed, bounded UTF-8 with no control characters and no braces, so a constant can never look like a placeholder. */
    private static function text(string $value, string $placeholder): void
    {
        ContractBindingException::require($value !== '' && strlen($value) <= self::BYTES && mb_check_encoding($value, 'UTF-8')
            && trim($value) === $value && preg_match('/[\x00-\x1f\x7f{}]/', $value) !== 1, 'value_invalid', $placeholder);
    }
}
