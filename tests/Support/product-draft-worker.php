<?php

use App\Domain\Catalog\Models\ProductDraft;
use App\Domain\Catalog\ProductDrafts;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
$directory = $input['directory'];
$name = $input['name'];
$connection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
$wait = function (string $path): void {
    $deadline = microtime(true) + 20;
    while (! is_file($path)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Product draft worker barrier timed out.');
        }
        usleep(10000);
        clearstatcache();
    }
};
if ($input['require_mfa'] ?? false) {
    $panel = Filament::getPanel('admin');
    $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
}
$actor = User::findOrFail($input['actor_id']);
$mfaPrecheck = AdminMultiFactor::satisfiedBy($actor);
$paused = false;
DB::listen(function ($query) use ($input, $directory, $name, $wait, &$paused): void {
    if (! $paused && ($input['pause'] ?? false) && str_contains($query->sql, 'from `product_drafts`') && str_contains($query->sql, 'for update')) {
        $paused = true;
        touch($directory.'/locked-'.$name);
        $wait($directory.'/release-'.$name);
    }
});
file_put_contents($directory.'/ready-'.$name, json_encode(['connection_id' => $connection, 'pid' => getmypid()], JSON_THROW_ON_ERROR));
$wait($directory.'/start-'.$name);
try {
    $service = app(ProductDrafts::class);
    $draft = isset($input['draft_id']) ? ProductDraft::findOrFail($input['draft_id']) : null;
    $result = match ($input['operation']) {
        'snapshot' => $service->snapshot($input['draft_id'], $actor),
        'tracks' => $service->tracks($actor),
        'select' => $service->select($draft, $input['retained_id'], $input['version'], $actor),
        default => $service->save($input['operation'] === 'create' ? null : $draft, $input['payload'], $actor),
    };
    $response = ['result' => 'saved', 'version' => $result instanceof ProductDraft ? $result->version : null];
} catch (ValidationException) {
    $response = ['result' => 'rejected'];
} catch (AuthorizationException) {
    $response = ['result' => 'unauthorized'];
}
echo json_encode($response + ['connection_id' => $connection, 'pid' => getmypid(), 'mfa_precheck' => $mfaPrecheck,
    'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR);
