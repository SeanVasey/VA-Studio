<?php

namespace App\Domain\Commerce\ContractIdentity;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Rights\LicenseTerritories;

/**
 * D-30 buyer contract identity: who the licensee is (an individual, or a business acting through a named
 * representative), the legal name the buyer confirms, a structured postal address and, for Exclusive, an
 * artist name. Pure: it validates checkout input into the frozen `buyer_declarations` shape, re-verifies a stored
 * declaration and formats `{CUSTOMER_ADDRESS}`. The production tax checkout freezes it behind the default-off
 * `contract_identity_v2` gate (local/testing only).
 *
 * Shape errors refuse with `invalid`; a well-formed value the pinned contract fonts cannot print refuses with
 * `unsupported_characters`, so the buyer can be asked to enter supported characters. Values are never rewritten
 * beyond trimming: decomposed text (combining marks) is refused, not normalized, so the stored value is what was sent.
 */
final class ContractIdentityDeclarationV2
{
    public const SCHEMA = 'buyer-contract-identity-v2';

    public const MEANING = 'buyer_declared_contract_identity';

    /** An individual licenses in their own name; a business licenses in its name through the person accepting for it. */
    public const LICENSEE_TYPES = ['individual', 'business'];

    private const LEGAL_NAME_BYTES = 255;

    private const ARTIST_NAME_BYTES = 120;

    private const LINE_BYTES = 120;

    private const AREA_BYTES = 80;

    private const POSTAL_CODE_BYTES = 20;

    private const ADDRESS_BYTES = 512;

    /**
     * Refused even where the pinned faces draw them: format, private-use, unassigned and C1 codepoints (bidi overrides
     * and isolates, zero-width characters, soft hyphen, BOM), combining marks, spaces other than U+0020, right-to-left
     * scripts, U+2800 BRAILLE PATTERN BLANK (a visible-width blank), U+FFFC and U+FFFD, and the 26 covered codepoints
     * that NFC replaces with another (Kelvin and Ångström signs, Greek oxia forms and similar), which print like their
     * canonical spelling but store different bytes. The list is pinned rather than computed so that re-verifying frozen
     * evidence does not depend on the runtime's normalization tables.
     */
    private const REFUSED = '/[\p{C}\p{M}\p{Z}\p{Hebrew}\p{Arabic}\p{Syriac}\p{Thaana}\p{Nko}\p{Samaritan}\p{Mandaic}'
        .'\x{2800}\x{FFFC}\x{FFFD}'
        .'\x{0374}\x{037E}\x{0387}\x{1F71}\x{1F73}\x{1F75}\x{1F77}\x{1F79}\x{1F7B}\x{1F7D}\x{1FBB}\x{1FBE}\x{1FC9}'
        .'\x{1FCB}\x{1FD3}\x{1FDB}\x{1FE3}\x{1FEB}\x{1FEE}\x{1FEF}\x{1FF9}\x{1FFB}\x{1FFD}\x{2126}\x{212A}\x{212B}]/u';

