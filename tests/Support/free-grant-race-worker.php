<?php

use App\Domain\Customers\CustomerAccess;
use App\Domain\Grants\Free\FreeGrantException;
use App\Domain\Grants\Free\FreeGrants;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['customer.test_accounts_enabled' => true, 'free-grants.test_enabled' => true]);
$input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
$user = User::findOrFail($input['userId']);
$principal = app(CustomerAccess::class)->principal($user);
$id = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
file_put_contents($input['ready'], (string) $id);
try {
    $origin = (new FreeGrants)->accept($input['definitionId'], $input['request'], $principal, $user);
    echo json_encode(['status' => 200, 'id' => $origin['id']], JSON_THROW_ON_ERROR);
} catch (FreeGrantException $error) {
    echo json_encode(['status' => $error->status], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    echo json_encode(['status' => 503], JSON_THROW_ON_ERROR);
}
