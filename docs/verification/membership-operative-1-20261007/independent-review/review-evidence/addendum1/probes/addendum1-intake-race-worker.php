<?php

// Reviewer probe worker (Addendum 1, head 4716ae52). Evidence only; not part of the suite. Run from the worktree root by
// Addendum1ProbeTest on native MySQL. Two of these processes deliver the SAME signed event concurrently. The test holds a
// gap lock on the event id so both pass the duplicate pre-check and block on INSERT; the loser must take the
// concurrent-insert (PDOException) branch. The sync queue has no bound gateway, so every dispatch "is lost" by throwing
// BindingResolutionException after the event row committed; the stack trace shows which code path dispatched.

use App\Domain\Memberships\Billing\BillingException;
use App\Domain\Memberships\Billing\BillingWebhookIntake;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\BillingStripeFixtures;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql' || getenv('VA_REVIEW_A1_RACE_ONLY') !== '1') {
    throw new LogicException('Isolated native reviewer race probe only.');
}
$input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
BillingStripeFixtures::configure();
$directory = getenv('VA_REVIEW_A1_RACE_DIRECTORY');
$worker = getenv('VA_REVIEW_A1_RACE_WORKER');
$pdo = DB::connection()->getPdo();
$connectionId = (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
$pdo->exec('SET SESSION innodb_lock_wait_timeout=120');
file_put_contents($directory.'/ready-'.$worker.'.tmp', (string) $connectionId);
rename($directory.'/ready-'.$worker.'.tmp', $directory.'/ready-'.$worker);
$deadline = microtime(true) + 40;
while (! is_file($directory.'/start')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Reviewer intake race barrier timed out.');
    }
    usleep(2000);
    clearstatcache();
}
$frames = [];
try {
    $result = (new BillingWebhookIntake)->receive($input['payload'], $input['signature']);
    $outcome = 'returned';
} catch (BillingException $error) {
    $outcome = 'billing_exception';
    $reason = $error->reason;
    $frames = $error->getTrace();
} catch (Throwable $error) {
    $outcome = 'threw';
    $errorClass = $error::class;
    $frames = $error->getTrace();
}
$path = [];
foreach ($frames as $frame) {
    if (($frame['class'] ?? null) === BillingWebhookIntake::class) {
        $path[] = ($frame['function'] ?? '?').'@'.basename($frame['file'] ?? '?').':'.($frame['line'] ?? '?');
    }
}
echo json_encode(['worker' => (int) $worker, 'outcome' => $outcome, 'reason' => $reason ?? null, 'error_class' => $errorClass ?? null,
    'duplicate' => $result['duplicate'] ?? null, 'scheduled' => isset($result) ? ($result['scheduled'] === null ? 'null' : 'dispatched') : null,
    'intake_frames' => $path, 'connection_id' => $connectionId, 'pid' => getmypid(), 'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR);
