<?php

namespace App\Domain\Contracts\Production;

use App\Domain\Commerce\ContractIdentity\ContractGlyphCoverage;
use App\Domain\Commerce\ContractIdentity\ContractIdentityDeclarationV2;
use App\Domain\Rights\SoundKitLicenseKind;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Binds one sound-kit purchase to the six placeholders of {@see SoundKitLicenseKind}. Pure: no database, file, clock,
 * locale or network, and not wired to any checkout (no kit checkout exists yet).
 *
 * Input, exactly these keys: `kit_name`, `purchased_at` (`Y-m-d\TH:i:s\Z`, UTC), `fee_minor` (integer minor units),
 * `currency` (explicit; only USD is supported, as for the beat contracts), `licensee_name` and `order_reference`. The
 * licensor's display name is a separate argument ({@see licensorFromConfig()} supplies it in production). Output: the
 * placeholder map in the kit's order. Refusals are {@see ContractBindingException}s with the placeholder named.
 */
final class SoundKitLicenseBinder
{
    private const FEE_MAX = 2147483647;

    private const INPUT_KEYS = ['kit_name', 'purchased_at', 'fee_minor', 'currency', 'licensee_name', 'order_reference'];

    /** The licensor display name from configuration; blank or unusable refuses (`value_invalid`), nothing is defaulted. */
    public static function licensorFromConfig(): string
    {
        $name = config('contracts.vasey_kit_license.licensor_name');
        ContractBindingException::require(is_string($name), 'value_invalid', 'LICENSOR_NAME');

        return $name;
    }

    /**
     * @param  array<string, mixed>  $purchase
     * @return array<string, string> placeholder => bound value, in {@see SoundKitLicenseKind::PLACEHOLDERS} order
     */
    public static function bind(array $purchase, string $licensorName, ContractGlyphCoverage $coverage): array
    {
        $keys = array_keys($purchase);
        sort($keys);
        $expected = self::INPUT_KEYS;
        sort($expected);
        ContractBindingException::require($keys === $expected, 'unsupported_input');

        $purchased = $purchase['purchased_at'];
        ContractBindingException::require(is_string($purchased) && preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $purchased) === 1, 'unsupported_input', 'PURCHASE_DATE');
        $instant = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $purchased, new DateTimeZone('UTC'));
        ContractBindingException::require($instant !== false && $instant->format('Y-m-d\TH:i:s\Z') === $purchased, 'unsupported_input', 'PURCHASE_DATE');

        $fee = $purchase['fee_minor'];
        ContractBindingException::require($purchase['currency'] === 'USD', 'currency_unsupported', 'LICENSE_FEE');
        ContractBindingException::require(is_int($fee) && $fee >= 0 && $fee <= self::FEE_MAX, 'amount_invalid', 'LICENSE_FEE');

        $reference = $purchase['order_reference'];
        ContractBindingException::require(is_string($reference) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{2,63}\z/D', $reference) === 1, 'value_invalid', 'ORDER_REFERENCE');

        return [
            'KIT_NAME' => self::text($purchase['kit_name'], 'KIT_NAME', 160, $coverage),
            'PURCHASE_DATE' => $instant->format('F j, Y'),
            'LICENSE_FEE' => ContractNumberWords::usd($fee),
            'LICENSEE_NAME' => self::text($purchase['licensee_name'], 'LICENSEE_NAME', 255, $coverage),
            'ORDER_REFERENCE' => $reference,
            'LICENSOR_NAME' => self::text($licensorName, 'LICENSOR_NAME', 160, $coverage),
        ];
    }

    /** Shape and byte cap first (`value_invalid`, no braces so a value can never look like a placeholder), then printability. */
    private static function text(mixed $value, string $placeholder, int $bytes, ContractGlyphCoverage $coverage): string
    {
        ContractBindingException::require(is_string($value) && $value !== '' && strlen($value) <= $bytes && mb_check_encoding($value, 'UTF-8')
            && trim($value) === $value && preg_match('/[\x00-\x1f\x7f{}]/', $value) !== 1, 'value_invalid', $placeholder);
        ContractBindingException::require(ContractIdentityDeclarationV2::isPrintable($value, $coverage), 'unsupported_characters', $placeholder);

        return $value;
    }
}
