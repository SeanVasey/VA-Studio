<?php

namespace App\Domain\Contracts\Production;

use App\Domain\Contracts\ContractIssuanceException;
use App\Support\CanonicalJson;
use Composer\InstalledVersions;
use Throwable;

/**
 * Increment 4: the `vasey-license-pdf-v1` render profile for Sean's license templates. Registered here, beside the
 * test-only registry rather than inside it: `ContractRenderProfileRegistry` is frozen-byte reviewed substrate, and
 * a profile listed there would be accepted by the test issuance path. This one is pinned the same way (the
 * manifest's SHA-256 below), is never selected for issuance, and no issuer, renderer dispatch or policy names it.
 *
 * The manifest pins the four converted brand faces, the unmodified VASEY.AUDIO lockup, the tc-lib package graph and
 * the renderer implementation files. Sean's signature is not in the manifest or in Git: it is read at render time
 * from private storage against a separately configured pin ({@see VaseyLicenseSignature}).
 */
final class VaseyLicenseProfile
{
    public const VERSION = VaseyLicenseProfileDefinition::VERSION;

    public const ASSETS_DIRECTORY = VaseyLicenseProfileDefinition::ASSETS_DIRECTORY;

    public const MANIFEST_SHA256 = 'd1db00a2bc733ea5008fce9da1659e45d5f4c207f8895e96e2fb7d59526141cb';

    public const FACES = VaseyLicenseProfileDefinition::FACES;

    public const LOGO = VaseyLicenseProfileDefinition::LOGO;

    /** The profile metadata with its verified asset manifest. Refuses `profile_changed` on any manifest drift. */
    public static function current(?string $projectRoot = null): array
    {
        $root = $projectRoot ?? self::defaultRoot();
        $path = $root.'/'.self::ASSETS_DIRECTORY.'/profile-assets.json';
        // Read once: the bytes that are hashed are the bytes that are decoded.
        $bytes = is_file($path) && ! is_link($path) ? file_get_contents($path, false, null, 0, 65537) : false;
        if (! is_string($bytes) || strlen($bytes) > 65536 || ! hash_equals(self::MANIFEST_SHA256, hash('sha256', $bytes))) {
            throw new ContractIssuanceException('profile_changed');
        }
        try {
            $assets = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new ContractIssuanceException('profile_changed');
        }
        if (! is_array($assets) || ($assets['schema_version'] ?? null) !== 1 || ($assets['profile_version'] ?? null) !== self::VERSION
            || ! is_array($assets['packages'] ?? null) || ! is_array($assets['implementation'] ?? null) || ! is_array($assets['assets'] ?? null)) {
            throw new ContractIssuanceException('profile_changed');
        }

        return VaseyLicenseProfileDefinition::METADATA + ['assets' => $assets];
    }

    /** A retained profile must equal the trusted current one exactly. */
    public static function validate(array $profile, ?string $projectRoot = null): array
    {
        try {
            if (($profile['version'] ?? null) !== self::VERSION || ! hash_equals(CanonicalJson::hash(self::current($projectRoot)), CanonicalJson::hash($profile))) {
                throw new ContractIssuanceException('profile_changed');
            }
        } catch (Throwable) {
            throw new ContractIssuanceException('profile_changed');
        }

        return $profile;
    }

    public static function hash(array $profile, ?string $projectRoot = null): string
    {
        return CanonicalJson::hash(self::validate($profile, $projectRoot));
    }

    /** The runtime, packages, implementation files and every pinned asset must be the reviewed bytes. */
    public static function verifyRuntime(array $profile, ?string $projectRoot = null): void
    {
        $root = $projectRoot ?? self::defaultRoot();
        self::validate($profile, $root);
        if (PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 4) {
            throw new ContractIssuanceException('profile_changed');
        }
        foreach ($profile['assets']['packages'] as $name => $identity) {
            if (! InstalledVersions::isInstalled($name)
                || ltrim((string) InstalledVersions::getPrettyVersion($name), 'v') !== $identity['version']
                || InstalledVersions::getReference($name) !== $identity['reference']) {
                throw new ContractIssuanceException('profile_changed');
            }
        }
        foreach ($profile['assets']['implementation'] as $path => $sha256) {
            $file = $root.'/'.$path;
            if (! is_file($file) || is_link($file) || ! hash_equals($sha256, (string) hash_file('sha256', $file))) {
                throw new ContractIssuanceException('profile_changed');
            }
        }
        foreach ($profile['assets']['assets'] as $path => $identity) {
            self::assetBytes($profile, $path, $root);
        }
    }

    /**
     * Read one pinned asset and check the bytes actually read, so a file swapped after verification is never used.
     */
    public static function assetBytes(array $profile, string $asset, ?string $projectRoot = null): string
    {
        $identity = $profile['assets']['assets'][$asset] ?? null;
        $file = ($projectRoot ?? self::defaultRoot()).'/'.self::ASSETS_DIRECTORY.'/'.$asset;
        if (! is_array($identity) || ! is_file($file) || is_link($file) || filesize($file) !== $identity['size_bytes']) {
            throw new ContractIssuanceException('profile_changed');
        }
        $bytes = file_get_contents($file);
        if (! is_string($bytes) || strlen($bytes) !== $identity['size_bytes'] || ! hash_equals($identity['sha256'], hash('sha256', $bytes))) {
            throw new ContractIssuanceException('profile_changed');
        }

        return $bytes;
    }

    public static function assetPath(string $asset, ?string $projectRoot = null): string
    {
        return ($projectRoot ?? self::defaultRoot()).'/'.self::ASSETS_DIRECTORY.'/'.$asset;
    }

    private static function defaultRoot(): string
    {
        return dirname(__DIR__, 4);
    }
}
