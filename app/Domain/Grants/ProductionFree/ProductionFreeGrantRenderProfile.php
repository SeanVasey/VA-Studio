<?php

namespace App\Domain\Grants\ProductionFree;

use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Contracts\ContractRenderProfile;
use App\Domain\Contracts\ContractRenderProfileRegistry;
use App\Support\CanonicalJson;

/**
 * Sealed profile `production-free-grant-pdf-v1`: its own template and implementation manifest over the retained
 * trusted offline fonts/packages (`test-buyer-pdf-v2` base metadata). It does not wrap or relabel the old
 * `test-free-grant-pdf-v1` profile.
 */
final class ProductionFreeGrantRenderProfile
{
    public const VERSION = 'production-free-grant-pdf-v1';

    public const TEMPLATE = 'production-free-grant-sections-v1';

    private const MANIFEST_HASH = 'b909c5fa80e882d7d6f2ab4bf8b05f315f46695d4a9acb621b0350a02d7f7b1f';

    public static function current(string $provenance, ?string $root = null): array
    {
        if (! in_array($provenance, ['synthetic_rehearsal', 'verified_production'], true)) {
            throw new ContractIssuanceException('profile_changed');
        }
        $root ??= dirname(__DIR__, 4);
        $path = $root.'/resources/contracts/production-free-v1/profile-assets.json';
        if (! is_file($path) || is_link($path) || filesize($path) > 65536 || ! hash_equals(self::MANIFEST_HASH, hash_file('sha256', $path))) {
            throw new ContractIssuanceException('profile_changed');
        }
        $manifest = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);

        return ['schema_version' => 1, 'version' => self::VERSION, 'purpose' => 'production-free-license-grant-v1', 'provenance' => $provenance,
            'template' => self::TEMPLATE, 'base' => ContractRenderProfileRegistry::metadata('test-buyer-pdf-v2', $root),
            'limits' => ContractRenderProfileRegistry::V1_LIMITS, 'implementation' => $manifest];
    }

    public static function validate(array $profile, ?string $root = null): array
    {
        if (CanonicalJson::hash($profile) !== CanonicalJson::hash(self::current((string) ($profile['provenance'] ?? ''), $root))) {
            throw new ContractIssuanceException('profile_changed');
        }

        return $profile;
    }

    public static function verifyRuntime(array $profile, ?string $root = null): void
    {
        self::validate($profile, $root);
        $root ??= dirname(__DIR__, 4);
        ContractRenderProfile::verifyVersionRuntime($profile['base'], 'test-buyer-pdf-v2', $root);
        foreach ($profile['implementation']['files'] as $relative => $sha256) {
            $file = $root.'/'.$relative;
            if (! is_file($file) || is_link($file) || ! hash_equals($sha256, hash_file('sha256', $file))) {
                throw new ContractIssuanceException('profile_changed');
            }
        }
    }
}
