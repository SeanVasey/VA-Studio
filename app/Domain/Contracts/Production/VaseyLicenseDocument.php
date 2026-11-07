<?php

namespace App\Domain\Contracts\Production;

use App\Domain\Commerce\ContractIdentity\ContractGlyphCoverage;
use App\Domain\Commerce\ContractIdentity\ContractIdentityDeclarationV2;
use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Support\CanonicalJson;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The `vasey-license-text-v1` document for one paid line: the license version's frozen template text with its
 * placeholders substituted in one pass ({@see ContractVariableBinder::substitute}) from the binder's output, split into
 * layout blocks, plus the acceptance block built outside the template from the frozen v2 declaration.
 *
 * Layout grammar (pinned by the profile's `template` identifier): blocks are separated by one or more blank lines.
 * The first block is the document title. A one-line block whose letters are all uppercase is a section heading.
 * Every other block is a paragraph; its single line breaks are kept. Tabs, carriage returns and other control
 * characters refuse.
 *
 * Refusals are {@see ContractBindingException} reasons: `malformed_template`, `doubled_symbol` (a template symbol next
 * to a placeholder that already prints it), `unsupported_input` (values that are not the binder's map or disagree
 * with the declaration), `contract_identity_invalid`, and `unsupported_characters` (a value the profile's faces cannot
 * print), plus the substitution refusals.
 */
final readonly class VaseyLicenseDocument
{
    public const TEMPLATE_BYTES = 1048576;

    private const HEADING_CHARACTERS = 120;

    /**
     * @param  list<array{type: string, text: string}>  $blocks
     * @param  array{licensor: string, licensee: string, representative_line: ?string, date: string}  $acceptance
     */
    private function __construct(
        public array $blocks,
        public array $acceptance,
        public int $timestamp,
        public string $title,
        public string $inputHash,
        public string $text,
    ) {}

    /**
     * @param  string  $template  The license version's frozen template text (`{NAME}` placeholders).
     * @param  array<string, string>  $values  {@see ContractVariableBinder::bind} output for the same line.
     * @param  array  $declaration  The line's frozen `buyer-contract-identity-v2` declaration.
     * @param  ContractGlyphCoverage  $coverage  The profile's own faces ({@see ContractGlyphCoverage::vaseyLicenseFaces}).
     */
    public static function build(string $template, array $values, array $declaration, ContractGlyphCoverage $coverage): self
    {
        ContractBindingException::require($template !== '' && strlen($template) <= self::TEMPLATE_BYTES && mb_check_encoding($template, 'UTF-8')
            && preg_match('/[\x00-\x09\x0b-\x1f\x7f]/', $template) !== 1, 'malformed_template');

        // Exactly the binder's map: its closed set, in its order, with the Exclusive-only alias present iff Exclusive.
        $exclusive = array_key_exists('CUSTOMER_ALIAS', $values);
        $expected = array_values(array_filter(ContractVariableBinder::PLACEHOLDERS,
            static fn (string $name): bool => $exclusive || $name !== 'CUSTOMER_ALIAS'));
        ContractBindingException::require(array_keys($values) === $expected, 'unsupported_input');
        foreach ($values as $name => $value) {
            ContractBindingException::require(is_string($value) && $value !== '' && mb_check_encoding($value, 'UTF-8'), 'missing_value', $name);
            ContractBindingException::require($coverage->covers($value), 'unsupported_characters', $name);
        }

        // The buyer fields re-verify under the profile's own faces, and the bound copies must be the declaration's.
        try {
            $declaration = ContractIdentityDeclarationV2::verify($declaration, $exclusive, $coverage);
        } catch (CheckoutException $exception) {
            throw new ContractBindingException($exception->reason === 'unsupported_characters' ? 'unsupported_characters' : 'contract_identity_invalid');
        }
        ContractBindingException::require($values['CUSTOMER_FULLNAME'] === $declaration['legal_name']
            && $values['CUSTOMER_ADDRESS'] === ContractIdentityDeclarationV2::customerAddress($declaration['postal_address'])
            && (! $exclusive || $values['CUSTOMER_ALIAS'] === $declaration['artist_name']), 'unsupported_input');

        foreach ([...ContractTemplateCensus::doubledSymbols($template), ...ContractTemplateCensus::doubledAtBoundaries($template, $values)] as $doubled) {
            throw new ContractBindingException('doubled_symbol', $doubled['placeholder']);
        }
        $body = ContractVariableBinder::substitute($template, $values);

        $date = DateTimeImmutable::createFromFormat('!F j, Y', $values['CONTRACT_DATE'], new DateTimeZone('UTC'));
        ContractBindingException::require($date !== false && $date->format('F j, Y') === $values['CONTRACT_DATE'], 'unsupported_input', 'CONTRACT_DATE');

        $blocks = self::blocks($body);
        ContractBindingException::require($blocks !== [], 'malformed_template');

        $business = $declaration['licensee_type'] === 'business';
        $acceptance = [
            'licensor' => $values['PRODUCER_ALIAS'],
            'licensee' => $declaration['legal_name'],
            // Sean with his lawyer, 2026-11-07: outside the template text, built from the frozen declaration only.
            'representative_line' => $business
                ? 'Accepted for '.$declaration['legal_name'].' by '.$declaration['representative_name'].', authorized representative.'
                : null,
            'date' => $values['CONTRACT_DATE'],
        ];

        $text = implode("\n", array_column($blocks, 'text'))."\n".implode("\n", array_filter($acceptance, is_string(...)));

        return new self($blocks, $acceptance, $date->getTimestamp(), $values['LICENSE_NAME'].': '.$values['PRODUCT_TITLE'],
            CanonicalJson::hash(['template_sha256' => hash('sha256', $template), 'values' => $values, 'declaration' => $declaration]), $text);
    }

    /** @return list<array{type: string, text: string}> */
    private static function blocks(string $body): array
    {
        $blocks = [];
        foreach (preg_split('/\n[ ]*\n+/', trim($body, "\n ")) ?: [] as $raw) {
            $lines = array_map(static fn (string $line): string => trim($line, ' '), explode("\n", trim($raw, "\n")));
            $lines = array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));
            if ($lines === []) {
                continue;
            }
            $text = implode("\n", $lines);
            $type = match (true) {
                $blocks === [] => 'title',
                count($lines) === 1 && mb_strlen($text) <= self::HEADING_CHARACTERS
                    && preg_match('/\p{L}/u', $text) === 1 && mb_strtoupper($text) === $text => 'heading',
                default => 'paragraph',
            };
            $blocks[] = ['type' => $type, 'text' => $text];
        }

        return $blocks;
    }
}
