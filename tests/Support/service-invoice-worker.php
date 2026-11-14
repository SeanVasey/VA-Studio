<?php

use App\Domain\Services\Invoicing\ServiceInvoiceException;
use App\Domain\Services\Invoicing\ServiceInvoices;
use App\Domain\Services\Invoicing\StripeInvoiceGateway;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeStripeInvoiceGateway;

require dirname(__DIR__, 2).'/vendor/autoload.php';
require_once __DIR__.'/FakeStripeInvoiceGateway.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql') {
    throw new LogicException('Synthetic native MySQL worker only.');
}
config(['services-projects.test_enabled' => true, 'services-invoicing.test_enabled' => true, 'payments.stripe.mode' => 'test']);
// Same key, same synthetic invoice: each process's fake answers exactly as the provider's idempotency key would.
$app->instance(StripeInvoiceGateway::class, new FakeStripeInvoiceGateway);
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
file_put_contents($directory.'/ready-'.$name, json_encode(['pid' => getmypid()], JSON_THROW_ON_ERROR));
$await($directory.'/start');
try {
    $result = app(ServiceInvoices::class)->issue($input['project'], $input['kind'], $input['days'], $actor);
    $outcome = ['result' => 'issued', 'id' => $result['invoice']['id'], 'state' => $result['invoice']['state'], 'replayed' => $result['replayed']];
} catch (ServiceInvoiceException $error) {
    $outcome = ['result' => 'refused', 'status' => $error->status];
}
echo json_encode($outcome + ['transactionLevel' => DB::transactionLevel()], JSON_THROW_ON_ERROR);
