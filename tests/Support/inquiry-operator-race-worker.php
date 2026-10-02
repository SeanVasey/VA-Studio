<?php

use App\Domain\Inquiries\InquiryException;
use App\Domain\Inquiries\SubmitInquiry;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use Filament\Facades\Filament;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (! $app->environment('testing') || DB::getDriverName() !== 'mysql') {
        throw new LogicException('Operator inquiry races require test MySQL.');
    }
    $directory = getenv('VASEY_INQUIRY_OPERATOR_RACE_DIRECTORY');
    $worker = getenv('VASEY_INQUIRY_OPERATOR_RACE_WORKER');
    if (! is_string($directory) || ! is_dir($directory) || ! in_array($worker, ['0', '1'], true)) {
        throw new LogicException('Missing operator race configuration.');
    }
    $input = json_decode(stream_get_contents(STDIN, 65536), true, 16, JSON_THROW_ON_ERROR);
    if (! in_array($input['operation'] ?? null, ['submit', 'revoke'], true)
        || ! is_int($input['operator_id'] ?? null) || $input['operator_id'] < 1
        || (($input['operation'] === 'revoke') && ! in_array($input['field'] ?? null, ['is_admin', 'email_verified_at', 'app_authentication_secret'], true))) {
        throw new LogicException('Unsupported operator race operation.');
    }
    config(['inquiries.enabled' => true, 'inquiries.privacy_notice' => 'SYNTHETIC RACE PRIVACY NOTICE',
        'inquiries.retention_policy_reference' => 'SYNTHETIC-RACE-RETENTION', 'inquiries.operator_user_id' => $input['operator_id'],
        'inquiries.operator_notifications_enabled' => false]);
    $panel = Filament::getPanel('admin');
    $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
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
        throw new RuntimeException('Operator worker barrier timed out.');
    };
    $isLock = fn (string $sql): bool => preg_match('/\Aselect\b/i', $sql)
        && str_contains($sql, 'from `users`') && str_contains($sql, 'for update');
    $observedFirstLock = false;
    $armed = false;
    DB::connection()->beforeExecuting(function ($query) use ($isLock, $directory, $worker, $connection, $wait, &$observedFirstLock, &$armed): void {
        if (! $observedFirstLock && $isLock($query)) {
            $observedFirstLock = true;
            $armed = true;
            file_put_contents($directory.'/ready-'.$worker, (string) $connection);
            $wait($directory.'/start-'.$worker);
        }
    });
    DB::listen(function ($query) use ($isLock, $directory, $worker, $wait, &$armed): void {
        if ($armed && $isLock($query->sql)) {
            $armed = false;
            touch($directory.'/locked-'.$worker);
            $wait($directory.'/commit');
        }
    });
    DB::beginTransaction();
    $snapshotEligible = null;
    try {
        if ($input['operation'] === 'submit') {
            // Deliberately keep an old valid consistent read view before either users lock.
            // Current-lock authority decisions must not consult this retained RR snapshot.
            $oldOperator = User::findOrFail($input['operator_id']);
            $snapshotEligible = Gate::forUser($oldOperator)->allows('administer-catalog') && AdminMultiFactor::satisfiedBy($oldOperator);
            if (! $snapshotEligible) {
                throw new LogicException('The synthetic old authority snapshot must be eligible.');
            }
            try {
                $result = app(SubmitInquiry::class)->handle($input['payload'], $input['owner_hash']);
            } catch (InquiryException $error) {
                $result = ['result' => 'rejected', 'status' => $error->status];
            }
        } else {
            User::query()->lockForUpdate()->findOrFail($input['operator_id']);
            $value = $input['field'] === 'is_admin' ? false : null;
            if (DB::table('users')->where('id', $input['operator_id'])->update([$input['field'] => $value]) !== 1) {
                throw new RuntimeException('The synthetic operator revocation must change exactly one row.');
            }
            $result = ['result' => 'revoked', 'field' => $input['field']];
        }
        DB::commit();
    } catch (Throwable $error) {
        DB::rollBack();
        throw $error;
    }
    echo json_encode($result + ['snapshot_eligible' => $snapshotEligible, 'connection_id' => $connection,
        'pid' => getmypid(), 'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR);
    exit(0);
} catch (Throwable $error) {
    echo json_encode(['result' => 'worker_failed', 'exception' => $error::class], JSON_THROW_ON_ERROR);
    exit(1);
}
