<?php

namespace App\Domain\Contracts\Production;

use RuntimeException;

/**
 * A production contract variable could not be bound or substituted. Nothing is ever filled in silently: every
 * refusal names a fixed reason and, where one is involved, the template placeholder.
 */
final class ContractBindingException extends RuntimeException
{
    public const REASONS = [
        'unsupported_input', 'contract_identity_missing', 'contract_identity_invalid', 'license_type_unsupported',
        'license_schema_unsupported', 'license_terms_mismatch', 'license_terms_invalid', 'publishing_share_unbound', 'currency_unsupported', 'amount_invalid', 'number_out_of_range', 'value_invalid', 'unsupported_characters',
        'unknown_placeholder', 'missing_value', 'malformed_template', 'doubled_symbol',
    ];

    public function __construct(public readonly string $reason, public readonly ?string $placeholder = null)
    {
        parent::__construct('Production contract variables are unavailable.');
    }

    public static function require(bool $condition, string $reason, ?string $placeholder = null): void
    {
        if (! $condition) {
            throw new self($reason, $placeholder);
        }
    }
}
