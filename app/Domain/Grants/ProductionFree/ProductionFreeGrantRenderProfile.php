<?php

namespace App\Domain\Grants\ProductionFree;

use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Contracts\ContractRenderProfile;
use App\Domain\Contracts\ContractRenderProfileRegistry;
use App\Support\CanonicalJson;
use Illuminate\Container\Container;
use Throwable;

/**
 * Sealed profile `production-free-grant-pdf-v1`: its own template and implementation manifest over the retained
 * trusted offline fonts/packages (`test-buyer-pdf-v2` base metadata). It does not wrap or relabel the old
 * `test-free-grant-pdf-v1` profile.
 */
final class ProductionFreeGrantRenderProfile
{
    public const VERSION = 'production-free-grant-pdf-v1';

    public const TEMPLATE = 'production-free-grant-sections-v1';

    /**
     * Registry of every released sealed profile: `CanonicalJson::hash` of the full profile document, by provenance.
     * A stored definition or origin is accepted for reading and delivery only when its own profile is listed here
     * (its row seal and `profile_hash` column already bind it), so a legitimate renderer revision never strands
     * existing grants. Append a new revision when the implementation manifest, `MANIFEST_HASH` or the retained base
     * changes; never edit or remove an entry, because grants sealed under it still exist. A test fails when the
     * current runtime profile is not listed.
     */
    public const RELEASED = [
        'r1' => [
            'synthetic_rehearsal' => 'd3d82aa5d53ac05dcc063fd01d861e8052c4cb332de2c0922c96a470d3ddd4aa',
            'verified_production' => '6f4e39b9ba7de0e3e79aeea1fc23b0048eb06f4126093b2b689d1f7eceb021ed',
        ],
    ];

    private const MANIFEST_HASH = 'b909c5fa80e882d7d6f2ab4bf8b05f315f46695d4a9acb621b0350a02d7f7b1f';

    public static function current(string $provenance, ?string $root = null): array
    {
        if (! in_array($provenance, ['synthetic_rehearsal', 'verified_production'], true)) {
            throw new ContractIssuanceException('profile_changed');
        }
        $root ??= self::root();
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

    /**
     * Read-side check for a profile stored in a sealed definition or origin: it must be a released profile, and
     * the runtime is not consulted. Rendering and recovery still require the runtime to equal it (`requireCurrent`).
     */
    public static function validateStored(array $profile): array
    {
        $provenance = $profile['provenance'] ?? null;
        $released = is_string($provenance) ? array_column(self::RELEASED, $provenance) : [];
        if ($released === [] || ! in_array(CanonicalJson::hash($profile), $released, true)) {
            throw new ContractIssuanceException('profile_changed');
        }

        return $profile;
    }

    /** Write-side check: the stored profile is the one this runtime would render with right now. */
    public static function requireCurrent(array $profile, ?string $root = null): void
    {
        try {
            self::validate($profile, $root);
        } catch (Throwable) {
            throw new ProductionFreeGrantException('profile_changed');
        }
    }

    public static function verifyRuntime(array $profile, ?string $root = null): void
    {
        self::validate($profile, $root);
        $root ??= self::root();
        ContractRenderProfile::verifyVersionRuntime($profile['base'], 'test-buyer-pdf-v2', $root);
        foreach ($profile['implementation']['files'] as $relative => $sha256) {
            $file = $root.'/'.$relative;
            if (! is_file($file) || is_link($file) || ! hash_equals($sha256, hash_file('sha256', $file))) {
                throw new ContractIssuanceException('profile_changed');
            }
        }
    }

    /**
     * The project root the runtime profile is read from. It is the application's `path.base` binding when one
     * exists (always the same directory as the file-relative root in the application) and the file-relative
     * root otherwise, so the bounded child renderer, which has no container, is unchanged.
     */
    private static function root(): string
    {
        $container = class_exists(Container::class) ? Container::getInstance() : null;

        return $container !== null && $container->bound('path.base') ? (string) $container->make('path.base') : dirname(__DIR__, 4);
    }
}
