<?php

namespace App\Domain\Contracts;

use App\Support\CanonicalJson;
use Composer\InstalledVersions;
use Throwable;

/** Retained metadata remains readable when rendering has been disabled or its assets withdrawn. */
final class ContractRenderProfile
{
    public const VERSION = ContractRenderProfileRegistry::CURRENT_VERSION;

    public const LIMITS = ContractRenderProfileRegistry::V1_LIMITS;

    public static function current(?string $projectRoot = null): array
    {
        $profile = ContractRenderProfileRegistry::metadata(self::VERSION, $projectRoot);
        self::verifyRuntime($profile, $projectRoot);

        return $profile;
    }

    /** Validate against the retained trusted manifest, without opening font files or loading the renderer. */
    public static function validate(array $profile): array
    {
        try {
            $version = $profile['version'] ?? null;
            if (! is_string($version)
                || ! hash_equals(CanonicalJson::hash(ContractRenderProfileRegistry::metadata($version)), CanonicalJson::hash($profile))) {
                throw new ContractIssuanceException('profile_changed');
            }
        } catch (Throwable) {
            throw new ContractIssuanceException('profile_changed');
        }

        return $profile;
    }

    public static function hash(array $profile): string
    {
        return CanonicalJson::hash(self::validate($profile));
    }

    public static function verifyRuntime(array $profile, ?string $projectRoot = null): void
    {
        self::validate($profile);
        $root = $projectRoot ?? dirname(__DIR__, 3);
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
            if (! is_file($file) || is_link($file) || ! hash_equals($sha256, hash_file('sha256', $file))) {
                throw new ContractIssuanceException('profile_changed');
            }
        }
        foreach ($profile['assets']['assets'] as $path => $identity) {
            $file = $root.'/'.ContractRenderProfileRegistry::assetsDirectory($profile['version']).'/'.$path;
            if (! is_file($file) || is_link($file) || filesize($file) !== $identity['size_bytes']
                || ! hash_equals($identity['sha256'], hash_file('sha256', $file))) {
                throw new ContractIssuanceException('profile_changed');
            }
        }
    }
}
