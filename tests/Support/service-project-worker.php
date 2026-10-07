<?php

use App\Domain\Services\Projects\ServiceProjectException;
use App\Domain\Services\Projects\ServiceProjects;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql') {
    throw new LogicException('Synthetic native MySQL worker only.');
}
config(['services-projects.test_enabled' => true]);
$directory = $input['directory'];
$name = $input['name'];
$await = function (string $file): void {
    $deadline = microtime(true) + 20;
    while (! is_file($file)) {
        clearstatcache();
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Worker barrier timed out.');
        }
        usleep(10000);
    }
};
$actor = User::findOrFail($input['actor']);
$snapshot = false;
if ($input['snapshot'] ?? false) {
    $armed = true;
    DB::listen(function (QueryExecuted $query) use (&$armed, &$snapshot, $directory, $name): void {
        if ($armed && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, '`users`')) {
            $armed = false;
            DB::table('service_project_events')->count();
            $snapshot = true;
            touch($directory.'/snapshot-'.$name);
        }
    });
}
if ($input['pause'] ?? false) {
    AuditEvent::created(function (AuditEvent $event) use ($directory, $name, $await): void {
        if ($event->action === 'service_project.author_quote') {
            touch($directory.'/locked-'.$name);
            $await($directory.'/release-'.$name);
        }
    });
}
file_put_contents($directory.'/ready-'.$name, json_encode(['pid' => getmypid(), 'connection' => (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id], JSON_THROW_ON_ERROR));
$await($directory.'/start-'.$name);
try {
    app(ServiceProjects::class)->staffCommand($input['project'], $input['body'], $actor);
    $result = 'saved';
} catch (ServiceProjectException $error) {
    $result = 'rejected';
}
echo json_encode(['result' => $result, 'snapshot' => $snapshot, 'transactionLevel' => DB::transactionLevel()], JSON_THROW_ON_ERROR);
