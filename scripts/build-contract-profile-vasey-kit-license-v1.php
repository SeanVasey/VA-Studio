<?php

declare(strict_types=1);

/*
 * Build or check the manifest of the `vasey-kit-license-pdf-v1` contract profile (the sound-kit license, BL-66).
 *
 *   php scripts/build-contract-profile-vasey-kit-license-v1.php           rebuild the manifest and rewrite
 *                                                                         VaseyKitLicenseProfile::MANIFEST_SHA256
 *   php scripts/build-contract-profile-vasey-kit-license-v1.php --check   rebuild in memory and compare byte for byte,
 *                                                                         and check the committed MANIFEST_SHA256
 *
 * The kit profile prints with the v2 profile's font and logo files (resources/contracts/vasey-license-v2), shared by hash
 * rather than copied, so this script derives the kit manifest from them and writes nothing else: it never converts a font and
 * reads no signature. It refuses when the v2 files are not the ones v2's own manifest pins (run the v2 build script's --check).
 *
 * Re-pinning after a renderer dependency update (composer update of tecnickcom/tc-lib-*) or any change to a file the kit
 * implementation closure names: rebuild v1 and v2 first if their own --check fails, then run this script without --check.
 * scripts/repin-vasey-kit-license-v1.sh runs this script and then re-pins the golden render hash of the kit renderer test.
 */

use App\Domain\Contracts\Production\VaseyKitLicenseImplementation;
use App\Domain\Contracts\Production\VaseyKitLicenseProfile;
use App\Domain\Contracts\Production\VaseyKitLicenseProfileDefinition;
use App\Domain\Contracts\Production\VaseyLicenseV2Profile;
use Composer\InstalledVersions;

require dirname(__DIR__).'/vendor/autoload.php';

$root = dirname(__DIR__);
$check = in_array('--check', array_slice($argv, 1), true);
foreach (array_slice($argv, 1) as $argument) {
    if ($argument !== '--check') {
        fwrite(STDERR, 'Unknown argument: '.$argument."\n");
        exit(2);
    }
}
$fail = static function (string $message): never {
    fwrite(STDERR, $message."\n");
    exit(1);
};

// The shared files must be the ones the v2 manifest pins, then they are pinned again here by hash and size.
try {
    $shared = VaseyLicenseV2Profile::current($root);
    VaseyLicenseV2Profile::verifyRuntime($shared, $root);
} catch (Throwable) {
    $fail('The v2 profile files are not the ones its manifest pins; run scripts/build-contract-profile-vasey-license-v2.php first.');
}
$assets = $shared['assets']['assets'];
ksort($assets, SORT_STRING);

$packages = [];
foreach (InstalledVersions::getInstalledPackages() as $name) {
    if (str_starts_with($name, 'tecnickcom/')) {
        $packages[$name] = ['version' => ltrim((string) InstalledVersions::getPrettyVersion($name), 'v'), 'reference' => InstalledVersions::getReference($name)];
    }
}
ksort($packages);
if ($packages !== $shared['assets']['packages']) {
    $fail('The installed tecnickcom packages differ from the v2 manifest; rebuild v2 (and v1) first, then this profile.');
}
$implementation = [];
foreach (VaseyKitLicenseImplementation::closure($root) as $path) {
    if (! is_file($root.'/'.$path) || is_link($root.'/'.$path)) {
        $fail('The pinned implementation is missing: '.$path);
    }
    $implementation[$path] = hash_file('sha256', $root.'/'.$path);
}

$manifest = [
    'schema_version' => 1,
    'profile_version' => VaseyKitLicenseProfileDefinition::VERSION,
    'shared_assets' => ['profile_version' => 'vasey-license-pdf-v2', 'directory' => VaseyKitLicenseProfileDefinition::ASSET_FILES_DIRECTORY,
        'note' => 'The font and logo files are the v2 profile\'s own, pinned here by SHA-256 and size; none is copied.'],
    'signature' => ['in_manifest' => false, 'source' => 'private_storage', 'pin' => 'config:contracts.vasey_license_v1.signature_sha256'],
    'packages' => $packages,
    'implementation' => $implementation,
    'assets' => $assets,
];
$bytes = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
$hash = hash('sha256', $bytes);

$target = $root.'/'.VaseyKitLicenseProfileDefinition::ASSETS_DIRECTORY;
$manifestPath = $target.'/profile-assets.json';
$profilePath = $root.'/app/Domain/Contracts/Production/VaseyKitLicenseProfile.php';
$anchor = (string) file_get_contents($profilePath);
$constant = "public const MANIFEST_SHA256 = '";

if ($check) {
    $committed = is_file($manifestPath) && ! is_link($manifestPath) ? file_get_contents($manifestPath) : false;
    if ($committed !== $bytes) {
        $fail('The rebuilt manifest differs from the committed profile-assets.json.');
    }
    if (! str_contains($anchor, $constant.$hash."';")) {
        $fail('VaseyKitLicenseProfile::MANIFEST_SHA256 is not the rebuilt manifest hash '.$hash.'.');
    }
    foreach (scandir($target) ?: [] as $entry) {
        if (! in_array($entry, ['.', '..', 'profile-assets.json', 'PROVENANCE.md'], true)) {
            $fail('Unexpected entry in the kit profile directory: '.$entry);
        }
    }
    fwrite(STDOUT, "Rebuilt manifest is byte-identical to the committed profile.\n");
} else {
    if (! is_dir($target)) {
        mkdir($target, 0755, true);
    }
    file_put_contents($manifestPath, $bytes);
    $rewritten = preg_replace("/public const MANIFEST_SHA256 = '[0-9a-f]{64}';/", $constant.$hash."';", $anchor, 1, $count);
    if ($count !== 1 || ! is_string($rewritten)) {
        $fail('Could not rewrite VaseyKitLicenseProfile::MANIFEST_SHA256.');
    }
    file_put_contents($profilePath, $rewritten);
    fwrite(STDOUT, 'Wrote profile-assets.json (pinning '.count($assets).' shared assets and '.count($implementation)." implementation files) and the MANIFEST_SHA256 constant.\n");
}
fwrite(STDOUT, "MANIFEST_SHA256 = '".$hash."'\n");
