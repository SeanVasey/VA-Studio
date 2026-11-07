<?php

namespace App\Domain\Contracts\Production;

use App\Domain\Commerce\ContractIdentity\ContractGlyphCoverage;
use App\Domain\Commerce\ContractIdentity\ContractIdentityDeclarationV2;
use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Rights\TieredLicenseTerms;
use App\Domain\Rights\TypedLicenseTerms;
use App\Support\CanonicalJson;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Increment 3 of the production contract profile: the closed map from one frozen paid license line to the
 * `{NAME}` placeholders of Sean's license templates, and the only substitution allowed into that template text.
 *
 * Pure and deterministic: no database, filesystem, clock, locale or network. Each placeholder has exactly one
 * source (docs/production-contract-profile.md, "Variable map"). A value that is absent, unrepresentable or
 * inconsistent with the rest of the frozen input refuses with a typed {@see ContractBindingException}; nothing is
 * defaulted. Not wired into issuance, renderers or any profile yet (increment 4).
 */
final class ContractVariableBinder
{
    /** The closed set, in the variable map's order. `CUSTOMER_ALIAS` is bound only for Exclusive lines. */
    public const PLACEHOLDERS = [
        'CONTRACT_DATE', 'CUSTOMER_FULLNAME', 'CUSTOMER_ADDRESS', 'CUSTOMER_ALIAS', 'PRODUCT_TITLE', 'PRODUCT_PRICE',
        'PRODUCT_PRICE_WORD', 'LICENSE_NAME', 'PRODUCER_ALIAS', 'STATE_PROVINCE_COUNTRY', 'FILE_TYPE', 'PUBLISHING_RIGHTS',
        'DISTRIBUTE_COPIES', 'NUMBER_OF_RADIO_STATIONS', 'MONETIZED_MUSIC_VIDEOS', 'MONETIZED_MUSIC_VIDEOS_WORD', 'AUDIO_STREAMS',
        'NON_MONETIZED_VIDEO_STREAMS_ALLOWED', 'MONETIZED_VIDEO_STREAMS_ALLOWED', 'FREE_DOWNLOADS', 'PERFORMANCES_FOR_PROFIT',
        'PERFORMANCES_NOT_FOR_PROFIT',
    ];

    /** Schema 5 usage slot for each usage placeholder. `audio_releases` has no template placeholder. */
    public const USAGE = [
        'DISTRIBUTE_COPIES' => 'copies_downloads', 'NUMBER_OF_RADIO_STATIONS' => 'radio_stations',
        'MONETIZED_MUSIC_VIDEOS' => 'music_videos', 'AUDIO_STREAMS' => 'audio_streams',
        'NON_MONETIZED_VIDEO_STREAMS_ALLOWED' => 'non_monetized_video_streams', 'MONETIZED_VIDEO_STREAMS_ALLOWED' => 'monetized_video_streams',
        'FREE_DOWNLOADS' => 'free_downloads', 'PERFORMANCES_FOR_PROFIT' => 'for_profit_performances',
        'PERFORMANCES_NOT_FOR_PROFIT' => 'not_for_profit_performances',
    ];

    /** The two frozen grant input families, each tied to its own paid source shape. */
    private const FAMILIES = [
        'paid-tax-render-input-v2' => ['purpose' => 'paid-tax-license-grant', 'source_schema' => 2, 'producer' => 'production_tax_checkout_v2'],
        'paid-grant-render-input-v1' => ['purpose' => 'paid-license-grant', 'source_schema' => 1, 'producer' => 'production_checkout_v1'],
    ];

    /** The catalog's price bound (`PricingSnapshotV3`). */
    private const PRICE_MAX = 2147483647;

    /** Byte cap for the track title and license name, as the legal name's. */
    private const TEXT_BYTES = 255;

    /** Each frozen value and the self-hash the producer stored beside it. */
    private const HASHED = ['buyer' => 'buyer_binding_hash', 'product' => 'product_hash', 'assent' => 'assent_hash', 'license' => 'license_hash'];

    private const COMMITMENTS = ['source_hash', 'asset_graph_hash', 'license_source_hash', 'license_submission_hash', 'license_review_hash', 'assent_hash'];

