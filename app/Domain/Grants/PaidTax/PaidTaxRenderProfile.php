<?php

namespace App\Domain\Grants\PaidTax;

use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Contracts\ContractRenderProfile;
use App\Domain\Contracts\ContractRenderProfileRegistry;
use App\Support\CanonicalJson;

/**
 * Separate paid purpose/template over the retained trusted offline fonts and package identities.
 *
 * Release rule: there is one released paid profile, and a document is rendered only under the exact profile its order
 * retained (`validate()` compares it with `current()`). Changing a file pinned by `resources/contracts/paid-tax-v2/
 * profile-assets.json`, `MANIFEST_HASH` or the retained base therefore makes every order whose document is still
 * unfinished fail with `profile_changed` until its five attempts are spent. Before any such change ships, either
 * drain every unfinished paid document, or add the new profile as a separate version (its own manifest directory and
 * renderer) while keeping this one's files and an entry point that resolves the renderer by the retained version.
 * Completed documents are delivered from their stored bytes and do not consult the current profile.
 */
final class PaidTaxRenderProfile
{
    private const MANIFEST_HASH = '9cc50e19608a11be4db4648b49aa5ccf234945a63fced68d95f6b42bf2e048b4';

    public static function current(string $provenance, ?string $root = null): array
    {
        if (! in_array($provenance, ['synthetic_rehearsal', 'verified_production'], true)) {
            throw new ContractIssuanceException('profile_changed');
        }
        $root ??= dirname(__DIR__, 4);
        $path = $root.'/resources/contracts/paid-tax-v2/profile-assets.json';
        if (! is_file($path) || is_link($path) || filesize($path) > 65536 || ! hash_equals(self::MANIFEST_HASH, hash_file('sha256', $path))) {
            throw new ContractIssuanceException('profile_changed');
        }
        $manifest = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);

        return ['schema_version' => 2, 'version' => $provenance === 'synthetic_rehearsal' ? 'rehearsal-paid-tax-grant-pdf-v2' : 'verified-paid-tax-grant-pdf-v2', 'purpose' => 'paid-tax-license-grant', 'provenance' => $provenance,
            'template' => 'paid-tax-grant-sections-v2', 'base' => ContractRenderProfileRegistry::metadata('test-buyer-pdf-v2', $root),
            'limits' => ContractRenderProfileRegistry::V1_LIMITS, 'implementation' => $manifest];
    }

    public static function validate(array $profile): array
    {
        if (CanonicalJson::hash($profile) !== CanonicalJson::hash(self::current($profile['provenance'] ?? ''))) {
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
