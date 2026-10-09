<?php

// M-16 evidence harness (not product code). Served by a private php-fpm pool; renders one family's captured
// {input, profile} payload through the application container, exactly as a web request would, and reports the result.
use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Contracts\ContractRenderer;
use App\Domain\Contracts\ContractRenderProfile;
use Symfony\Component\Process\Process;

$root = (string) getenv('M16_ROOT');
$capture = (string) getenv('M16_CAPTURE_DIR');
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
$family = PHP_SAPI === 'cli' ? (string) ($argv[1] ?? '') : (string) ($_GET['family'] ?? '');
$out = ['family' => $family, 'sapi' => PHP_SAPI, 'php_binary' => PHP_BINARY, 'php_version' => PHP_VERSION,
    'configured_cli' => config('app.php_cli_binary'), 'app_env' => app()->environment()];
try {
    if ($family === 'raw') {
        // The original M-16 failure in isolation: PHP_BINARY with renderer-style CLI arguments.
        $process = new Process([PHP_BINARY, '-n', '-r', 'echo PHP_SAPI;'], null, null, null, 10);
        $process->run();
        $out += ['exit' => $process->getExitCode(), 'stdout_head' => substr($process->getOutput(), 0, 60),
            'stderr_head' => substr($process->getErrorOutput(), 0, 60)];
    } else {
        $classes = ['free' => 'App\Domain\Grants\Free\FreeGrantRendererProcess',
            'production-free' => 'App\Domain\Grants\ProductionFree\ProductionFreeGrantRendererProcess',
            'paid' => 'App\Domain\Grants\Paid\PaidGrantRendererProcess', 'generic' => ContractRenderer::class];
        if ($family === 'generic') {
            $input = Tests\Support\ContractRendererFixtures::input();
            $profile = ContractRenderProfile::current();
        } else {
            $payload = json_decode((string) file_get_contents($capture.'/'.$family.'.payload.json'), true, 64, JSON_THROW_ON_ERROR);
            ['input' => $input, 'profile' => $profile] = $payload;
        }
        $rendered = app($classes[$family])->render($input, $profile);
        $out += ['ok' => true, 'sha256' => $rendered->sha256, 'size_bytes' => $rendered->sizeBytes ?? null];
    }
} catch (ContractIssuanceException $error) {
    $out += ['ok' => false, 'reason' => $error->reason];
} catch (Throwable $error) {
    $out += ['ok' => false, 'error' => get_class($error)];
}
header('Content-Type: application/json');
echo json_encode($out, JSON_UNESCAPED_SLASHES), "\n";
