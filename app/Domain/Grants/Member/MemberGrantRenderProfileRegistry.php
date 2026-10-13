<?php

namespace App\Domain\Grants\Member;

use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Contracts\ContractRenderProfile;
use App\Domain\Contracts\ContractRenderProfileRegistry;
use App\Support\CanonicalJson;
use Throwable;

/** Member-only released technical descriptors; retained validation performs no I/O. */
final class MemberGrantRenderProfileRegistry
{
    public const VERSION = 'production-member-original-pdf-v1';

    public const TEMPLATE = 'production-member-original-sections-v1';

    public const LIMITS = ['input_bytes' => 1048576, 'output_bytes' => 16777216, 'pages' => 100,
        'runtime_seconds' => 60, 'memory_bytes' => 134217728];

    // Filled from the complete new implementation before its first release. Never replace a released entry.
    private const MANIFEST_HASH = 'caa3218c162ef3c9192785858e6cd9fad89ab828a345466652dda21e74a1c852';

    public const RELEASED = [
        'r1' => [
            'synthetic_rehearsal' => '1c518ec10f5dce9cf538b48de4e1034cd1fe1574fe21c9f0819d0638972e434e',
            'verified_production' => 'd9047a26ebdc6b2bbfc44d00f3fbd93bfc1504e61948bf9666e7acadde712cbd',
        ],
    ];