    /**
     * Checkout input (camelCase) to the canonical declaration.
     *
     * $statements holds the confirmation text offered for each licensee type, as
     * `['individual' => ['statement_version' => ..., 'statement_hash' => ...], 'business' => [...]]`. The buyer's type
     * selects the one they saw. A type with no approved statement is left out, so it cannot be chosen. Each
     * statement's version starts with its type (`individual-…`, `business-…`), and the two must differ, so a
     * miskeyed map cannot freeze one type's statement for the other.
     *
     * @param  array<string, array{statement_version: string, statement_hash: string}>  $statements
     */
    public static function fromInput(array $input, bool $exclusive, array $statements, ContractGlyphCoverage $coverage): array
    {
        self::keys($input, ['licenseeType', 'legalName', 'legalNameConfirmed', 'postalAddress'], ['representativeName', 'artistName']);
        CheckoutException::require($input['legalNameConfirmed'] === true, 'invalid', 422);
        CheckoutException::require(in_array($input['licenseeType'], self::LICENSEE_TYPES, true), 'invalid', 422);
        CheckoutException::require(array_diff(array_keys($statements), self::LICENSEE_TYPES) === [], 'invalid', 422);
        // Every offered statement is checked, not only the selected one: a malformed or miskeyed entry is a configuration error.
        foreach ($statements as $type => $offered) {
            CheckoutException::require(is_array($offered), 'invalid', 422);
            self::statement($offered);
            CheckoutException::require(self::belongsTo($offered['statement_version'], $type), 'invalid', 422);
        }
        $hashes = array_column($statements, 'statement_hash');
        CheckoutException::require(count($hashes) === count(array_unique($hashes)), 'invalid', 422);
        $statement = $statements[$input['licenseeType']] ?? null;
        CheckoutException::require(is_array($statement), 'invalid', 422);
        self::statement($statement);
        $address = $input['postalAddress'];
        CheckoutException::require(is_array($address), 'invalid', 422);
        self::keys($address, ['country', 'lines'], ['locality', 'region', 'postalCode']);
        CheckoutException::require(is_array($address['lines']) && array_is_list($address['lines']), 'invalid', 422);

        $postal = ['country' => $address['country'], 'lines' => array_map(static fn (mixed $line): mixed => self::trimmed($line), $address['lines'])];
        foreach (['locality', 'region', 'postalCode'] as $key) {
            $value = self::trimmed($address[$key] ?? null);
            if ($value !== null && $value !== '') {
                $postal[$key] = $value;
            }
        }
        $artist = self::trimmed($input['artistName'] ?? null);
        $representative = self::trimmed($input['representativeName'] ?? null);

        return self::verify([
            'schema' => self::SCHEMA,
            'licensee_type' => $input['licenseeType'],
            'legal_name' => self::trimmed($input['legalName']),
            'representative_name' => $representative === '' ? null : $representative,
            'legal_name_confirmation' => ['confirmed' => true, 'statement_version' => $statement['statement_version'], 'statement_hash' => $statement['statement_hash']],
            'postal_address' => $postal,
            'artist_name' => $artist === '' ? null : $artist,
            'identity_meaning' => self::MEANING,
        ], $exclusive, $coverage);
    }

    /** Re-verify a frozen declaration exactly; any drift from the canonical shape refuses. */
    public static function verify(mixed $declaration, bool $exclusive, ContractGlyphCoverage $coverage): array
    {
        CheckoutException::require(self::isV2($declaration), 'invalid', 422);
        self::keys($declaration, ['schema', 'licensee_type', 'legal_name', 'representative_name', 'legal_name_confirmation',
            'postal_address', 'artist_name', 'identity_meaning']);
        CheckoutException::require($declaration['identity_meaning'] === self::MEANING, 'invalid', 422);
        CheckoutException::require(in_array($declaration['licensee_type'], self::LICENSEE_TYPES, true), 'invalid', 422);

        $confirmation = $declaration['legal_name_confirmation'];
        CheckoutException::require(is_array($confirmation), 'invalid', 422);
        self::keys($confirmation, ['confirmed', 'statement_version', 'statement_hash']);
        CheckoutException::require($confirmation['confirmed'] === true, 'invalid', 422);
        self::statement(['statement_version' => $confirmation['statement_version'], 'statement_hash' => $confirmation['statement_hash']]);
        // The frozen statement must be the one for this licensee type: its version names the type.
        CheckoutException::require(is_string($declaration['licensee_type'])
            && self::belongsTo($confirmation['statement_version'], $declaration['licensee_type']), 'invalid', 422);

        $printed = [];
        $printed[] = self::text($declaration['legal_name'], self::LEGAL_NAME_BYTES);

        // A business needs the name of the person accepting for it; an individual has no representative.
        $business = $declaration['licensee_type'] === 'business';
        CheckoutException::require(($declaration['representative_name'] !== null) === $business, 'invalid', 422);
        if ($business) {
            $printed[] = self::text($declaration['representative_name'], self::LEGAL_NAME_BYTES);
        }

        CheckoutException::require($declaration['artist_name'] === null || $exclusive, 'invalid', 422);
        CheckoutException::require($declaration['artist_name'] !== null || ! $exclusive, 'invalid', 422);
        if ($declaration['artist_name'] !== null) {
            $printed[] = self::text($declaration['artist_name'], self::ARTIST_NAME_BYTES);
        }

        $address = $declaration['postal_address'];
        CheckoutException::require(is_array($address), 'invalid', 422);
        self::keys($address, ['country', 'lines'], ['locality', 'region', 'postalCode']);
        CheckoutException::require(is_string($address['country']) && isset(LicenseTerritories::COUNTRIES[$address['country']]), 'invalid', 422);
        $lines = $address['lines'];
        CheckoutException::require(is_array($lines) && array_is_list($lines) && count($lines) >= 1 && count($lines) <= 3, 'invalid', 422);
        foreach ($lines as $line) {
            $printed[] = self::text($line, self::LINE_BYTES);
        }
        foreach (['locality', 'region'] as $key) {
            if (array_key_exists($key, $address)) {
                $printed[] = self::text($address[$key], self::AREA_BYTES);
            }
        }
        if (array_key_exists('postalCode', $address)) {
            $code = $address['postalCode'];
            CheckoutException::require(is_string($code) && $code !== '' && strlen($code) <= self::POSTAL_CODE_BYTES
                && preg_match('/\A[A-Za-z0-9](?:[A-Za-z0-9 -]*[A-Za-z0-9])?\z/', $code) === 1, 'invalid', 422);
        }
        CheckoutException::require(strlen(self::customerAddress($address)) <= self::ADDRESS_BYTES, 'invalid', 422);

        // Shape first, then printability, so a malformed value never reports as a character problem.
        foreach ($printed as $value) {
            CheckoutException::require(self::printable($value, $coverage), 'unsupported_characters', 422);
        }

        return $declaration;
    }

