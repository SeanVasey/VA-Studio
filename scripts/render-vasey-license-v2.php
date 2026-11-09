<?php

use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Contracts\Production\VaseyLicenseSignature;
use App\Domain\Contracts\Production\VaseyLicenseV2Profile;
use App\Domain\Contracts\Production\VaseyLicenseV2Render;
use Composer\Autoload\ClassLoader;

// Protocol-only child of VaseyLicenseV2RendererProcess: deliberately no Laravel application, .env loading, configuration,
// database or provider client, and none of Composer's application-wide autoload files. Only the tc-lib packages and
// Composer's InstalledVersions load through Composer. An application class loads only when the v2 manifest pins its file,
// and only after the file's bytes match that pin; the manifest itself must match the hash its anchor class holds.
$root = dirname(__DIR__);
require $root.'/vendor/composer/ClassLoader.php';
$loader = new ClassLoader($root.'/vendor');
foreach ((require $root.'/vendor/composer/autoload_psr4.php') as $prefix => $paths) {
    if (str_starts_with($prefix, 'Com\\Tecnick\\')) {
        $loader->setPsr4($prefix, $paths);
    }
}
$loader->addClassMap(['Composer\\InstalledVersions' => $root.'/vendor/composer/InstalledVersions.php']);
$loader->register();

$anchor = 'app/Domain/Contracts/Production/VaseyLicenseV2Profile.php';
$pinned = null;
// Set when the manifest or a pinned file does not match. The autoloader cannot throw the typed refusal (that class loads
// through it), so the result reports `profile_changed` whatever a caller made of the failed load.
$refused = false;
spl_autoload_register(static function (string $class) use ($root, $anchor, &$pinned, &$refused): void {
    if (! str_starts_with($class, 'App\\') || preg_match('/\A[A-Za-z0-9_\\\\]+\z/D', $class) !== 1) {
        return;
    }
    $path = 'app/'.str_replace('\\', '/', substr($class, 4)).'.php';
    if ($path === $anchor) {
        require $root.'/'.$anchor;

        return;
    }
    if ($pinned === null) {
        // The path is spelled out: the anchor's own path constants come from a class that loads through this pin.
        $manifest = $root.'/resources/contracts/vasey-license-v2/profile-assets.json';
        $bytes = is_file($manifest) && ! is_link($manifest) ? file_get_contents($manifest, false, null, 0, 65537) : false;
        $implementation = is_string($bytes) && strlen($bytes) <= 65536 && hash_equals(VaseyLicenseV2Profile::MANIFEST_SHA256, hash('sha256', $bytes))
            ? (json_decode($bytes, true, 32)['implementation'] ?? null) : null;
        if (! is_array($implementation)) {
            $refused = true;
            throw new UnexpectedValueException;
        }
        $pinned = $implementation;
    }
    $sha256 = $pinned[$path] ?? null;
    $file = $root.'/'.$path;
    if (! is_string($sha256) || ! is_file($file) || is_link($file) || ! hash_equals($sha256, (string) hash_file('sha256', $file))) {
        $refused = true;
        throw new UnexpectedValueException;
    }
    require $file;
});

try {
    $raw = stream_get_contents(STDIN, 1048577);
    if (! is_string($raw) || strlen($raw) > 1048576) {
        throw new ContractIssuanceException('unsupported_input');
    }
    $payload = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
    if (! is_array($payload) || array_keys($payload) !== ['input', 'profile', 'renderer_profile', 'template', 'values']
        || ! is_array($payload['input']) || ! is_array($payload['profile']) || ! is_string($payload['renderer_profile'])
        || ! is_string($payload['template']) || ! is_array($payload['values']) || ! array_is_list($payload['values'])) {
        throw new ContractIssuanceException('unsupported_input');
    }
    // The bound values arrive as ordered [name, value] pairs (canonical JSON would sort an object's keys).
    $values = [];
    foreach ($payload['values'] as $pair) {
        if (! is_array($pair) || ! array_is_list($pair) || count($pair) !== 2 || ! is_string($pair[0]) || ! is_string($pair[1])
            || array_key_exists($pair[0], $values)) {
            throw new ContractIssuanceException('unsupported_input');
        }
        $values[$pair[0]] = $pair[1];
    }
    // The descriptor first, then the signature the parent placed in this render's private workspace (the child's only
    // temporary directory), checked against the descriptor's pin when it is read.
    VaseyLicenseV2Render::shape($payload['profile']);
    $signature = Closure::bind(static fn (string $path, string $sha256): VaseyLicenseSignature => new VaseyLicenseSignature($path, $sha256),
        null, VaseyLicenseSignature::class)(sys_get_temp_dir().'/signature.png', $payload['profile']['signature_sha256']);
    // The template and renderer profile are checked against the descriptor's hashes inside render().
    $rendered = VaseyLicenseV2Render::render($payload['input'], $payload['profile'], $values, $signature, $payload['template'],
        $payload['renderer_profile'], $root);
    $limits = VaseyLicenseV2Render::rendererProfile($payload['profile'], $payload['renderer_profile'])['limits'];
    if ($rendered->sizeBytes > 16777216 || $rendered->sizeBytes > $limits['output_bytes'] || $rendered->pageCount > $limits['pages']) {
        throw new ContractIssuanceException('invalid_pdf');
    }
    $encoded = json_encode(['pdf_base64' => base64_encode($rendered->pdfBytes), 'sha256' => $rendered->sha256,
        'size_bytes' => $rendered->sizeBytes, 'page_count' => $rendered->pageCount,
        'text_digest' => $rendered->textDigest, 'profile_hash' => $rendered->profileHash], JSON_THROW_ON_ERROR);
    if (strlen($encoded) > 24117248) {
        throw new ContractIssuanceException('invalid_pdf');
    }
    $offset = 0;
    while ($offset < strlen($encoded)) {
        $written = fwrite(STDOUT, substr($encoded, $offset, 1048576));
        if (! is_int($written) || $written < 1) {
            throw new RuntimeException;
        }
        $offset += $written;
    }
    exit(0);
} catch (ContractIssuanceException $error) {
    $reason = $refused ? 'profile_changed' : (in_array($error->reason, ['profile_changed', 'unsupported_input', 'invalid_pdf', 'render_failed', 'signature_changed',
        'signature_unavailable', 'signature_invalid'], true) ? $error->reason : 'render_failed');
    fwrite(STDOUT, json_encode(['error' => $reason], JSON_THROW_ON_ERROR));
    exit(0);
} catch (Throwable) {
    if ($refused) {
        fwrite(STDOUT, '{"error":"profile_changed"}');
        exit(0);
    }
    // No input, path, provider data or error text may cross the failure boundary.
    exit(1);
}