    private const DESCRIPTOR = [
        'schema_version' => 1,
        'version' => 'production-member-original-pdf-v1',
        'purpose' => 'production-member-license-grant-v1',
        'template' => 'production-member-original-sections-v1',
        'base' => [
            'schema_version' => 1,
            'version' => 'test-buyer-pdf-v2',
            'test_only' => true,
            'issuance_policy' => [
                'schema_version' => 1,
                'purpose' => 'test_contract_issuance',
                'version' => 'test-contract-issuance-v2',
                'profile' => 'test-buyer-pdf-v2',
                'originals' => 'preserve_first_committed',
                'missing_original' => 'restore_only',
                'buyer_identity' => 'unverified_guest',
                'entitlements' => 'pending',
                'lease_seconds' => 300,
                'max_attempts' => 5,
                'retry_seconds' => 60,
            ],
            'renderer' => 'tecnickcom/tc-lib-pdf',
            'template' => 'frozen-grant-sections-v1',
            'php_runtime' => '8.4',
            'timezone' => 'UTC',
            'pdf_conformance' => 'plain-pdf',
            'page' => [
                'format' => 'A4',
                'orientation' => 'P',
                'margin_mm' => 15,
                'font_size_pt' => 10,
            ],
            'font_family' => 'dejavusans',
            'font_subset' => false,
            'compression' => false,
            'allowed_scripts' => [
                0 => 'Latin',
                1 => 'Greek',
                2 => 'Cyrillic',
                3 => 'Common',
            ],
            'combining_marks' => false,
            'remote_resources' => false,
            'markup_resources' => false,
            'limits' => [
                'input_bytes' => 1048576,
                'output_bytes' => 16777216,
                'pages' => 100,
                'runtime_seconds' => 60,
                'memory_bytes' => 134217728,
                'claim_seconds' => 300,
                'max_attempts' => 5,
                'backoff_seconds' => 60,
            ],
            'assets' => [
                'schema_version' => 1,
                'profile_version' => 'test-buyer-pdf-v2',
                'font_source' => [
                    'repository' => 'tecnickcom/tc-font-mirror',
                    'release' => '2.4.0',
                    'reference' => '3251310e5f8e92659ac3ef1133e591ed681ef95d',
                ],
                'font_conversion' => [
                    'type' => 'TrueTypeUnicode',
                    'flags' => 32,
                    'platform_id' => 3,
                    'encoding_id' => 10,
                ],
                'packages' => [
                    'tecnickcom/tc-lib-barcode' => [
                        'version' => '2.16.4',
                        'reference' => 'e0b07db25cd223cacdfcfc1af728585781bd2bda',
                    ],
                    'tecnickcom/tc-lib-color' => [
                        'version' => '3.0.7',
                        'reference' => '8455d371882d2e3eb2742d4d6fb82ca5aebb95db',
                    ],
                    'tecnickcom/tc-lib-file' => [
                        'version' => '3.9.4',
                        'reference' => '02089faaaed42cb5137d23ba2236e6809124999e',
                    ],
                    'tecnickcom/tc-lib-pdf' => [
                        'version' => '8.76.3',
                        'reference' => 'd417129fad37d49dc9fc0d1e229e9740e1c774a3',
                    ],
                    'tecnickcom/tc-lib-pdf-encrypt' => [
                        'version' => '2.11.4',
                        'reference' => 'c9e6ee4456d4701b20897a12e35eb2820c723abd',
                    ],
                    'tecnickcom/tc-lib-pdf-filter' => [
                        'version' => '2.11.4',
                        'reference' => '2b0e0f9f56ae8e8baeda9a7aebd8e8321b493e30',
                    ],
                    'tecnickcom/tc-lib-pdf-font' => [
                        'version' => '4.4.1',
                        'reference' => 'a78b8e0ac9284d1594b6ba68488cf65c3e958f7b',
                    ],
                    'tecnickcom/tc-lib-pdf-graph' => [
                        'version' => '2.18.2',
                        'reference' => 'bb87e68d21792e5722e8395a3ef471c74b81d70d',
                    ],
                    'tecnickcom/tc-lib-pdf-image' => [
                        'version' => '3.14.4',
                        'reference' => '18039dff586d90c749d88f76c9eab45d3ea27ccf',
                    ],
                    'tecnickcom/tc-lib-pdf-page' => [
                        'version' => '4.16.5',
                        'reference' => '5c6337e6dd3d8f1637dafc653e83f9493351151d',
                    ],
                    'tecnickcom/tc-lib-pdf-parser' => [
                        'version' => '3.16.2',
                        'reference' => '074599a52f1ef8cbac67dcb9b10dd2a730760c70',
                    ],
                    'tecnickcom/tc-lib-pdf-sign' => [
                        'version' => '2.0.5',
                        'reference' => 'cc3d9afc536a5878326a5d34d08492c1d475960a',
                    ],
                    'tecnickcom/tc-lib-unicode' => [
                        'version' => '3.0.9',
                        'reference' => '36bab72c262b15cc78476ab951049652d801307a',
                    ],
                    'tecnickcom/tc-lib-unicode-data' => [
                        'version' => '3.0.9',
                        'reference' => '24e5cec5ee4ce607fab77aceb1a67a3eaf817528',
                    ],
                ],
                'implementation' => [
                    'app/Domain/Contracts/ContractText.php' => '46d145c921ff5a94b3d2928c9b7d9caf0697d33f8ba5c5c30ca65598e1d68a08',
                    'app/Domain/Contracts/TcpdfV2ContractRenderer.php' => '36f8454d055bae4b20f9eb67a861d4a4709fc1708acb4a47d353c4ba061e70d2',
                ],
                'assets' => [
                    'fonts/generated/dejavusans.ctg.z' => [
                        'sha256' => 'e32a1fea0a049ccf1c687eba84f804339c4599eba4eedb1ff4ee4427aee34b93',
                        'size_bytes' => 10501,
                    ],
                    'fonts/generated/dejavusans.json' => [
                        'sha256' => '4912381c5c0baccdca18246cb5ffb8d234fae48de71d002a523d082859bbfde4',
                        'size_bytes' => 215918,
                    ],
                    'fonts/generated/dejavusans.z' => [
                        'sha256' => 'bf01c0d0a4d745951b355b0d253b4076d5e0da2aef8114d42c0f2ab83ec257fe',
                        'size_bytes' => 381440,
                    ],
                    'fonts/generated/dejavusansb.ctg.z' => [
                        'sha256' => 'e59d2256ed0a75d999d124c994c42bb025efa782c3e77e251f7497f4f539e089',
                        'size_bytes' => 10491,
                    ],
                    'fonts/generated/dejavusansb.json' => [
                        'sha256' => '5128b3fcdd515b6fc80cceca16f0b0118be333aa1558cf193c487c4ad3144a10',
                        'size_bytes' => 215364,
                    ],
                    'fonts/generated/dejavusansb.z' => [
                        'sha256' => '34fa710f984b43306f89abd2458a213a63ecfecd59976154a03183b65a8f1554',
                        'size_bytes' => 352720,
                    ],
                    'fonts/source/DejaVuSans-Bold.ttf' => [
                        'sha256' => 'e6476c1b80502924294eed40894c5b18e06c181444ca953e5334262df9c27724',
                        'git_blob' => '6d65fa7dc41ae8ffae77a4a843a73ba31ffd78c7',
                        'size_bytes' => 705684,
                    ],
                    'fonts/source/DejaVuSans.ttf' => [
                        'sha256' => '7da195a74c55bef988d0d48f9508bd5d849425c1770dba5d7bfc6ce9ed848954',
                        'git_blob' => 'e5f7eecce43be41ff0703ed99e1553029b849f14',
                        'size_bytes' => 757076,
                    ],
                    'fonts/source/LICENSE' => [
                        'sha256' => '7a083b136e64d064794c3419751e5c7dd10d2f64c108fe5ba161eae5e5958a93',
                        'git_blob' => 'df52c1709bea171104d41bf084313ea434858423',
                        'size_bytes' => 8816,
                    ],
                ],
            ],
        ],
        'limits' => [
            'input_bytes' => 1048576,
            'output_bytes' => 16777216,
            'pages' => 100,
            'runtime_seconds' => 60,
            'memory_bytes' => 134217728,
        ],
        'implementation' => [
            'schema_version' => 1,
            'version' => 'production-member-original-pdf-v1',
            'files' => [
                'app/Domain/Grants/Member/MemberOriginalRenderInput.php' => '6b470c6d2dfadbb74d24bc7263e347985ce92864c29b236aac50d70bd379e3c7',
                'app/Domain/Grants/Member/MemberOriginalText.php' => '959d90054dfb6513dff278359d4d27709da4b0131faef674e2cdd6a6689902da',
                'app/Domain/Grants/Member/MemberOriginalPdfRenderer.php' => 'f3acf36fcf4938b14a8efb742ad46a17dc7c3001a478833d36a48d09ce5c7ed2',
                'app/Domain/Grants/Member/MemberOriginalRendererProcess.php' => 'f0470e91b122b23588088a7361971b9e009f2098d86e9d1321a3cea47c610085',
                'scripts/member-original-renderer-autoload.php' => 'adca75e6c55194265c0395df088cf77b8aab37a30923435e3b5e2704ada7193c',
                'scripts/render-member-original.php' => '880ec7590bba84b24c0944986b9c71e6484cd0b63f25b0e55add1b6a7f4be0c3',
            ],
        ],
    ];

