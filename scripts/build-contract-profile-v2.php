<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';

$root = dirname(__DIR__);
$base = $root.'/resources/contracts/test-v2';
$source = [
    'fonts/source/DejaVuSans.ttf' => ['sha256' => '7da195a74c55bef988d0d48f9508bd5d849425c1770dba5d7bfc6ce9ed848954', 'git_blob' => 'e5f7eecce43be41ff0703ed99e1553029b849f14'],
    'fonts/source/DejaVuSans-Bold.ttf' => ['sha256' => 'e6476c1b80502924294eed40894c5b18e06c181444ca953e5334262df9c27724', 'git_blob' => '6d65fa7dc41ae8ffae77a4a843a73ba31ffd78c7'],
    'fonts/source/LICENSE' => ['sha256' => '7a083b136e64d064794c3419751e5c7dd10d2f64c108fe5ba161eae5e5958a93', 'git_blob' => 'df52c1709bea171104d41bf084313ea434858423'],
];
foreach ($source as $path => &$identity) {
    $bytes = file_get_contents($base.'/'.$path);
    if ($bytes === false || ! hash_equals($identity['sha256'], hash('sha256', $bytes))
        || ! hash_equals($identity['git_blob'], sha1('blob '.strlen($bytes)."\0".$bytes))) {
        throw new RuntimeException('Pinned font source identity mismatch.');
    }
    $identity['size_bytes'] = strlen($bytes);
}
unset($identity);

$expected = [
    'tecnickcom/tc-lib-pdf' => ['8.76.3', 'd417129fad37d49dc9fc0d1e229e9740e1c774a3'],
    'tecnickcom/tc-lib-pdf-font' => ['4.4.1', 'a78b8e0ac9284d1594b6ba68488cf65c3e958f7b'],
];
foreach ($expected as $name => [$version, $ref]) {
    if (ltrim((string) InstalledVersions::getPrettyVersion($name), 'v') !== $version
        || InstalledVersions::getReference($name) !== $ref) {
        throw new RuntimeException('Pinned renderer dependency identity mismatch.');
    }
}

$generated = $base.'/fonts/generated';
if (! is_dir($generated) && ! mkdir($generated, 0755, true)) {
    throw new RuntimeException('Could not prepare generated fonts.');
}
foreach (glob($generated.'/*') ?: [] as $file) {
    if (! is_file($file) || is_link($file)) {
        throw new RuntimeException('Unexpected generated font entry.');
    }
    unlink($file);
}
$process = new Process([
    PHP_BINARY, $root.'/vendor/tecnickcom/tc-lib-pdf-font/util/convert.php',
    '--outpath='.$generated, '--type=TrueTypeUnicode', '--flags=32', '--encoding_id=10',
    '--fonts='.$base.'/fonts/source/DejaVuSans.ttf,'.$base.'/fonts/source/DejaVuSans-Bold.ttf',
], $root, null, null, 120);
$process->mustRun();
fwrite(STDOUT, $process->getOutput());

$assets = $source;
foreach (glob($generated.'/*') ?: [] as $file) {
    $name = basename($file);
    if (! preg_match('/\Adejavusansb?\.(json|z|ctg\.z)\z/D', $name) || is_link($file)) {
        throw new RuntimeException('Unexpected generated font filename.');
    }
    $assets['fonts/generated/'.$name] = ['sha256' => hash_file('sha256', $file), 'size_bytes' => filesize($file)];
}
if (count($assets) !== 9) {
    throw new RuntimeException('Expected the complete six-file Unicode font profile.');
}
ksort($assets);
$packages = [];
foreach (InstalledVersions::getInstalledPackages() as $name) {
    if (str_starts_with($name, 'tecnickcom/')) {
        $packages[$name] = ['version' => ltrim((string) InstalledVersions::getPrettyVersion($name), 'v'), 'reference' => InstalledVersions::getReference($name)];
    }
}
ksort($packages);
$implementation = [];
foreach (['app/Domain/Contracts/ContractText.php', 'app/Domain/Contracts/TcpdfV2ContractRenderer.php'] as $path) {
    if (! is_file($root.'/'.$path) || is_link($root.'/'.$path)) {
        throw new RuntimeException('The pinned renderer implementation is missing.');
    }
    $implementation[$path] = hash_file('sha256', $root.'/'.$path);
}
$manifest = [
    'schema_version' => 1,
    'profile_version' => 'test-buyer-pdf-v2',
    'font_source' => ['repository' => 'tecnickcom/tc-font-mirror', 'release' => '2.4.0', 'reference' => '3251310e5f8e92659ac3ef1133e591ed681ef95d'],
    'font_conversion' => ['type' => 'TrueTypeUnicode', 'flags' => 32, 'platform_id' => 3, 'encoding_id' => 10],
    'packages' => $packages,
    'implementation' => $implementation,
    'assets' => $assets,
];
file_put_contents($base.'/profile-assets.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
fwrite(STDOUT, "Pinned font and package manifest complete.\n");
