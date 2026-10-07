<?php

namespace App\Console\Commands;

use App\Domain\Notifications\TestTransactionalNotificationRecovery;
use Illuminate\Console\Command;
use Throwable;

final class RecoverTestNotifications extends Command
{
    protected $signature = 'vasey:recover-test-notifications {--after=0 : Internal row cursor from the previous page} {--limit=10 : Maximum private test notices to inspect (1-25)}';

    protected $description = 'Recover bounded private test captures without sending email or replacing uncertain leases.';

    public function handle(): int
    {
        try {
            $after = $this->decimal($this->option('after'));
            $limit = $this->decimal($this->option('limit'));
            $result = app(TestTransactionalNotificationRecovery::class)->scan($after, $limit);
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return in_array('unavailable', array_column($result['results'], 'state'), true)
                ? self::FAILURE : self::SUCCESS;
        } catch (Throwable) {
            $this->line('{"recoverySchema":1,"testOnly":true,"state":"unavailable"}');

            return self::FAILURE;
        }
    }

    private function decimal(mixed $value): int
    {
        if (! is_string($value) || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) !== 1
            || strlen($value) > strlen((string) PHP_INT_MAX)
            || (strlen($value) === strlen((string) PHP_INT_MAX) && strcmp($value, (string) PHP_INT_MAX) > 0)) {
            throw new \InvalidArgumentException;
        }

        return (int) $value;
    }
}
