<?php

namespace App\Domain\Grants\Free;

use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Contracts\ContractRenderProfile;
use App\Domain\Contracts\ContractRenderProfileRegistry;
use App\Support\CanonicalJson;

/** Separate free purpose/template over the retained trusted offline fonts and package identities. */
final class FreeGrantRenderProfile
{
    private const MANIFEST_HASH = '570f6a023927228e0ac6def670df603b5b595ffd916acf69bf569fecaeab74c8';

    public static function current(?string $root = null): array
    {
        $root ??= dirname(__DIR__, 4);
        $path = $root.'/resources/contracts/free-v1/profile-assets.json';
        if (! is_file($path) || is_link($path) || filesize($path) > 65536 || ! hash_equals(self::MANIFEST_HASH, hash_file('sha256', $path))) {
            throw new ContractIssuanceException('profile_changed');
        }
        $manifest = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);

        return ['schema_version' => 1, 'version' => 'test-free-grant-pdf-v1', 'purpose' => 'free-license-grant', 'test_only' => true,
            'template' => 'free-grant-sections-v1', 'base' => ContractRenderProfileRegistry::metadata('test-buyer-pdf-v2', $root),
            'limits' => ContractRenderProfileRegistry::V1_LIMITS, 'implementation' => $manifest];
    }

    public static function validate(array $profile): array
    {
        if (CanonicalJson::hash($profile) !== CanonicalJson::hash(self::current())) {
            throw new ContractIssuanceException('profile_changed');
        }

        return $profile;
    }

    public static function verifyRuntime(array $profile, ?string $root = null): void
    {
        self::validate($profile);
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