    /** Legacy declarations (`legal_name`, `identity_meaning`) are not v2; the contract binder refuses them. */
    public static function isV2(mixed $declaration): bool
    {
        return is_array($declaration) && ($declaration['schema'] ?? null) === self::SCHEMA;
    }

    /**
     * `{CUSTOMER_ADDRESS}`: lines, then locality, region and postal code, then the country's pinned English name,
     * joined with ", ". Deterministic: no locale data is consulted.
     */
    public static function customerAddress(array $postalAddress): string
    {
        $parts = $postalAddress['lines'];
        foreach (['locality', 'region', 'postalCode'] as $key) {
            if (array_key_exists($key, $postalAddress)) {
                $parts[] = $postalAddress[$key];
            }
        }
        $parts[] = LicenseTerritories::COUNTRIES[$postalAddress['country']];

        return implode(', ', $parts);
    }

    /** A trimmed, non-empty, bounded value of valid UTF-8 with no C0/DEL, as the legacy legal-name rule. */
    private static function text(mixed $value, int $bytes): string
    {
        CheckoutException::require(is_string($value) && $value !== '' && strlen($value) <= $bytes
            && mb_check_encoding($value, 'UTF-8') && trim($value) === $value
            && preg_match('/[\x00-\x1f\x7f]/', $value) !== 1, 'invalid', 422);

        return $value;
    }

    /**
     * Printable: no refused codepoint, at least one cased or other letter or decimal digit (a modifier letter such as
     * U+02BC or a number such as ½ alone does not count), and every codepoint drawn by every pinned face.
     */
    private static function printable(string $value, ContractGlyphCoverage $coverage): bool
    {
        return preg_match(self::REFUSED, str_replace(' ', '', $value)) === 0
            && preg_match('/[\p{Lu}\p{Ll}\p{Lt}\p{Lo}\p{Nd}]/u', $value) === 1
            && $coverage->covers($value);
    }

    /** A statement version names its licensee type as a prefix and has something after it (`individual-…`, `business-…`). */
    private static function belongsTo(string $version, string $type): bool
    {
        return str_starts_with($version, $type.'-') && strlen($version) > strlen($type) + 1;
    }

    private static function statement(array $statement): void
    {
        self::keys($statement, ['statement_version', 'statement_hash']);
        CheckoutException::require(is_string($statement['statement_version'])
            && preg_match('/\A[a-z0-9][a-z0-9.-]{0,63}\z/', $statement['statement_version']) === 1
            && is_string($statement['statement_hash']) && preg_match('/\A[0-9a-f]{64}\z/', $statement['statement_hash']) === 1, 'invalid', 422);
    }

    private static function trimmed(mixed $value): mixed
    {
        return is_string($value) ? trim($value, " \t\n\r\0\x0B") : $value;
    }

    /** Exact keys: every required key present, optional keys allowed, nothing else. */
    private static function keys(mixed $value, array $required, array $optional = []): void
    {
        CheckoutException::require(is_array($value), 'invalid', 422);
        foreach ($required as $key) {
            CheckoutException::require(array_key_exists($key, $value), 'invalid', 422);
        }
        CheckoutException::require(array_diff(array_keys($value), [...$required, ...$optional]) === [], 'invalid', 422);
    }
}
