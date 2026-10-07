<?php
require dirname(__DIR__, 3).'/vendor/autoload.php';
use App\Domain\Contracts\ContractRenderProfile as Profile;
use App\Domain\Contracts\ContractRenderProfileRegistry as Registry;
use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Contracts\ContractIssuancePolicy as Policy;
$count = 0;
function same($left, $right): void { global $count; if ($left !== $right) { throw new RuntimeException('Canary equality failed'); } $count++; }
function refuses(callable $fn): void { global $count; try { $fn(); } catch (ContractIssuanceException $e) { same('profile_changed', $e->reason); $count++; return; } throw new RuntimeException('Canary refusal failed'); }
$root = dirname(__DIR__, 3);
$fixture = json_decode(file_get_contents($root.'/tests/fixtures/contracts/retained-render-profile-v1.json'), true, 32, JSON_THROW_ON_ERROR);
$profile = $fixture['profile'];
same($root.'/app/Domain/Contracts/ContractRenderProfileRegistry.php', (new ReflectionClass(Registry::class))->getFileName());
$temporary = sys_get_temp_dir().'/registry-independent-'.bin2hex(random_bytes(10));
mkdir($temporary.'/resources/contracts/test-v1', 0700, true);
$manifest = $temporary.'/resources/contracts/test-v1/profile-assets.json';
try {
    file_put_contents($manifest, json_encode($profile['assets'], JSON_THROW_ON_ERROR));
    same($profile, Registry::metadata('test-buyer-pdf-v1', $temporary));
    // Retained metadata is readable without renderer implementation/font files.
    refuses(fn () => Profile::verifyRuntime($profile, $temporary));
    same($profile, Profile::validate($profile));
    unlink($manifest);
    symlink($root.'/resources/contracts/test-v1/profile-assets.json', $manifest);
    refuses(fn () => Registry::metadata('test-buyer-pdf-v1', $temporary));
    unlink($manifest);
    file_put_contents($manifest, str_repeat(' ', 65537));
    refuses(fn () => Registry::metadata('test-buyer-pdf-v1', $temporary));
    file_put_contents($manifest, '{"schema_version":1');
    refuses(fn () => Registry::metadata('test-buyer-pdf-v1', $temporary));
    $assets = $profile['assets']; $assets['profile_version'] = 'test-buyer-pdf-v2';
    file_put_contents($manifest, json_encode($assets, JSON_THROW_ON_ERROR));
    refuses(fn () => Registry::metadata('test-buyer-pdf-v1', $temporary));
    foreach (['test-buyer-pdf-v2', '../test-v1', 'test-buyer-pdf-v1'.chr(0), 'TEST-BUYER-PDF-V1'] as $version) {
        refuses(fn () => Registry::metadata($version, $temporary));
    }
    $changed = $profile; $changed['remote_resources'] = true;
    refuses(fn () => Profile::validate($changed));
    $policy = Policy::V1_CONTRACT; $policy['profile'] = 'test-buyer-pdf-v2';
    refuses(fn () => Policy::validate($policy));
} finally {
    if (file_exists($manifest) || is_link($manifest)) { unlink($manifest); }
    rmdir(dirname($manifest)); rmdir($temporary.'/resources/contracts'); rmdir($temporary.'/resources'); rmdir($temporary);
}
echo json_encode(['assertions' => $count, 'result' => 'passed'], JSON_PRETTY_PRINT), "\n";
