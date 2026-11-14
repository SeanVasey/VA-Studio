<?php

namespace App\Domain\Grants\PaidTax;

use App\Domain\Delivery\DeliveryAssetFiles;
use Illuminate\Config\Repository;

/** Live technical delivery inputs, separate from the original purchased six-field policy. */
final class PaidTaxDeliveryPolicy
{
    private const KEYS = ['paid-tax-grants.downloads_enabled', 'paid-tax-grants.snapshot_seconds',
        'paid-tax-grants.transfer_min_bytes_per_second', 'paid-tax-grants.transfer_base_seconds',
        'paid-tax-grants.transfer_max_seconds', 'app.key'];

    public static function capture(Repository $configuration, string $environment): array
    {
        $inputs = [];
        foreach (self::KEYS as $key) {
            $inputs[$key] = PaidTaxConfiguration::read($configuration, $key);
        }
        self::validate($inputs, $environment);

        return $inputs;
    }

    public static function provePure(array $inputs, Repository $configuration, string $environment): void
    {
        self::validate($inputs, $environment);
        foreach (self::KEYS as $key) {
            PaidTaxException::require(PaidTaxConfiguration::read($configuration, $key) === $inputs[$key], 403);
        }
    }

    private static function validate(array $inputs, string $environment): void
    {
        PaidTaxException::require(array_keys($inputs) === self::KEYS);
        PaidTaxException::require(in_array($environment, ['local', 'testing'], true)
            && $inputs['paid-tax-grants.downloads_enabled'] === true, 404);
        $snapshot = $inputs['paid-tax-grants.snapshot_seconds'];
        $rate = $inputs['paid-tax-grants.transfer_min_bytes_per_second'];
        $base = $inputs['paid-tax-grants.transfer_base_seconds'];
        $maximum = $inputs['paid-tax-grants.transfer_max_seconds'];
        PaidTaxException::require(is_int($snapshot) && $snapshot >= 30 && $snapshot <= 1800
            && is_int($rate) && $rate >= 16384 && $rate <= 1073741824
            && is_int($base) && $base >= 0 && $base <= 600
            && is_int($maximum) && $maximum >= 60 && $maximum <= 14400
            && is_string($inputs['app.key']) && $inputs['app.key'] !== '', 503);
    }

    public static function snapshotSeconds(): int
    {
        return self::capture(app('config'), app()->environment())['paid-tax-grants.snapshot_seconds'];
    }

    public static function transferSeconds(array $inputs, int $bytes): int
    {
        PaidTaxException::require($bytes >= 1 && $bytes <= DeliveryAssetFiles::MAX_BYTES);
        $rate = $inputs['paid-tax-grants.transfer_min_bytes_per_second'];

        return min($inputs['paid-tax-grants.transfer_max_seconds'],
            $inputs['paid-tax-grants.transfer_base_seconds'] + intdiv($bytes + $rate - 1, $rate));
    }
}
