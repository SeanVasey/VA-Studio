<?php

declare(strict_types=1);

/*
 * Build or check the pinned assets of the `vasey-license-pdf-v1` contract profile.
 *
 *   php scripts/build-contract-profile-vasey-license-v1.php           rebuild resources/contracts/vasey-license-v1
 *   php scripts/build-contract-profile-vasey-license-v1.php --check   rebuild into a temporary directory and compare
 *
 * Inputs are the reviewed brand files only: the four OFL woff2 faces and their licenses under public/brand/fonts
 * (checked against public/brand/fonts/source-manifest.json) and the unmodified lockup public/brand/vasey-audio-logo.png.
 * The woff2 faces are decoded to static TrueType by scripts/vasey-license-fonts.py (pinned fontTools and brotli), then
 * converted by tc-lib-pdf-font's own util/convert.php. No signature is read or written: Sean's signature stays in
 * private storage and is pinned in configuration, never in this manifest or in Git.
 *
 * After a rebuild, set VaseyLicenseProfile::MANIFEST_SHA256 to the printed hash.
 */

use Composer\InstalledVersions;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';

$root = dirname(__DIR__);
$check = in_array('--check', array_slice($argv, 1), true);
$target = $root.'/resources/contracts/vasey-license-v1';
$logoSha256 = '7b0ffd26d5ddffab9695fdd3e0a4bb306d50642dc6a51e25233fd1626ff07571';
$faces = ['bebas-neue-latin-400-normal.woff2', 'reddit-sans-latin-wght-normal.woff2', 'jetbrains-mono-latin-wght-normal.woff2'];

$fail = static function (string $message): never {
    fwrite(STDERR, $message."\n");
    exit(1);
};

$expected = [
    'tecnickcom/tc-lib-pdf' => ['8.76.3', 'd417129fad37d49dc9fc0d1e229e9740e1c774a3'],
    'tecnickcom/tc-lib-pdf-font' => ['4.4.1', 'a78b8e0ac9284d1594b6ba68488cf65c3e958f7b'],
];
foreach ($expected as $name => [$version, $reference]) {
    if (ltrim((string) InstalledVersions::getPrettyVersion($name), 'v') !== $version || InstalledVersions::getReference($name) !== $reference) {
        $fail('Pinned renderer dependency identity mismatch: '.$name);
    }
}

$work = sys_get_temp_dir().'/vasey-license-v1-'.bin2hex(random_bytes(8));
foreach (['fonts/source', 'fonts/ttf', 'fonts/generated', 'images'] as $directory) {
    if (! mkdir($work.'/'.$directory, 0700, true)) {
        $fail('Could not prepare '.$directory);
    }
}

// Sources: byte copies of the reviewed brand files, each checked against the brand source manifest.
$brand = json_decode((string) file_get_contents($root.'/public/brand/fonts/source-manifest.json'), true, 16, JSON_THROW_ON_ERROR);
$sources = [];
foreach ($brand['files'] as $file) {
    if (! in_array(basename($file['font']), $faces, true)) {
        continue;
    }
    foreach ([[$file['font'], $file['font_sha256']], [$file['license'], $file['license_sha256']]] as [$path, $sha256]) {
        $bytes = file_get_contents($root.'/'.$path);
        if (! is_string($bytes) || ! hash_equals($sha256, hash('sha256', $bytes))) {
            $fail('Brand source identity mismatch: '.$path);
        }
        file_put_contents($work.'/fonts/source/'.basename($path), $bytes);
    }
    $sources[basename($file['font'])] = ['package' => $file['package'], 'version' => $file['version'], 'archive_sha256' => $file['archive_sha256']];
}
if (count($sources) !== 3) {
    $fail('Expected the three brand faces.');
}
ksort($sources);
$logo = file_get_contents($root.'/public/brand/vasey-audio-logo.png');
if (! is_string($logo) || ! hash_equals($logoSha256, hash('sha256', $logo))) {
    $fail('The VASEY.AUDIO lockup is not the reviewed file.');
}
file_put_contents($work.'/images/vasey-audio-logo.png', $logo);

// woff2 -> static TrueType, then tc-lib metrics and programs.
(new Process(['python3', $root.'/scripts/vasey-license-fonts.py', $work.'/fonts/source', $work.'/fonts/ttf'], $root, null, null, 120))->mustRun();
$ttf = ['bebasneue', 'redditsans', 'redditsansb', 'jetbrainsmono'];
$convert = new Process([PHP_BINARY, $root.'/vendor/tecnickcom/tc-lib-pdf-font/util/convert.php', '--outpath='.$work.'/fonts/generated',
    '--type=TrueTypeUnicode', '--flags=32', '--platform_id=3', '--encoding_id=1',
    '--fonts='.implode(',', array_map(static fn (string $name): string => $work.'/fonts/ttf/'.$name.'.ttf', $ttf))], $root, null, null, 120);
