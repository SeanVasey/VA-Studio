<?php

declare(strict_types=1);

/*
 * Build or check the pinned assets of the `vasey-license-pdf-v2` contract profile (successor of v1, which is unchanged).
 *
 *   php scripts/build-contract-profile-vasey-license-v2.php                   rebuild resources/contracts/vasey-license-v2
 *   php scripts/build-contract-profile-vasey-license-v2.php --check           rebuild into a temporary directory and compare
 *   php scripts/build-contract-profile-vasey-license-v2.php --archives=DIR    also prove every font source and license is
 *                                                                             the member of its pinned npm tarball in DIR
 *
 * Inputs: the Latin brand faces and their OFL texts under public/brand/fonts (checked against
 * public/brand/fonts/source-manifest.json), the three Latin Extended faces vendored in this profile's fonts/source
 * (checked against the SHA-256 pinned below; they come from the same Fontsource 5.3.0 tarballs as the brand faces) and the
 * unmodified lockup public/brand/vasey-audio-logo.png. Each Latin and Latin Extended pair is merged into one static
 * TrueType face by scripts/vasey-license-v2-fonts.py (pinned fontTools and brotli), then converted by tc-lib-pdf-font's
 * own util/convert.php. No signature is read or written.
 *
 * After a rebuild, set VaseyLicenseV2Profile::MANIFEST_SHA256 to the printed hash.
 */

use App\Domain\Contracts\Production\VaseyLicenseV2Implementation;
use Composer\InstalledVersions;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';

$root = dirname(__DIR__);
$arguments = array_slice($argv, 1);
$check = in_array('--check', $arguments, true);
$archives = null;
foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--archives=')) {
        $archives = substr($argument, strlen('--archives='));
    } elseif ($argument !== '--check') {
        fwrite(STDERR, 'Unknown argument: '.$argument."\n");
        exit(2);
    }
}
$target = $root.'/resources/contracts/vasey-license-v2';
$logoSha256 = '7b0ffd26d5ddffab9695fdd3e0a4bb306d50642dc6a51e25233fd1626ff07571';
$latin = ['bebas-neue-latin-400-normal.woff2', 'reddit-sans-latin-wght-normal.woff2', 'jetbrains-mono-latin-wght-normal.woff2'];

// The Latin Extended faces: Fontsource 5.3.0, the same tarballs (by SHA-256) the brand manifest pins for the Latin faces.
$extended = [
    'bebas-neue-latin-ext-400-normal.woff2' => ['package' => '@fontsource/bebas-neue', 'version' => '5.3.0',
        'archive_sha256' => '57928e0ee30b9e8a99033cdd5dc74ad06761007b4ad8fc32173e5d9072ba91fa',
        'sha256' => '16c95ce45a2922f52551d38d565d14c92cf257b8f219c89613407d81fdd21a39', 'size_bytes' => 8928],
    'jetbrains-mono-latin-ext-wght-normal.woff2' => ['package' => '@fontsource-variable/jetbrains-mono', 'version' => '5.3.0',
        'archive_sha256' => '996fe6368a480c9ce15d4de22a2682b7c40b403718fee2b15e7272f244fd993f',
        'sha256' => '79bfdab9ba467e26eea4122e6f2567e188dd8a09a8c730d501fc487c4ab99c6e', 'size_bytes' => 15196],
    'reddit-sans-latin-ext-wght-normal.woff2' => ['package' => '@fontsource-variable/reddit-sans', 'version' => '5.3.0',
        'archive_sha256' => '9ca6f15148cb50f8faa9b0f2fff1effc7a0bc752155e203b18e9ab30a82662a9',
        'sha256' => '1f4049cb63a3088a505d98719f509f3fadcf5dde4424c9d675b1826ec28d262b', 'size_bytes' => 34092],
];

$fail = static function (string $message): never {
    fwrite(STDERR, $message."\n");
    exit(1);
};

$expected = [
    'tecnickcom/tc-lib-pdf' => ['8.81.1', 'a0626686715391dd4210c5622aee849d033b8baf'],
    'tecnickcom/tc-lib-pdf-font' => ['4.6.2', '668d73e853c6027afd9cdaae8ea042957ba11e08'],
];
foreach ($expected as $name => [$version, $reference]) {
    if (ltrim((string) InstalledVersions::getPrettyVersion($name), 'v') !== $version || InstalledVersions::getReference($name) !== $reference) {
        $fail('Pinned renderer dependency identity mismatch: '.$name);
    }
}

$work = sys_get_temp_dir().'/vasey-license-v2-'.bin2hex(random_bytes(8));
foreach (['fonts/source', 'fonts/ttf', 'fonts/generated', 'images'] as $directory) {
    if (! mkdir($work.'/'.$directory, 0700, true)) {
        $fail('Could not prepare '.$directory);
    }
}

