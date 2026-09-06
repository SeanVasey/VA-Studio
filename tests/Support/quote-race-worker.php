<?php

// Invoked only by QuoteConcurrencyTest with isolated, committed synthetic fixtures.
use App\Domain\Commerce\CreateQuote;
use App\Domain\Commerce\QuoteException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (! $app->environment('testing') || DB::connection()->getDriverName() !== 'mysql') {
        throw new LogicException('Quote race workers require a testing MySQL connection.');
    }
    $directory = getenv('VASEY_QUOTE_RACE_DIRECTORY');
    $worker = getenv('VASEY_QUOTE_RACE_WORKER');
    $phase = getenv('VASEY_QUOTE_RACE_PHASE');
    $mediaRoot = getenv('VASEY_QUOTE_RACE_MEDIA_ROOT');
    if (! is_string($directory) || ! is_dir($directory) || ! in_array($worker, ['0', '1'], true) || ! in_array($phase, ['owner', 'track'], true) || ! is_string($mediaRoot) || ! is_dir($mediaRoot)) {
        throw new LogicException('Missing isolated quote race configuration.');
    }
    config(['filesystems.disks.local.root' => $mediaRoot, 'filesystems.disks.local.serve' => false, 'filesystems.disks.local.visibility' => 'private']);
    Storage::forgetDisk('local');
    $input = json_decode(stream_get_contents(STDIN, 65536), true, 16, JSON_THROW_ON_ERROR);
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $connectionId = DB::selectOne('SELECT CONNECTION_ID() AS connection_id')->connection_id;
    $barrierPassed = false;
    DB::connection()->beforeExecuting(function (string $query) use ($directory, $worker, $phase, $connectionId, &$barrierPassed): void {
        $matchesPhase = $phase === 'owner'
            ? preg_match('/\Ainsert\b/i', $query) && str_contains($query, 'quote_owners')
            : preg_match('/\Aselect\b/i', $query) && str_contains($query, 'from `tracks`') && str_contains($query, 'for update');
        if ($barrierPassed || ! $matchesPhase) {
            return;
        }
        $barrierPassed = true; // Deadlock retries must not wait at the barrier twice.
        if (file_put_contents($directory.'/ready-'.$worker, (string) $connectionId) === false) {
            throw new RuntimeException('Cannot signal quote race barrier.');
        }
        $deadline = microtime(true) + 18;
        do {
            clearstatcache(true, $directory.'/release');
            if (is_file($directory.'/release')) {
                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Quote race barrier timed out.');
    });

    try {
        $quote = app(CreateQuote::class)->handle($input['owner'], $input['key'], $input['items']);
        $result = ['result' => 'created', 'quote_id' => $quote->public_id, 'snapshot_hash' => $quote->snapshot_hash];
    } catch (QuoteException $exception) {
        $result = ['result' => 'conflict', 'error_code' => $exception->errorCode, 'status' => $exception->status];
    }
    if (! $barrierPassed) {
        throw new LogicException('CreateQuote did not reach the expected transaction barrier.');
    }
    echo json_encode($result + ['pid' => getmypid()], JSON_THROW_ON_ERROR);
    exit(0);
} catch (Throwable $exception) {
    // PDO messages may contain credentials or SQL bindings. Output only the failure class.
    echo json_encode(['result' => 'worker_failed', 'exception' => $exception::class], JSON_THROW_ON_ERROR);
    exit(1);
}