    /** Exact retained technical values; this neither opens runtime files nor authorizes issuance. */
    public static function released(string $provenance): array
    {
        return self::validateStored(self::DESCRIPTOR + ['provenance' => $provenance]);
    }

    public static function validateStored(array $profile): array
    {
        try {
            $provenance = $profile['provenance'] ?? null;
            $hashes = is_string($provenance) ? array_column(self::RELEASED, $provenance) : [];
            if ($hashes === [] || ! in_array(CanonicalJson::hash($profile), $hashes, true)) {
                throw new ContractIssuanceException('profile_changed');
            }

            return $profile;
        } catch (Throwable) {
            throw new ContractIssuanceException('profile_changed');
        }
    }

    public static function current(string $provenance, MemberOriginalIoFrame $idle): array
    {
        $idle->assertStillIdle();
        $profile = self::descriptor($provenance, dirname(__DIR__, 4));
        $idle->assertStillIdle();
        self::verifyRuntime($profile, $idle);

        return $profile;
    }

    public static function verifyRuntime(array $profile, MemberOriginalIoFrame $idle): void
    {
        $idle->assertStillIdle();
        self::verify($profile, dirname(__DIR__, 4));
        $idle->assertStillIdle();
    }

    /** Only the bounded child has neither a database extension nor an application container loaded. */
    public static function verifyChildRuntime(array $profile, string $root): void
    {
        if (PHP_SAPI !== 'cli' || class_exists('PDO', false) || class_exists('Illuminate\\Container\\Container', false)) {
            throw new ContractIssuanceException('profile_changed');
        }
        self::verify($profile, $root);
    }

    private static function descriptor(string $provenance, string $root): array
    {
        if (! in_array($provenance, ['synthetic_rehearsal', 'verified_production'], true)) {
            throw new ContractIssuanceException('profile_changed');
        }
        $path = $root.'/resources/contracts/production-member-v1/profile-assets.json';
        if (! is_file($path) || is_link($path) || filesize($path) > 65536
            || ! hash_equals(self::MANIFEST_HASH, hash_file('sha256', $path))) {
            throw new ContractIssuanceException('profile_changed');
        }
        try {
            $manifest = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($manifest) || ($manifest['schema_version'] ?? null) !== 1
                || ($manifest['version'] ?? null) !== self::VERSION || ! is_array($manifest['files'] ?? null)) {
                throw new ContractIssuanceException('profile_changed');
            }

            return ['schema_version' => 1, 'version' => self::VERSION, 'purpose' => MemberOriginalRenderInput::PURPOSE,
                'provenance' => $provenance, 'template' => self::TEMPLATE,
                'base' => ContractRenderProfileRegistry::metadata('test-buyer-pdf-v2', $root),
                'limits' => self::LIMITS, 'implementation' => $manifest];
        } catch (Throwable) {
            throw new ContractIssuanceException('profile_changed');
        }
    }

    private static function verify(array $profile, string $root): void
    {
        self::validateStored($profile);
        try {
            $current = self::descriptor($profile['provenance'], $root);
            if (! hash_equals(CanonicalJson::hash($current), CanonicalJson::hash($profile))) {
                throw new ContractIssuanceException('profile_changed');
            }
            // These retained helpers read files. Only the guarded parent/isolated child paths call them.
            ContractRenderProfile::verifyVersionRuntime($profile['base'], 'test-buyer-pdf-v2', $root);
            foreach ($profile['implementation']['files'] as $relative => $sha256) {
                $path = $root.'/'.$relative;
                if (! is_file($path) || is_link($path) || ! hash_equals($sha256, hash_file('sha256', $path))) {
                    throw new ContractIssuanceException('profile_changed');
                }
            }
        } catch (Throwable) {
            throw new ContractIssuanceException('profile_changed');
        }
    }
}