// Latin sources and licenses: byte copies of the reviewed brand files, each checked against the brand source manifest.
$brand = json_decode((string) file_get_contents($root.'/public/brand/fonts/source-manifest.json'), true, 16, JSON_THROW_ON_ERROR);
$sources = [];
$tarballs = [];
foreach ($brand['files'] as $file) {
    if (! in_array(basename($file['font']), $latin, true)) {
        continue;
    }
    foreach ([[$file['font'], $file['font_sha256']], [$file['license'], $file['license_sha256']]] as [$path, $sha256]) {
        $bytes = file_get_contents($root.'/'.$path);
        if (! is_string($bytes) || ! hash_equals($sha256, hash('sha256', $bytes))) {
            $fail('Brand source identity mismatch: '.$path);
        }
        file_put_contents($work.'/fonts/source/'.basename($path), $bytes);
    }
    $sources[basename($file['font'])] = ['package' => $file['package'], 'version' => $file['version'], 'subset' => 'latin',
        'archive_sha256' => $file['archive_sha256'], 'archive_member' => 'package/files/'.basename($file['font']), 'origin' => $file['font']];
    $tarballs[$file['package']] = ['url' => $file['package_url'], 'integrity' => $file['package_integrity'], 'archive_sha256' => $file['archive_sha256'],
        'license' => basename($file['license'])];
}
if (count($sources) !== 3) {
    $fail('Expected the three Latin brand faces.');
}

// Latin Extended sources: the committed copies, checked against the pins above and against the brand tarball identity.
foreach ($extended as $name => $pin) {
    $tarball = $tarballs[$pin['package']] ?? null;
    if ($tarball === null || ! hash_equals($tarball['archive_sha256'], $pin['archive_sha256'])) {
        $fail('A Latin Extended face does not come from a pinned brand tarball: '.$name);
    }
    $path = $target.'/fonts/source/'.$name;
    $bytes = is_file($path) && ! is_link($path) ? file_get_contents($path) : false;
    if (! is_string($bytes) || strlen($bytes) !== $pin['size_bytes'] || ! hash_equals($pin['sha256'], hash('sha256', $bytes))) {
        $fail('Latin Extended source identity mismatch: '.$name);
    }
    file_put_contents($work.'/fonts/source/'.$name, $bytes);
    $sources[$name] = ['package' => $pin['package'], 'version' => $pin['version'], 'subset' => 'latin-ext',
        'archive_sha256' => $pin['archive_sha256'], 'archive_member' => 'package/files/'.$name, 'origin' => 'vendored'];
}
ksort($sources);
ksort($tarballs);

// Optional: every vendored face and license must be the member of its tarball, read only after the tarball hash matches.
if ($archives !== null) {
    foreach ($tarballs as $package => $tarball) {
        $file = rtrim($archives, '/').'/'.basename($tarball['url']);
        if (! is_file($file) || is_link($file) || ! hash_equals($tarball['archive_sha256'], (string) hash_file('sha256', $file))
            || ! hash_equals($tarball['integrity'], 'sha512-'.base64_encode((string) hash_file('sha512', $file, true)))) {
            $fail('Tarball missing or not the pinned bytes: '.$file);
        }
        $members = ['package/LICENSE' => $tarball['license']];
        foreach ($sources as $name => $source) {
            if ($source['package'] === $package) {
                $members[$source['archive_member']] = $name;
            }
        }
        $archive = new PharData($file);
        foreach ($members as $member => $name) {
            if (! isset($archive[$member]) || ! hash_equals(hash('sha256', $archive[$member]->getContent()), (string) hash_file('sha256', $work.'/fonts/source/'.$name))) {
                $fail('Source is not the tarball member '.$package.':'.$member);
            }
        }
    }
    fwrite(STDOUT, 'Every font source and license is the member of its pinned tarball.'."\n");
}

$logo = file_get_contents($root.'/public/brand/vasey-audio-logo.png');
if (! is_string($logo) || ! hash_equals($logoSha256, hash('sha256', $logo))) {
    $fail('The VASEY.AUDIO lockup is not the reviewed file.');
}
file_put_contents($work.'/images/vasey-audio-logo.png', $logo);

// woff2 pairs -> merged static TrueType, then tc-lib metrics and programs.
(new Process(['python3', $root.'/scripts/vasey-license-v2-fonts.py', $work.'/fonts/source', $work.'/fonts/ttf'], $root, null, null, 300))->mustRun();
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
if (count($assets) !== 1 + 9 + 4 + 12) {
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
foreach (VaseyLicenseV2Implementation::closure($root) as $path) {
    if (! is_file($root.'/'.$path) || is_link($root.'/'.$path)) {
        $fail('The pinned implementation is missing: '.$path);
    }
    $implementation[$path] = hash_file('sha256', $root.'/'.$path);
}

$manifest = [
    'schema_version' => 1,
    'profile_version' => 'vasey-license-pdf-v2',
    'supersedes' => 'vasey-license-pdf-v1',
    'font_source' => ['manifest' => 'public/brand/fonts/source-manifest.json', 'tarballs' => $tarballs, 'faces' => $sources],
    'font_decoding' => ['tool' => 'scripts/vasey-license-v2-fonts.py', 'fonttools' => '4.68.0', 'brotli' => '1.2.1',
        'merge' => [
            'bebasneue.ttf' => ['bebas-neue-latin-400-normal.woff2', 'bebas-neue-latin-ext-400-normal.woff2'],
            'redditsans.ttf' => ['reddit-sans-latin-wght-normal.woff2', 'reddit-sans-latin-ext-wght-normal.woff2'],
            'redditsansb.ttf' => ['reddit-sans-latin-wght-normal.woff2', 'reddit-sans-latin-ext-wght-normal.woff2'],
            'jetbrainsmono.ttf' => ['jetbrains-mono-latin-wght-normal.woff2', 'jetbrains-mono-latin-ext-wght-normal.woff2'],
        ],
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
