<?php

// Independent-review harness (not product code). Served by a private php-fpm8.4 pool (or run with the CLI for the
// baseline): boots the application's HTTP kernel at REVIEW_ROOT and renders one family's captured {input, profile}
// payload through the application container, exactly as a web request would.
//
// family=raw            PHP_BINARY with renderer-style arguments (the original failure in isolation)
// family=free|production-free|paid|generic   render through app(<class>)->render()
// &record=1             additionally wrap the container-supplied process factory to record the argument vector and
//                       environment that the production factory actually built (workspace path normalised)
use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Contracts\ContractRenderer;
use App\Domain\Contracts\ContractRenderProfile;
use Symfony\Component\Process\Process;

$root = (string) getenv('REVIEW_ROOT');
$capture = (string) getenv('REVIEW_CAPTURE_DIR');
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
$query = PHP_SAPI === 'cli' ? ['family' => $argv[1] ?? '', 'record' => $argv[2] ?? ''] : $_GET;
$family = (string) ($query['family'] ?? '');
$out = ['family' => $family, 'sapi' => PHP_SAPI, 'php_binary' => PHP_BINARY, 'php_version' => PHP_VERSION,
    'configured_cli' => config('app.php_cli_binary'), 'root' => base_path()];
try {
    if ($family === 'resolve') {
        // The resolver's own verdict in this SAPI: the fixed reason code and the exception message (privacy check).
        try {
            $out += ['resolved' => app(App\Support\PhpCliBinary::class)->path()];
        } catch (App\Support\PhpCliBinaryUnavailable $error) {
            $out += ['reason' => $error->reason, 'message' => $error->getMessage(), 'previous' => $error->getPrevious() === null ? null : 'set'];
        }
    } elseif ($family === 'raw') {
        $process = new Process([PHP_BINARY, '-n', '-r', 'echo PHP_SAPI;'], null, null, null, 10);
        $process->run();
        $out += ['exit' => $process->getExitCode(), 'stdout_head' => substr($process->getOutput(), 0, 60)];
    } else {
        $classes = ['free' => 'App\Domain\Grants\Free\FreeGrantRendererProcess',
            'production-free' => 'App\Domain\Grants\ProductionFree\ProductionFreeGrantRendererProcess',
            'paid' => 'App\Domain\Grants\Paid\PaidGrantRendererProcess', 'generic' => ContractRenderer::class];
        if ($family === 'production-free-composed') {
            // 214256a ships family 256 unbound; this is the binding its future composition step is told to add.
            app()->bind($classes['production-free'], fn ($app) => new App\Domain\Grants\ProductionFree\ProductionFreeGrantRendererProcess(
                App\Support\PhpCliProcess::factory($app->make(App\Support\PhpCliBinary::class))));
            $family = 'production-free';
            $out['composed'] = true;
        }
        if ($family === 'generic') {
            $input = Tests\Support\ContractRendererFixtures::input();
            $profile = ContractRenderProfile::current();
        } else {
            $payload = json_decode((string) file_get_contents($capture.'/'.$family.'.payload.json'), true, 64, JSON_THROW_ON_ERROR);
            ['input' => $input, 'profile' => $profile] = $payload;
        }
        $renderer = app($classes[$family]);
        $out['renderer_class'] = get_class($renderer);
        if (($query['record'] ?? '') === '1' && $family !== 'generic') {
            $inner = (new ReflectionProperty($renderer, 'processFactory'))->getValue($renderer);
            $out['factory'] = $inner === null ? 'none' : 'closure';
            $record = function (array $command, string $cwd, array $environment, string $stdin) use ($inner, &$out): Process {
                $process = $inner === null ? new Process($command, $cwd, $environment, $stdin, 60) : $inner($command, $cwd, $environment, $stdin);
                $workspace = (string) $environment['TMPDIR'];
                $env = (new ReflectionProperty($process, 'env'))->getValue($process);
                $out['spawn'] = [
                    'argv' => array_map(fn ($a) => str_replace($workspace, '{workspace}', $a), (new ReflectionProperty($process, 'commandline'))->getValue($process)),
                    'cwd' => $process->getWorkingDirectory(),
                    'timeout' => $process->getTimeout(),
                    'stdin_sha256' => hash('sha256', (string) $process->getInput()),
                    'renderer_built_argv0' => $command[0],
                    'env_set' => array_map(fn ($v) => str_replace($workspace, '{workspace}', (string) $v), array_filter($env, fn ($v) => $v !== false)),
                ];

                return $process;
            };
            $renderer = new ($classes[$family])($record);
        }
        $rendered = $renderer->render($input, $profile);
        $out += ['ok' => true, 'sha256' => $rendered->sha256];
    }
} catch (ContractIssuanceException $error) {
    $out += ['ok' => false, 'reason' => $error->reason];
} catch (Throwable $error) {
    $out += ['ok' => false, 'error' => get_class($error)];
}
header('Content-Type: application/json');
echo json_encode($out, JSON_UNESCAPED_SLASHES), "\n";
