<?php

namespace App\Domain\Contracts;

use Throwable;

/** Explicit trusted versions: retained metadata never follows the current renderer implicitly. */
final class ContractRenderProfileRegistry
{
    public const CURRENT_VERSION = 'test-buyer-pdf-v1';

    public const V1_LIMITS = ['input_bytes' => 1048576, 'output_bytes' => 16777216, 'pages' => 100,
        'runtime_seconds' => 60, 'memory_bytes' => 134217728, 'claim_seconds' => 300,
        'max_attempts' => 5, 'backoff_seconds' => 60];

    // A successor requires its own reviewed metadata, policy and verified asset manifest.
    // Request data, configuration and discovered filesystem directories cannot register a profile.
    private const PROFILES = [
        'test-buyer-pdf-v1' => [
            'assets_directory' => 'resources/contracts/test-v1',
            'metadata' => ['schema_version' => 1, 'version' => 'test-buyer-pdf-v1', 'test_only' => true,
                'issuance_policy' => ContractIssuancePolicy::V1_CONTRACT, 'renderer' => 'tecnickcom/tc-lib-pdf', 'template' => 'frozen-grant-sections-v1',
                'php_runtime' => '8.4', 'timezone' => 'UTC', 'pdf_conformance' => 'plain-pdf',
                'page' => ['format' => 'A4', 'orientation' => 'P', 'margin_mm' => 15, 'font_size_pt' => 10],
                'font_family' => 'dejavusans', 'font_subset' => false, 'compression' => false,
                'allowed_scripts' => ['Latin', 'Greek', 'Cyrillic', 'Common'], 'combining_marks' => false,
                'remote_resources' => false, 'markup_resources' => false,
                'limits' => self::V1_LIMITS],
        ],
    ];

    public static function assetsDirectory(string $version): string
    {
        return self::definition($version)['assets_directory'];
    }

    private static function definition(string $version): array
    {
        return self::PROFILES[$version] ?? throw new ContractIssuanceException('profile_changed');
    }

    public static function metadata(string $version, ?string $projectRoot = null): array
    {
        $definition = self::definition($version);
        $root = $projectRoot ?? dirname(__DIR__, 3);
        $path = $root.'/'.$definition['assets_directory'].'/profile-assets.json';
        if (! is_file($path) || is_link($path) || filesize($path) > 65536) {
            throw new ContractIssuanceException('profile_changed');
        }
        try {
            $assets = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new ContractIssuanceException('profile_changed');
        }
        if (! is_array($assets) || ($assets['schema_version'] ?? null) !== 1
            || ($assets['profile_version'] ?? null) !== $version
            || ! is_array($assets['implementation'] ?? null) || count($assets['implementation']) !== 2) {
            throw new ContractIssuanceException('profile_changed');
        }

        return $definition['metadata'] + ['assets' => $assets];
    }
}