    /**
     * Bind one paid line.
     *
     * @param  array  $input  The frozen grant render input (`PaidTaxRenderInput` or `PaidGrantRenderInput`) for one line.
     * @param  array  $terms  The license version's schema 5 structured terms; must equal the line's frozen license terms.
     * @return array<string, string> Placeholder name to value, in {@see PLACEHOLDERS} order.
     */
    public static function bind(array $input, array $terms, ContractLicenseConstants $constants, ContractGlyphCoverage $coverage): array
    {
        try {
            return self::values($input, $terms, $constants, $coverage);
        } catch (ContractBindingException $exception) {
            throw $exception;
        } catch (Throwable) {
            // A missing key or wrong type anywhere in the frozen input is a shape refusal, never a partial map.
            throw new ContractBindingException('unsupported_input');
        }
    }

    /**
     * Substitute `{NAME}` placeholders in template text from a bound map, in one pass. Substituted values are never
     * scanned again, so buyer text that looks like a placeholder stays literal. Refuses an unknown placeholder, one
     * with no value in the map, a map entry outside the closed set or without a value, and any brace in the template
     * that is not part of a well-formed placeholder (including leftover `{{…}}` license source variables).
     *
     * @param  array<string, string>  $values
     */
    public static function substitute(string $template, array $values): string
    {
        foreach ($values as $name => $value) {
            ContractBindingException::require(in_array($name, self::PLACEHOLDERS, true), 'unknown_placeholder', (string) $name);
            ContractBindingException::require(is_string($value) && $value !== '' && mb_check_encoding($value, 'UTF-8'), 'missing_value', $name);
            ContractBindingException::require(preg_match('/[\x00-\x1f\x7f]/', $value) !== 1, 'value_invalid', $name);
        }
        ContractBindingException::require(mb_check_encoding($template, 'UTF-8'), 'malformed_template');
        $parts = preg_split('/\{([^{}]*)\}/u', $template, -1, PREG_SPLIT_DELIM_CAPTURE);
        ContractBindingException::require(is_array($parts), 'malformed_template');
        $text = '';
        foreach ($parts as $index => $part) {
            if ($index % 2 === 0) {
                ContractBindingException::require(! str_contains($part, '{') && ! str_contains($part, '}'), 'malformed_template');
                $text .= $part;

                continue;
            }
            ContractBindingException::require(in_array($part, self::PLACEHOLDERS, true), 'unknown_placeholder', $part);
            ContractBindingException::require(array_key_exists($part, $values), 'missing_value', $part);
            $text .= $values[$part];
        }

        return $text;
    }

