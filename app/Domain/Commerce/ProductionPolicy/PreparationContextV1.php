<?php

namespace App\Domain\Commerce\ProductionPolicy;

use App\Support\CanonicalJson;

/** Explicit adapter identity binding; this is not an execution or payment capability. */
final class PreparationContextV1
{
    public const PURPOSE = 'production_track_pricing_order_preparation';

    public static function forMachine(array $machine): array
    {
        $c = MachinePolicyV1::validate($machine)['choices'];

        return ['schema_version' => 1, 'purpose' => self::PURPOSE, 'provider' => $c['provider_account']['provider'],
            'account_id' => $c['provider_account']['account_id'], 'mode' => $c['provider_account']['mode'],
            'api_version' => $c['provider_account']['api_version'], 'capture_method' => $c['provider_account']['capture_method'],
            'currency' => $c['currency']['code'], 'minor_unit_exponent' => $c['currency']['minor_unit_exponent'],
            'storage_adapter' => $c['storage']['adapter'], 'storage_adapter_version' => $c['storage']['adapter_version'],
            'storage_boundary_id' => $c['storage']['boundary_id'], 'renderer_profile' => $c['original_documents']['renderer_profile'],
            'delivery_transfer' => $c['delivery']['transfer'], 'assent_version' => $c['assent']['version']];
    }

    public static function requireMatches(array $context, array $machine): void
    {
        $expected = self::forMachine($machine);
        MachinePolicyV1::record($context, array_keys($expected));
        MachinePolicyV1::require(CanonicalJson::encode($context) === CanonicalJson::encode($expected));
    }
}
