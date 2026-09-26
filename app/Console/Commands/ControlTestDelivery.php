<?php

namespace App\Console\Commands;

use App\Domain\Delivery\ManageTestDeliveryControl;
use Illuminate\Console\Command;
use Throwable;

final class ControlTestDelivery extends Command
{
    protected $signature = 'vasey:control-test-delivery {order} {action} {--expected-version=} {--reference=}';
    protected $description = 'Provision, enable, or block internal test delivery without changing purchase rights.';

    public function handle(): int
    {
        try {
            $action = $this->argument('action'); $version = $this->option('expected-version'); $reference = $this->option('reference');
            if (! in_array($action, ['enable', 'block'], true) || ! is_string($version)
                || preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', $version) !== 1 || (int) $version >= 4294967295
                || ! is_string($reference)) { throw new \InvalidArgumentException; }
            $control = app(ManageTestDeliveryControl::class)->handle((string) $this->argument('order'), $action === 'block', (int) $version, $reference);
            $this->line($control->public_id.' '.($control->blocked ? 'blocked' : 'enabled').' version='.$control->control_version);
            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Test delivery control is unavailable or the request conflicts with retained evidence.');
            return self::FAILURE;
        }
    }
}
