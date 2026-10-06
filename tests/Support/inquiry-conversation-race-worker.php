<?php

use App\Domain\Inquiries\InquiryConversation;
use App\Domain\Inquiries\InquiryException;
use App\Domain\Inquiries\Models\CustomerInquiry;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (! $app->environment('testing') || DB::getDriverName() !== 'mysql') {
        throw new LogicException('Conversation races require isolated test MySQL.');
    }
    $input = json_decode(stream_get_contents(STDIN, 16384), true, 16, JSON_THROW_ON_ERROR);
    if (! in_array($input['operation'] ?? null, ['owner', 'reply', 'read'], true) || ! is_int($input['inquiry_id'] ?? null)
        || ! is_int($input['actor_id'] ?? null) || ! is_string($input['ready'] ?? null) || ! is_dir(dirname($input['ready']))) {
        throw new LogicException('Invalid synthetic race job.');
    }
    config(['inquiries.enabled' => true]);
    $panel = Filament::getPanel('admin');
    $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $connection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
    DB::beginTransaction();
    $actor = User::findOrFail($input['actor_id']);
    $old = CustomerInquiry::findOrFail($input['inquiry_id']);
    file_put_contents($input['ready'], (string) $connection);
    try {
        $service = app(InquiryConversation::class);
        $result = match ($input['operation']) {
            'owner' => $service->followUp($old->public_id, $input['owner'], $input['body']),
            'reply' => $service->reply($old->id, $input['body'], $actor),
            'read' => $service->staff($old->id, $actor),
        };
        $outcome = ['status' => 200, 'replayed' => $result['replayed'] ?? null, 'messageId' => $result['messageId'] ?? null];
    } catch (InquiryException $error) {
        $outcome = ['status' => $error->status];
    } catch (AuthorizationException) {
        $outcome = ['status' => 403];
    }
    DB::commit();
    echo json_encode($outcome + ['connection' => $connection, 'pid' => getmypid(), 'old_state' => $old->state,
        'old_admin' => (bool) $actor->is_admin, 'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    echo json_encode(['result' => 'worker_failed', 'exception' => $error::class], JSON_THROW_ON_ERROR);
    exit(1);
}