$convert->mustRun();
if (! str_contains($convert->getOutput(), '4 CONVERTED FONT(S), 0 ERROR(S)')) {
    $fail('tc-lib font conversion did not convert all four faces.');
}

$assets = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ($file->isLink() || ! $file->isFile()) {
        $fail('Unexpected generated entry.');
    }
    $relative = substr($file->getPathname(), strlen($work) + 1);
    $assets[$relative] = ['sha256' => hash_file('sha256', $file->getPathname()), 'size_bytes' => $file->getSize()];
}
ksort($assets, SORT_STRING);
if (count($assets) !== 1 + 6 + 4 + 12) {
    $fail('Unexpected asset count '.count($assets));
}

$packages = [];
foreach (InstalledVersions::getInstalledPackages() as $name) {
    if (str_starts_with($name, 'tecnickcom/')) {
        $packages[$name] = ['version' => ltrim((string) InstalledVersions::getPrettyVersion($name), 'v'), 'reference' => InstalledVersions::getReference($name)];
    }
}
ksort($packages);
$implementation = [];
foreach ([
    'app/Domain/Commerce/ContractIdentity/ContractGlyphCoverage.php',
    'app/Domain/Commerce/ContractIdentity/ContractIdentityDeclarationV2.php',
    'app/Domain/Contracts/Production/ContractBindingException.php',
    'app/Domain/Contracts/Production/ContractTemplateCensus.php',
    'app/Domain/Contracts/Production/ContractVariableBinder.php',
    'app/Domain/Contracts/Production/VaseyLicenseDocument.php',
    'app/Domain/Contracts/Production/VaseyLicenseLayout.php',
    'app/Domain/Contracts/Production/VaseyLicensePdfRenderer.php',
    'app/Domain/Contracts/Production/VaseyLicenseSignature.php',
] as $path) {
    if (! is_file($root.'/'.$path) || is_link($root.'/'.$path)) {
        $fail('The pinned renderer implementation is missing: '.$path);
    }
    $implementation[$path] = hash_file('sha256', $root.'/'.$path);
}

$manifest = [
    'schema_version' => 1,
    'profile_version' => 'vasey-license-pdf-v1',
    'font_source' => ['manifest' => 'public/brand/fonts/source-manifest.json', 'faces' => $sources],
    'font_decoding' => ['tool' => 'scripts/vasey-license-fonts.py', 'fonttools' => '4.68.0', 'brotli' => '1.2.1',
        'instances' => ['redditsans.ttf' => ['wght' => 400], 'redditsansb.ttf' => ['wght' => 700], 'jetbrainsmono.ttf' => ['wght' => 400]],
        'static' => ['bebasneue.ttf']],
    'font_conversion' => ['type' => 'TrueTypeUnicode', 'flags' => 32, 'platform_id' => 3, 'encoding_id' => 1],
    'logo' => ['source' => 'public/brand/vasey-audio-logo.png', 'sha256' => $logoSha256, 'modified' => false],
    'signature' => ['in_manifest' => false, 'source' => 'private_storage', 'pin' => 'config:contracts.vasey_license_v1.signature_sha256'],
    'packages' => $packages,
    'implementation' => $implementation,
    'assets' => $assets,
];
file_put_contents($work.'/profile-assets.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

$differences = [];
$built = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work, FilesystemIterator::SKIP_DOTS));
foreach ($built as $file) {
    $relative = substr($file->getPathname(), strlen($work) + 1);
    $committed = $target.'/'.$relative;
    if (! is_file($committed) || hash_file('sha256', $committed) !== hash_file('sha256', $file->getPathname())) {
        $differences[] = $relative;
    }
    if (! $check) {
        if (! is_dir(dirname($committed))) {
            mkdir(dirname($committed), 0755, true);
        }
        copy($file->getPathname(), $committed);
    }
}
(new Process(['rm', '-rf', $work]))->run();
sort($differences);

$hash = hash_file('sha256', $target.'/profile-assets.json');
if ($check) {
    if ($differences !== []) {
        $fail("Rebuilt assets differ from the committed profile:\n  ".implode("\n  ", $differences));
    }
    fwrite(STDOUT, "Rebuilt assets are byte-identical to the committed profile.\n");
} else {
    fwrite(STDOUT, 'Wrote '.count($assets)." pinned assets and profile-assets.json.\n");
}
fwrite(STDOUT, "MANIFEST_SHA256 = '".$hash."'\n");
