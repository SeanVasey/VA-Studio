<?php

use App\Domain\Migration\History\ExclusivityAuthority;
use App\Domain\Migration\History\LegacyArchiveRefusal;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (! $app->environment('testing') || DB::getDriverName() !== 'mysql') {
        throw new LogicException('Exclusivity authority races require test MySQL.');
    }
    $directory = getenv('VASEY_AUTHORITY_RACE_DIRECTORY');
    $worker = getenv('VASEY_AUTHORITY_RACE_WORKER');
    if (! is_string($directory) || ! is_dir($directory) || ! in_array($worker, ['0', '1'], true)) {
        throw new LogicException('Missing exclusivity authority race configuration.');
    }
    $input = json_decode(stream_get_contents(STDIN, 65536), true, 16, JSON_THROW_ON_ERROR);
    if (! is_int($input['actor_id'] ?? null) || ! in_array($input['transition'] ?? null, ['declare', 'freeze', 'unfreeze', 'revert'], true)) {
        throw new LogicException('Unsupported exclusivity authority race input.');
    }
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $connection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
    $wait = function (string ...$paths): void {
        $deadline = microtime(true) + 30;
        do {
            foreach ($paths as $path) {
                clearstatcache(true, $path);
                if (is_file($path)) {
                    return;
                }
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Exclusivity authority worker barrier timed out.');
    };
    $isHead = fn (string $sql): bool => preg_match('/\Aselect\b/i', $sql) === 1
        && str_contains($sql, 'from `legacy_exclusivity_authority_events`') && str_contains($sql, 'for update');
    $armed = false;
    DB::connection()->beforeExecuting(function ($query) use ($isHead, $directory, $worker, $connection, $wait, &$armed): void {
        if (! $armed && $isHead($query)) {
            $armed = true;
            file_put_contents($directory.'/ready-'.$worker, (string) $connection);
            $wait($directory.'/start', $directory.'/start-'.$worker);
        }
    });
    $held = false;
    DB::listen(function ($query) use ($isHead, $directory, $worker, $wait, &$armed, &$held): void {
        if ($armed && ! $held && $isHead($query->sql)) {
            $held = true;
            touch($directory.'/locked-'.$worker);
            $wait($directory.'/commit');
        }
    });
    try {
        $result = app(ExclusivityAuthority::class)->transition($input['transition'], User::findOrFail($input['actor_id']),
            'SYNTHETIC race reference '.$worker, null, true);
        $output = ['outcome' => $result['outcome'], 'to' => $result['to'], 'sequence' => $result['sequence']];
    } catch (LegacyArchiveRefusal $refusal) {
        $output = ['outcome' => 'refused', 'reason' => $refusal->reason];
    }
    echo json_encode($output + ['connection_id' => $connection, 'pid' => getmypid(), 'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR);
    exit(0);
} catch (Throwable $error) {
    echo json_encode(['outcome' => 'worker_failed', 'exception' => $error::class, 'message' => substr($error->getMessage(), 0, 300)], JSON_THROW_ON_ERROR);
    exit(1);
}
