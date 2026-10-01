<?php

use App\Domain\Inquiries\InquiryAdministration;
use App\Domain\Inquiries\Models\CustomerInquiry;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (! $app->environment('testing') || DB::getDriverName() !== 'mysql') {
        throw new LogicException('Administration inquiry races require test MySQL.');
    }
    $directory = getenv('VASEY_INQUIRY_ADMINISTRATION_RACE_DIRECTORY');
    $worker = getenv('VASEY_INQUIRY_ADMINISTRATION_RACE_WORKER');
    if (! is_string($directory) || ! is_dir($directory) || ! in_array($worker, ['0', '1'], true)) {
        throw new LogicException('Missing administration race configuration.');
    }
    $input = json_decode(stream_get_contents(STDIN, 65536), true, 16, JSON_THROW_ON_ERROR);
    if (! in_array($input['operation'] ?? null, ['view', 'inbox', 'transition', 'revoke'], true)
        || (($input['operation'] !== 'revoke') && (! is_int($input['inquiry_id'] ?? null) || $input['inquiry_id'] < 1))
        || ! is_int($input['operator_id'] ?? null) || $input['operator_id'] < 1
        || (($input['operation'] === 'revoke') && ! in_array($input['field'] ?? null, ['is_admin', 'email_verified_at', 'app_authentication_secret'], true))) {
        throw new LogicException('Unsupported administration race operation.');
    }
    config(['inquiries.enabled' => true, 'inquiries.privacy_notice' => 'SYNTHETIC RACE PRIVACY NOTICE',
        'inquiries.retention_policy_reference' => 'SYNTHETIC-RACE-RETENTION', 'inquiries.operator_user_id' => $input['operator_id'],
        'inquiries.operator_notifications_enabled' => false]);
    $panel = Filament::getPanel('admin');
    $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $connection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
    $isolation = DB::selectOne('SELECT @@transaction_isolation AS isolation')->isolation;
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
        throw new RuntimeException('Administration worker barrier timed out.');
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
        if ($input['operation'] !== 'revoke') {
            // Deliberately keep an old valid consistent read view before either users lock.
            // Current-lock authority decisions must not consult this retained RR snapshot.
            $oldOperator = User::findOrFail($input['operator_id']);
            $snapshotEligible = Gate::forUser($oldOperator)->allows('administer-catalog') && AdminMultiFactor::satisfiedBy($oldOperator);
            if (! $snapshotEligible) {
                throw new LogicException('The synthetic old authority snapshot must be eligible.');
            }
            try {
                $service = app(InquiryAdministration::class);
                if ($input['operation'] === 'inbox') {
                    $body = $service->authorizedRead($oldOperator, function () use ($service, $oldOperator): array {
                        $service->openInbox($oldOperator);

                        return CustomerInquiry::query()->orderBy('id')->get()->map(fn ($inquiry): array =>
                            ['receipt' => $inquiry->public_id, 'state' => $inquiry->state, 'version' => $inquiry->version, 'payload' => $inquiry->payload])->all();
                    });
                } else {
                    $inquiry = $input['operation'] === 'view' ? $service->view($input['inquiry_id'], $oldOperator)
                        : $service->transition($input['inquiry_id'], 'archived', 0, $oldOperator);
                    $body = [['receipt' => $inquiry->public_id, 'state' => $inquiry->state, 'version' => $inquiry->version, 'payload' => $inquiry->payload]];
                }
                $result = ['result' => 'authorized', 'operation' => $input['operation'], 'body' => $body];
            } catch (AuthorizationException) {
                $result = ['result' => 'rejected', 'status' => 403];
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
        'isolation' => $isolation, 'pid' => getmypid(), 'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR);
    exit(0);
} catch (Throwable $error) {
    echo json_encode(['result' => 'worker_failed', 'exception' => $error::class], JSON_THROW_ON_ERROR);
    exit(1);
}
