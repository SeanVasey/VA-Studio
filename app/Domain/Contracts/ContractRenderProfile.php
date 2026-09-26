<?php

namespace App\Domain\Contracts;

use App\Support\CanonicalJson;
use Composer\InstalledVersions;
use Throwable;

/** Retained metadata remains readable when rendering has been disabled or its assets withdrawn. */
final class ContractRenderProfile
{
    public const VERSION = 'test-buyer-pdf-v1';

    public const LIMITS = ['input_bytes' => 1048576, 'output_bytes' => 16777216, 'pages' => 100,
        'runtime_seconds' => 60, 'memory_bytes' => 134217728, 'claim_seconds' => 300,
        'max_attempts' => 5, 'backoff_seconds' => 60];

    public static function current(?string $projectRoot = null): array
    {
        $profile = self::metadata($projectRoot);
        self::verifyRuntime($profile, $projectRoot);

        return $profile;
    }

    /** Validate against the retained trusted manifest, without opening font files or loading the renderer. */
    public static function validate(array $profile): array
    {
        try {
            if (! hash_equals(CanonicalJson::hash(self::metadata()), CanonicalJson::hash($profile))) {
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
            $file = $root.'/resources/contracts/test-v1/'.$path;
            if (! is_file($file) || is_link($file) || filesize($file) !== $identity['size_bytes']
                || ! hash_equals($identity['sha256'], hash_file('sha256', $file))) {
                throw new ContractIssuanceException('profile_changed');
            }
        }
    }

    private static function metadata(?string $projectRoot = null): array
    {
        $root = $projectRoot ?? dirname(__DIR__, 3);
        $path = $root.'/resources/contracts/test-v1/profile-assets.json';
        if (! is_file($path) || is_link($path) || filesize($path) > 65536) {
            throw new ContractIssuanceException('profile_changed');
        }
        try {
            $assets = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new ContractIssuanceException('profile_changed');
        }
        if (! is_array($assets) || ($assets['schema_version'] ?? null) !== 1
            || ($assets['profile_version'] ?? null) !== self::VERSION
            || ! is_array($assets['implementation'] ?? null) || count($assets['implementation']) !== 2) {
            throw new ContractIssuanceException('profile_changed');
        }

        return ['schema_version' => 1, 'version' => self::VERSION, 'test_only' => true,
            'issuance_policy' => ContractIssuancePolicy::CONTRACT, 'renderer' => 'tecnickcom/tc-lib-pdf', 'template' => 'frozen-grant-sections-v1',
            'php_runtime' => '8.4', 'timezone' => 'UTC', 'pdf_conformance' => 'plain-pdf',
            'page' => ['format' => 'A4', 'orientation' => 'P', 'margin_mm' => 15, 'font_size_pt' => 10],
            'font_family' => 'dejavusans', 'font_subset' => false, 'compression' => false,
            'allowed_scripts' => ['Latin', 'Greek', 'Cyrillic', 'Common'], 'combining_marks' => false,
            'remote_resources' => false, 'markup_resources' => false,
            'limits' => self::LIMITS, 'assets' => $assets];
    }
}
