<?php

// Temporary CI diagnostic. Only the committed synthetic fixture can enter this script.
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$profile = App\Domain\Contracts\ContractRenderProfile::current();
$input = Tests\Support\ContractRendererFixtures::input();
$input['disclosure']['termsText'] = str_repeat("Synthetic FULL TERMS paragraph. Zoë Ελληνικά Кириллица.\n", 150).'FINAL TERMS SENTINEL';
$renderer = new App\Domain\Contracts\IsolatedContractRenderer(function ($command, $root, $environment, $payload) {
    $probe = new Symfony\Component\Process\Process($command, $root, $environment, $payload, 60);
    $output = ''; $errors = '';
    $probe->run(function ($type, $chunk) use (&$output, &$errors, $probe) {
        if ($type === Symfony\Component\Process\Process::ERR) {
            $errors .= substr($chunk, 0, max(0, 8192 - strlen($errors)));
            $probe->clearErrorOutput();
        } else {
            $output .= substr($chunk, 0, max(0, 24117248 - strlen($output)));
            $probe->clearOutput();
        }
    });
    $result = json_decode($output, true);
    fwrite(STDOUT, json_encode(['synthetic_child_exit' => $probe->getExitCode(),
        'stdout_bytes' => strlen($output), 'stderr_bytes' => strlen($errors),
        'reply_keys' => is_array($result) ? array_keys($result) : null,
        'reply_error' => is_array($result) && isset($result['error']) ? $result['error'] : null,
        'synthetic_stderr' => $errors], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    return new Symfony\Component\Process\Process($command, $root, $environment, $payload, 60);
});
try {
    $rendered = $renderer->render($input, $profile);
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error).': '.$error->getMessage()."\n");
    exit(1);
}
fwrite(STDOUT, json_encode(['adapter_pages' => $rendered->pageCount, 'adapter_bytes' => $rendered->sizeBytes,
    'adapter_sha256' => $rendered->sha256], JSON_THROW_ON_ERROR)."\n");
