<?php

use App\Domain\Customers\CustomerPrincipal;
use App\Domain\Customers\Models\CustomerAccount;
use App\Domain\Inquiries\InquiryException;
use App\Domain\Inquiries\OrderInquiry;
use App\Domain\SiteBuilder\SiteContent;
use App\Models\User;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\CustomerFixtures;
use Tests\Support\OrderFixtures;

require dirname(__DIR__, 2).'/vendor/autoload.php';
try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (! $app->environment('testing') || DB::getDriverName() !== 'mysql') {
        throw new LogicException('Disposable test MySQL required.');
    }
    $input = json_decode(stream_get_contents(STDIN, 65536), true, 24, JSON_THROW_ON_ERROR);
    $directory = $input['directory'];
    $worker = $input['worker'];
    if (! is_dir($directory) || ! in_array($worker, [0, 1], true)
        || ! in_array($input['operation'], ['submit', 'publication', 'role', 'mfa', 'credential', 'account'], true)) {
        throw new LogicException('Invalid synthetic order inquiry job.');
    }
    config($input['config']);
    OrderFixtures::configure();
    CustomerFixtures::configure();
    Carbon::setTestNow($input['at']);
    $panel = Filament::getPanel('admin');
    $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    DB::statement('SET SESSION innodb_lock_wait_timeout=15');
    $connection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
    $actor = isset($input['principal']) ? User::findOrFail($input['principal'][1]) : null;
    $principal = isset($input['principal']) ? new CustomerPrincipal(...$input['principal']) : null;
    $operator = User::findOrFail($input['operator']);
    $old = ['operator_admin' => (bool) $operator->is_admin, 'customer_active' => $principal ? CustomerAccount::findOrFail($principal->accountId)->active : null];
    $wait = function (string $path): void {
        $deadline = microtime(true) + 20;
        do {
            clearstatcache(true, $path);
            if (is_file($path)) {
                return;
            } usleep(10000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Order inquiry race barrier timed out.');
    };
    $matches = fn (string $sql, array $bindings): bool => str_starts_with(strtolower($sql), 'select')
        && str_contains($sql, 'from `'.$input['table'].'`') && str_contains($sql, 'for update')
        && in_array($input['row'], array_map('intval', $bindings), true);
    $armed = false;
    $observed = false;
    $locks = [];
    DB::connection()->beforeExecuting(function ($sql, $bindings) use ($matches, $directory, $worker, $connection, $wait, &$armed, &$observed): void {
        if (! $observed && $matches($sql, $bindings)) {
            $observed = true;
            $armed = true;
            $ready = json_encode(['connection' => $connection, 'pid' => getmypid()], JSON_THROW_ON_ERROR);
            $temporary = $directory.'/ready-'.$worker.'.tmp';
            if (file_put_contents($temporary, $ready) !== strlen($ready) || ! rename($temporary, $directory.'/ready-'.$worker)) {
                throw new RuntimeException('Cannot publish complete worker readiness.');
            }
            $wait($directory.'/start-'.$worker);
        }
    });
    DB::listen(function ($query) use ($matches, $directory, $worker, $wait, &$armed, &$locks): void {
        if (str_contains($query->sql, 'for update') && preg_match('/from `([a-z_]+)`/', $query->sql, $match)) {
            $locks[] = $match[1];
        }
        if ($armed && $matches($query->sql, $query->bindings)) {
            $armed = false;
            touch($directory.'/locked-'.$worker);
            $wait($directory.'/commit');
        }
    });
    try {
        if ($input['operation'] === 'submit') {
            $result = app(OrderInquiry::class)->submit($input['order'], $input['owner'], $input['inquiry_owner'], $input['body'], $principal, $actor);
            $outcome = ['status' => 200] + $result;
        } elseif ($input['operation'] === 'publication') {
            app(SiteContent::class)->publish($input['release'], $input['revision'], $operator);
            $outcome = ['status' => 200, 'withdrawn' => true];
        } else {
            DB::transaction(function () use ($input): void {
                $user = User::whereKey($input['row'])->lockForUpdate()->firstOrFail();
                match ($input['operation']) {
                    'role' => $user->forceFill(['is_admin' => false])->save(),
                    'mfa' => $user->saveAppAuthenticationSecret(null),
                    'credential' => $user->forceFill(['password' => 'Changed-synthetic-race-password-43!'])->save(),
                    'account' => (function () use ($user): void {
                        $account = CustomerAccount::where('user_id', $user->id)->lockForUpdate()->firstOrFail();
                        $account->update(['active' => false, 'access_version' => $account->access_version + 1]);
                    })(),
                };
            });
            $outcome = ['status' => 200, 'withdrawn' => true];
        }
    } catch (InquiryException $error) {
        $outcome = ['status' => $error->status];
    }
    echo json_encode($outcome + ['connection' => $connection, 'pid' => getmypid(), 'old' => $old, 'locks' => $locks,
        'isolation' => DB::selectOne('SELECT @@transaction_isolation AS isolation')->isolation,
        'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    echo json_encode(['result' => 'worker_failed', 'exception' => $error::class, 'message' => $error->getMessage()], JSON_THROW_ON_ERROR);
    exit(1);
}