    private static function values(array $input, array $terms, ContractLicenseConstants $constants, ContractGlyphCoverage $coverage): array
    {
        $family = self::FAMILIES[$input['schema_version'] ?? null] ?? null;
        $source = $input['source'] ?? null;
        ContractBindingException::require(is_array($family) && ($input['purpose'] ?? null) === $family['purpose'] && is_array($source)
            && ($source['schema_version'] ?? null) === $family['source_schema'] && ($source['producer'] ?? null) === $family['producer'], 'unsupported_input');
        self::commitments($input, $source);

        // CONTRACT_DATE: the UTC calendar date of the frozen assent instant.
        $effective = $input['effective_at'] ?? null;
        ContractBindingException::require(is_string($effective) && preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $effective) === 1
            && $effective === ($source['assent']['accepted_at'] ?? null), 'unsupported_input');
        $instant = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $effective, new DateTimeZone('UTC'));
        ContractBindingException::require($instant !== false && $instant->format('Y-m-d\TH:i:s\Z') === $effective, 'unsupported_input');

        // License: only schema 5 sources, identical to the terms frozen on the line.
        $license = $source['license'];
        ContractBindingException::require(is_array($license) && is_array($input['license'] ?? null)
            && ($input['license']['name'] ?? null) === $license['name'] && ($input['license']['type'] ?? null) === $license['type'], 'unsupported_input');
        ContractBindingException::require(in_array($license['type'], ['non-exclusive', 'exclusive'], true), 'license_type_unsupported');
        $exclusive = $license['type'] === 'exclusive';
        ContractBindingException::require(($terms['schema_version'] ?? null) === 5
            && ($license['structured_terms']['schema_version'] ?? null) === 5, 'license_schema_unsupported');
        ContractBindingException::require(CanonicalJson::encode($terms) === CanonicalJson::encode($license['structured_terms']), 'license_terms_mismatch');
        try {
            $terms = app(TieredLicenseTerms::class)->validate($terms);
        } catch (ValidationException) {
            throw new ContractBindingException('license_terms_invalid');
        }

        // Buyer: only a re-verified v2 contract identity declaration. Legacy two-key declarations have no address.
        $declaration = $input['buyer']['declarations'];
        ContractBindingException::require($declaration === $source['buyer_declarations'], 'unsupported_input');
        ContractBindingException::require(ContractIdentityDeclarationV2::isV2($declaration), 'contract_identity_missing');
        try {
            $declaration = ContractIdentityDeclarationV2::verify($declaration, $exclusive, $coverage);
        } catch (CheckoutException) {
            throw new ContractBindingException('contract_identity_invalid');
        }

        // Price: the pre-tax license fee in USD minor units (Sean, 2026-11-07), never the taxed or gross figure.
        [$amount, $currency] = $family['source_schema'] === 2 ? self::taxedLicenseFee($source) : self::exemptLicenseFee($source);
        ContractBindingException::require($currency === 'USD', 'currency_unsupported');
        ContractBindingException::require(is_int($amount) && $amount >= 1 && $amount <= self::PRICE_MAX, 'amount_invalid');

        $values = [
            'CONTRACT_DATE' => $instant->format('F j, Y'),
            'CUSTOMER_FULLNAME' => $declaration['legal_name'],
            'CUSTOMER_ADDRESS' => ContractIdentityDeclarationV2::customerAddress($declaration['postal_address']),
            'CUSTOMER_ALIAS' => $exclusive ? $declaration['artist_name'] : null,
            'PRODUCT_TITLE' => self::text($source['product']['title'], 'PRODUCT_TITLE', self::TEXT_BYTES, $coverage),
            'PRODUCT_PRICE' => ContractNumberWords::usd($amount),
            'PRODUCT_PRICE_WORD' => ContractNumberWords::dollars($amount),
            'LICENSE_NAME' => self::text($license['name'], 'LICENSE_NAME', self::TEXT_BYTES, $coverage),
            'PRODUCER_ALIAS' => self::text($constants->producerAlias, 'PRODUCER_ALIAS', self::TEXT_BYTES, $coverage),
            'STATE_PROVINCE_COUNTRY' => self::text($constants->stateProvinceCountry, 'STATE_PROVINCE_COUNTRY', self::TEXT_BYTES, $coverage),
            'FILE_TYPE' => self::fileTypes($terms['required_asset_roles']),
            'PUBLISHING_RIGHTS' => self::publishing($terms['publishing_income']),
        ];
        foreach (self::USAGE as $placeholder => $slot) {
            $values[$placeholder] = self::usage($terms['usage'][$slot], false);
        }
        $values['MONETIZED_MUSIC_VIDEOS_WORD'] = self::usage($terms['usage']['music_videos'], true);

        $bound = [];
        foreach (self::PLACEHOLDERS as $placeholder) {
            if ($placeholder === 'CUSTOMER_ALIAS' && ! $exclusive) {
                continue;
            }
            ContractBindingException::require(is_string($values[$placeholder]) && $values[$placeholder] !== '', 'missing_value', $placeholder);
            $bound[$placeholder] = $values[$placeholder];
        }

        return $bound;
    }

    /**
     * Every hash commitment the producer froze must still match the values beside it, so an edited title, license or
     * assent with a stale hash never binds. Recomputed exactly as the producers compute them (`CanonicalJson::hash`).
     */
    private static function commitments(array $input, array $source): void
    {
        $unhashed = $source;
        unset($unhashed['source_hash']);
        $ok = is_string($source['source_hash'] ?? null) && hash_equals(CanonicalJson::hash($unhashed), $source['source_hash']);
        foreach (self::HASHED as $value => $hash) {
            $ok = $ok && array_key_exists($value, $source) && is_string($source[$hash] ?? null) && hash_equals(CanonicalJson::hash($source[$value]), $source[$hash]);
        }
        $commitments = $input['source_commitments'] ?? null;
        $license = $source['license'] ?? null;
        ContractBindingException::require($ok && is_array($commitments) && is_array($license) && count($commitments) === count(self::COMMITMENTS)
            && array_diff(self::COMMITMENTS, array_keys($commitments)) === []
            && $commitments['source_hash'] === $source['source_hash'] && $commitments['assent_hash'] === $source['assent_hash']
            && array_key_exists('source_hash', $license) && $commitments['license_source_hash'] === $license['source_hash']
            && array_key_exists('submission_hash', $license) && $commitments['license_submission_hash'] === $license['submission_hash']
            && array_key_exists('review_evidence_hash', $license) && $commitments['license_review_hash'] === $license['review_evidence_hash']
            && is_string($commitments['asset_graph_hash']) && preg_match('/\A[0-9a-f]{64}\z/', $commitments['asset_graph_hash']) === 1
            && ($input['assent'] ?? null) === $source['assent'], 'unsupported_input');
    }

    /**
     * V2: the pre-tax license fee. Tax-exclusive lines frozen the fee as the subtotal; tax-inclusive lines frozen the
     * gross, so the fee is the subtotal less the line's own tax, exactly, in integer minor units.
     *
     * @return array{0: mixed, 1: mixed}
     */
    private static function taxedLicenseFee(array $source): array
    {
        $tax = $source['tax'];
        $subtotal = $source['pre_tax']['line_amount_minor'];
        $taxed = $tax['line_tax_minor'] ?? null;
        ContractBindingException::require(is_int($subtotal) && $subtotal === $tax['line_subtotal_minor'] && is_int($taxed) && $taxed >= 0
            && $tax['currency'] === $source['pre_tax']['currency'], 'amount_invalid');
        $fee = match ($tax['tax_behavior'] ?? null) {
            'exclusive' => $tax['line_total_minor'] === $subtotal + $taxed ? $subtotal : null,
            'inclusive' => $tax['line_total_minor'] === $subtotal ? $subtotal - $taxed : null,
            default => null,
        };
        // A fee of zero or less (inclusive tax equal to the gross) is refused by the caller's 1..PRICE_MAX bound.
        ContractBindingException::require(is_int($fee), 'amount_invalid');

        return [$fee, $source['pre_tax']['currency']];
    }

    /** V1 orders are tax-exempt: the line carries no tax, so its amount is the license fee. */
    private static function exemptLicenseFee(array $source): array
    {
        ContractBindingException::require($source['line_tax_minor'] === 0 && ($source['amounts']['tax_minor'] ?? null) === 0, 'amount_invalid');

        return [$source['line_amount_minor'], $source['amounts']['currency']];
    }

    /** A limited use prints its limit, an unlimited one "unlimited", a prohibited one zero (Sean, 2026-11-07). */
    private static function usage(array $use, bool $words): string
    {
        return match ($use['mode']) {
            'limited' => $words ? ContractNumberWords::spell($use['limit']) : number_format($use['limit'], 0, '.', ','),
            'unlimited' => 'unlimited',
            'prohibited' => $words ? ContractNumberWords::spell(0) : '0',
        };
    }

    /**
     * The licensor's share of publishing income in the statement schedule's own form (`EconomicLicenseTerms`,
     * "12.50%"), so one contract never prints the share two ways. Mode `none` has no share to print.
     */
    private static function publishing(array $publishing): string
    {
        ContractBindingException::require($publishing['mode'] === 'share', 'publishing_share_unbound', 'PUBLISHING_RIGHTS');
        $bps = $publishing['licensor_bps'];

        return intdiv($bps, 100).'.'.str_pad((string) ($bps % 100), 2, '0', STR_PAD_LEFT).'%';
    }

    /** Deliverables in the reviewed label vocabulary and order: "MP3, WAV master and Stems ZIP". */
    private static function fileTypes(array $roles): string
    {
        $labels = array_values(array_intersect_key(TypedLicenseTerms::DELIVERABLES, array_flip($roles)));
        ContractBindingException::require(count($labels) === count($roles) && $labels !== [], 'license_terms_invalid', 'FILE_TYPE');
        $last = array_pop($labels);

        return $labels === [] ? $last : implode(', ', $labels).' and '.$last;
    }

    /** Shape and byte cap first (`value_invalid`), then the D-30 printability rule (`unsupported_characters`). */
    private static function text(mixed $value, string $placeholder, int $bytes, ContractGlyphCoverage $coverage): string
    {
        ContractBindingException::require(is_string($value) && $value !== '' && strlen($value) <= $bytes && mb_check_encoding($value, 'UTF-8')
            && trim($value) === $value && preg_match('/[\x00-\x1f\x7f]/', $value) !== 1, 'value_invalid', $placeholder);
        ContractBindingException::require(ContractIdentityDeclarationV2::isPrintable($value, $coverage), 'unsupported_characters', $placeholder);

        return $value;
    }
}
