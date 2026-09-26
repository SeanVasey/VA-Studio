<?php

namespace App\Domain\Contracts;

use App\Jobs\IssueTestContractJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Throwable;

final class DispatchTestContract
{
    public function handle(int $grantId): void
    {
        try {
            app(ContractIssuancePolicy::class)->current();
            $connection = config('queue.default');
            $driver = is_string($connection) ? config('queue.connections.'.$connection.'.driver') : null;
            if (! in_array($driver, ['database', 'redis', 'sqs', 'beanstalkd'], true)) { return; }
            $send = static function () use ($grantId, $connection): void {
                try {
                    // Configuration may have been withdrawn before the containing transaction committed.
                    app(ContractIssuancePolicy::class)->current();
                    $driver = config('queue.connections.'.$connection.'.driver');
                    if (! in_array($driver, ['database', 'redis', 'sqs', 'beanstalkd'], true)) { return; }
                    Bus::dispatch((new IssueTestContractJob($grantId))->onConnection($connection));
                }
                catch (Throwable) { /* Paid grant scanning recovers missed dispatch. */ }
            };
            if (DB::transactionLevel() > 0) { DB::afterCommit($send); }
            else { $send(); }
        } catch (Throwable) { /* Contract dispatch cannot undo retained paid rights. */ }
    }
}
