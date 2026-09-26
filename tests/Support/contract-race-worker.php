<?php

use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Contracts\ContractRenderer;
use App\Domain\Contracts\Models\ContractRenderWork;
use App\Domain\Contracts\RenderTestContract;
use App\Domain\Contracts\RequestTestContract;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ContractFixtures;
use Tests\Support\PaymentFixtures;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php'; $app->make(Kernel::class)->bootstrap();
    if (! $app->environment('testing') || DB::getDriverName() !== 'mysql') { throw new LogicException('Contract races require test MySQL.'); }
    $directory = getenv('VASEY_CONTRACT_RACE_DIRECTORY'); $worker = getenv('VASEY_CONTRACT_RACE_WORKER');
    $mediaRoot = getenv('VASEY_CONTRACT_RACE_MEDIA_ROOT');
    if (! is_string($directory) || ! is_dir($directory) || ! in_array($worker, ['0', '1'], true) || ! is_string($mediaRoot) || ! is_dir($mediaRoot)) {
        throw new LogicException('Missing contract race configuration.');
    }
    $input = json_decode(stream_get_contents(STDIN, 32768), true, 32, JSON_THROW_ON_ERROR);
    config(['filesystems.disks.local.root' => $mediaRoot, 'filesystems.disks.local.serve' => false, 'filesystems.disks.local.visibility' => 'private']);
    Storage::forgetDisk('local'); ContractFixtures::configure();
    \Illuminate\Support\Carbon::setTestNow($input['now']); \Carbon\CarbonImmutable::setTestNow($input['now']);
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $connectionId = DB::selectOne('SELECT CONNECTION_ID() AS connection_id')->connection_id;
    $waitFor = function (string $path): void {
        $deadline = microtime(true) + 35;
        do { clearstatcache(true, $path); if (is_file($path)) { return; } usleep(10000); }
        while (microtime(true) < $deadline);
        throw new RuntimeException('Contract worker barrier timed out.');
    };
    $gateway = PaymentFixtures::gateway(); $renderer = ContractFixtures::renderer();
    $app->instance(StripeCheckoutGateway::class, $gateway); $app->instance(StripePaymentGateway::class, $gateway);
    $app->instance(ContractRenderer::class, $renderer);
    $renderer->onRender = function (array $renderInput, array $profile) use ($directory, $worker, $waitFor, $input) {
        if (DB::transactionLevel() !== 0 || ContractRenderWork::where('contract_render_request_id', $input['request_id'])->where('state', 'processing')->count() !== 1) {
            throw new LogicException('Rendering requires a committed claim and no open transaction.');
        }
        touch($directory.'/render-'.$worker); $waitFor($directory.'/release-'.$worker);
        if ($input['fail'] ?? false) { throw new ContractIssuanceException('render_failed'); }

        return ContractFixtures::syntheticResult($renderInput, $profile);
    };
    $requestBarrier = false;
    if ($input['scenario'] === 'duplicate_request') {
        DB::connection()->beforeExecuting(function (string $sql) use ($directory, $worker, $waitFor, &$requestBarrier): void {
            if (! $requestBarrier && preg_match('/\Aselect\b/i', $sql) && str_contains($sql, 'from `orders`') && str_contains($sql, 'for update')) {
                $requestBarrier = true; touch($directory.'/request-'.$worker); $waitFor($directory.'/release-'.$worker);
            }
        });
    }
    file_put_contents($directory.'/ready-'.$worker, (string) $connectionId); $waitFor($directory.'/start-'.$worker);
    if ($input['scenario'] === 'duplicate_request') {
        $request = app(RequestTestContract::class)->handle($input['grant_id']);
        if (! $requestBarrier) { throw new LogicException('Request creation missed its order mutex.'); }
        $outcome = 'requested'; $requestId = $request->id;
    } else { $outcome = app(RenderTestContract::class)->handle($input['request_id']); $requestId = $input['request_id']; }
    echo json_encode(['outcome' => $outcome, 'request_id' => $requestId, 'pid' => getmypid(), 'transaction_level' => DB::transactionLevel(),
        'provider_calls' => $gateway->calls, 'render_transaction_levels' => array_column($renderer->calls, 'transaction_level')], JSON_THROW_ON_ERROR); exit(0);
} catch (Throwable $error) {
    echo json_encode(['outcome' => 'worker_failed', 'exception' => $error::class], JSON_THROW_ON_ERROR); exit(1);
}
