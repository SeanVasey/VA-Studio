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

        // Price: the frozen pre-tax line amount in USD minor units.
        [$amount, $currency] = $family['source_schema'] === 2
            ? [$source['pre_tax']['line_amount_minor'], $source['pre_tax']['currency']]
            : [$source['line_amount_minor'], $source['amounts']['currency']];
        ContractBindingException::require($currency === 'USD', 'currency_unsupported');
        ContractBindingException::require(is_int($amount) && $amount >= 1 && $amount <= self::PRICE_MAX
            && ($family['source_schema'] === 1 || $amount === $source['tax']['line_subtotal_minor']), 'amount_invalid');

        $values = [
            'CONTRACT_DATE' => $instant->format('F j, Y'),
            'CUSTOMER_FULLNAME' => $declaration['legal_name'],
            'CUSTOMER_ADDRESS' => ContractIdentityDeclarationV2::customerAddress($declaration['postal_address']),
            'CUSTOMER_ALIAS' => $exclusive ? $declaration['artist_name'] : null,
            'PRODUCT_TITLE' => self::text($source['product']['title'], 'PRODUCT_TITLE'),
            'PRODUCT_PRICE' => ContractNumberWords::usd($amount),
            'PRODUCT_PRICE_WORD' => ContractNumberWords::dollars($amount),
            'LICENSE_NAME' => self::text($license['name'], 'LICENSE_NAME'),
            'PRODUCER_ALIAS' => $constants->producerAlias,
            'STATE_PROVINCE_COUNTRY' => $constants->stateProvinceCountry,
            'FILE_TYPE' => self::fileTypes($terms['required_asset_roles']),
            'PUBLISHING_RIGHTS' => self::publishing($terms['publishing_income']),
        ];
        foreach (self::USAGE as $placeholder => $slot) {
            $values[$placeholder] = self::usage($terms['usage'][$slot], $placeholder, false);
        }
        $values['MONETIZED_MUSIC_VIDEOS_WORD'] = self::usage($terms['usage']['music_videos'], 'MONETIZED_MUSIC_VIDEOS_WORD', true);

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

    /** A limited use prints its limit, an unlimited one "unlimited"; a prohibited use has no value to print. */
    private static function usage(array $use, string $placeholder, bool $words): string
    {
        return match ($use['mode']) {
            'limited' => $words ? ContractNumberWords::spell($use['limit']) : number_format($use['limit'], 0, '.', ','),
            'unlimited' => 'unlimited',
            'prohibited' => throw new ContractBindingException('usage_prohibited', $placeholder),
        };
    }

    /** The licensor's share of publishing income, as a percentage with no trailing zeros ("50%", "12.5%"). */
    private static function publishing(array $publishing): string
    {
        ContractBindingException::require($publishing['mode'] === 'share', 'publishing_share_unbound', 'PUBLISHING_RIGHTS');
        $bps = $publishing['licensor_bps'];
        $fraction = rtrim(str_pad((string) ($bps % 100), 2, '0', STR_PAD_LEFT), '0');

        return intdiv($bps, 100).($fraction === '' ? '' : '.'.$fraction).'%';
    }

    /** Deliverables in the reviewed label vocabulary and order: "MP3, WAV master and Stems ZIP". */
    private static function fileTypes(array $roles): string
    {
        $labels = array_values(array_intersect_key(TypedLicenseTerms::DELIVERABLES, array_flip($roles)));
        ContractBindingException::require(count($labels) === count($roles) && $labels !== [], 'license_terms_invalid', 'FILE_TYPE');
        $last = array_pop($labels);

        return $labels === [] ? $last : implode(', ', $labels).' and '.$last;
    }

    private static function text(mixed $value, string $placeholder): string
    {
        ContractBindingException::require(is_string($value) && $value !== '' && mb_check_encoding($value, 'UTF-8')
            && trim($value) === $value && preg_match('/[\x00-\x1f\x7f]/', $value) !== 1, 'value_invalid', $placeholder);

        return $value;
    }
}
