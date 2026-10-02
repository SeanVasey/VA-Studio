<?php

use App\Domain\Rights\Models\RightsDeclaration;
use App\Domain\Rights\VerifyRightsDeclaration;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql') {
    throw new LogicException('Rights evidence race workers require disposable testing MySQL.');
}
$input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
$directory = getenv('VASEY_RIGHTS_EVIDENCE_RACE_DIRECTORY');
$worker = getenv('VASEY_RIGHTS_EVIDENCE_RACE_WORKER');
$connection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
DB::statement('SET SESSION innodb_lock_wait_timeout = 20');
$wait = function (string $path): void {
    $deadline = microtime(true) + 20;
    while (! is_file($path)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Rights evidence race barrier timed out.');
        }
        usleep(10000);
        clearstatcache();
    }
};
// Both workers retain the actual pending instance before the verifier acquires its write lock.
$pending = RightsDeclaration::findOrFail($input['rights_id']);
if ($pending->getOriginal('status') !== 'pending') {
    throw new LogicException('Rights race did not start from a retained pending model.');
}
file_put_contents($directory.'/ready-'.$worker, json_encode(['connection_id' => $connection, 'pid' => getmypid(),
    'original_status' => $pending->getOriginal('status'), 'rights_id' => $pending->id], JSON_THROW_ON_ERROR));
$wait($directory.'/start-'.$worker);
if ($input['operation'] === 'verify') {
    $locked = false;
    DB::listen(function ($query) use ($directory, $wait, &$locked, $input): void {
        if (! $locked && preg_match('/\Aselect\b/i', $query->sql) && str_contains($query->sql, 'from `rights_declarations`')
            && str_contains($query->sql, 'for update') && in_array($input['rights_id'], $query->bindings, true)) {
            $locked = true;
            touch($directory.'/verifier-locked');
            $wait($directory.'/commit-verifier');
        }
    });
    $verified = app(VerifyRightsDeclaration::class)->handle($pending, User::findOrFail($input['actor_id']));
    if (! $locked) {
        throw new LogicException('Verifier never acquired the expected rights row through the real service.');
    }
    $result = ['result' => 'verified', 'verified_row' => $verified->getAttributes(),
        'verification_audits' => DB::table('audit_events')->where('subject_type', RightsDeclaration::class)
            ->where('subject_id', $verified->id)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()];
} else {
    try {
        if ($input['operation'] === 'update') {
            $pending->update(['provenance_reference' => 'SYNTHETIC-UNREVIEWED-AFTER-WAIT', 'sample_disclosure' => 'Synthetic stale writer']);
        } elseif ($input['operation'] === 'delete') {
            $pending->delete();
        } else {
            throw new LogicException('Unknown rights evidence writer.');
        }
        $result = ['result' => 'incorrectly-written'];
    } catch (QueryException $error) {
        if (! str_contains($error->getMessage(), 'Verified rights evidence')) {
            throw $error;
        }
        $result = ['result' => 'guard-refused', 'exception_class' => $error::class,
            'guard_message_present' => true, 'retained_original_status' => $pending->getOriginal('status')];
    }
}
echo json_encode($result + ['connection_id' => $connection, 'pid' => getmypid()], JSON_THROW_ON_ERROR);
