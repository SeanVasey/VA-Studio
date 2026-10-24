<?php

/*
 * Independent-process worker for the native family 256 first-byte race (ProductionFreeGrantFirstByteTest). Each mode
 * is its own operating-system process with its own connection to the shared disposable MySQL database. Barriers are
 * files in VA_PRODUCTION_FREE_RACE_DIRECTORY. Output is one JSON line of fixed facts: never a token, key or binding.
 */

use App\Domain\Customers\ProductionIdentity\ProductionCustomerSessions;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantDownloads;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrants;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantSources;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\FakeProductionFreeSources;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$directory = getenv('VA_PRODUCTION_FREE_RACE_DIRECTORY');
if (! $app->environment('testing') || getenv('VA_PRODUCTION_FREE_RACE_ONLY') !== '1' || ! is_string($directory) || ! is_dir($directory)
    || DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== getenv('DB_DATABASE')) {
    throw new LogicException('Explicit isolated native production free race only.');
}
$input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
config(['app.key' => $input['app_key'], 'production-free-grants' => $input['free'], 'production-customer-identity' => $input['identity'],
    'filesystems.disks.local.root' => $input['private_root'], 'filesystems.disks.local.serve' => false,
    'filesystems.disks.local.visibility' => 'private']);
$sources = new FakeProductionFreeSources;
$sources->bytes = $input['bytes'];
$app->instance(ProductionFreeGrantSources::class, $sources);
$panel = Filament::getPanel('admin');
$panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);

$barrier = static function (string $name) use ($directory): void {
    $end = hrtime(true) + 60_000_000_000;
    while (! is_file($directory.'/'.$name)) {
        if (hrtime(true) > $end) {
            throw new RuntimeException('Barrier '.$name.' timed out.');
        }
        usleep(10000);
        clearstatcache();
    }
};
$signal = static function (string $name) use ($directory): void {
    file_put_contents($directory.'/'.$name.'.tmp', (string) getmypid());
    rename($directory.'/'.$name.'.tmp', $directory.'/'.$name);
};
$pdo = DB::connection()->getPdo();
$result = ['result' => 'unexpected', 'connection_id' => (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn(), 'pid' => getmypid()];

try {
    switch ($input['mode']) {
        case 'redeem':
            $buyer = (new ProductionCustomerSessions)->authenticate($input['email'] ?? 'free-owner@example.test', 'MailboxPassword123');
            if ($buyer === null) {
                throw new LogicException('The enrolled synthetic buyer did not authenticate.');
            }
            if (($input['gate'] ?? false) === true) {
                // Authenticated before any peer holds a lock; the redemption itself starts at the gate.
                $signal('ready');
                $barrier('go');
            }
            $transfer = (new ProductionFreeGrantDownloads)->redeem($input['authorization_id'], $input['token'], $buyer['principal'], $buyer['user']);
            $signal('redeemed');
            if (($input['proceed'] ?? true) !== false) {
                $barrier('proceed');
            }
            $bytes = 0;
            $hash = hash_init('sha256');
            try {
                $transfer->writeTo(static function (string $chunk) use (&$bytes, $hash): void {
                    $bytes += strlen($chunk);
                    hash_update($hash, $chunk);
                });
                $result = [...$result, 'result' => 'delivered', 'bytes' => $bytes, 'sha256' => hash_final($hash)];
            } catch (ProductionFreeGrantException $error) {
                $result = [...$result, 'result' => 'refused', 'reason' => $error->reason, 'bytes' => $bytes];
            }
            break;
        case 'revoke':
            if ($input['hold'] === true) {
                Event::listen(TransactionCommitting::class, static function () use ($signal, $barrier): void {
                    static $held = false;
                    if (! $held && DB::table('production_free_revocations')->count() > 0) {
                        $held = true;
                        // The revocation row and its locks exist, uncommitted, at this command's own commit.
                        $signal('holding');
                        $barrier('commit');
                    }
                });
            }
            (new ProductionFreeGrants)->revoke($input['origin_id'], ['originSeal' => $input['seal'], 'reason' => 'Synthetic independent revocation'],
                User::query()->findOrFail($input['staff_id']));
            $result = [...$result, 'result' => 'revoked'];
            break;
        case 'lock-rows':
            $pdo->beginTransaction();
            $pdo->prepare('SELECT id FROM production_free_origins WHERE id = ? FOR UPDATE')->execute([$input['origin_id']]);
            $pdo->prepare('SELECT id FROM production_free_revocations WHERE origin_id = ? FOR UPDATE')->execute([$input['origin_id']]);
            $signal('locked');
            $barrier('release');
            $pdo->rollBack();
            $result = [...$result, 'result' => 'released'];
            break;
        case 'observe-frame':
            $config = DB::connection()->getConfig();
            $observer = new PDO('mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'], $config['username'], $config['password'] ?? '',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('LOCK TABLES production_free_origins WRITE');
            $signal('locked');
            $pending = $observer->prepare("SELECT COUNT(*) FROM performance_schema.metadata_locks l JOIN performance_schema.threads t ON t.THREAD_ID = l.OWNER_THREAD_ID
                WHERE t.PROCESSLIST_ID = ? AND l.LOCK_STATUS = 'PENDING' AND l.OBJECT_NAME = 'production_free_origins'");
            $end = hrtime(true) + 60_000_000_000;
            do {
                $pending->execute([$input['connection_id']]);
                $waiting = (int) $pending->fetchColumn() > 0;
                if (! $waiting) {
                    usleep(10000);
                }
            } while (! $waiting && hrtime(true) < $end);
            $transaction = $observer->prepare('SELECT e.ISOLATION_LEVEL, e.ACCESS_MODE, e.STATE FROM performance_schema.events_transactions_current e
                JOIN performance_schema.threads t ON t.THREAD_ID = e.THREAD_ID WHERE t.PROCESSLIST_ID = ?');
            $transaction->execute([$input['connection_id']]);
            $seen = $transaction->fetch(PDO::FETCH_ASSOC) ?: [];
            $innodb = $observer->prepare('SELECT trx_isolation_level, trx_is_read_only FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = ?');
            $innodb->execute([$input['connection_id']]);
            $trx = $innodb->fetch(PDO::FETCH_ASSOC) ?: [];
            $pdo->exec('UNLOCK TABLES');
            $result = [...$result, 'result' => $waiting ? 'observed' : 'not_waiting', 'isolation' => $seen['ISOLATION_LEVEL'] ?? null,
                'access_mode' => $seen['ACCESS_MODE'] ?? null, 'state' => $seen['STATE'] ?? null,
                'innodb_isolation' => $trx['trx_isolation_level'] ?? null, 'innodb_read_only' => isset($trx['trx_is_read_only']) ? (int) $trx['trx_is_read_only'] : null];
            break;
        default:
            throw new LogicException('Unknown race mode.');
    }
} catch (ProductionFreeGrantException $error) {
    $result = [...$result, 'result' => 'refused', 'reason' => $error->reason, 'bytes' => 0];
} catch (Throwable $error) {
    // Call sites only: never arguments, SQL bindings, configuration or credentials.
    $result = [...$result, 'result' => 'unexpected', 'error_class' => $error::class, 'location' => basename($error->getFile()).':'.$error->getLine()];
}
echo json_encode($result, JSON_THROW_ON_ERROR);
