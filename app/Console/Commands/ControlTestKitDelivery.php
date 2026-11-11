<?php

namespace App\Console\Commands;

use App\Domain\SoundKits\Purchases\SoundKitDelivery;
use Illuminate\Console\Command;
use Throwable;

/** BL-13: enable or block the test delivery of one paid kit order. Changes no purchase right; absent means not enabled. */
final class ControlTestKitDelivery extends Command
{
    protected $signature = 'vasey:control-test-kit-delivery {order} {action} {--expected-version=} {--reference=}';

    protected $description = 'Enable or block the Stripe-test delivery of one paid sound-kit order.';

    public function handle(): int
    {
        try {
            $version = $this->option('expected-version');
            $reference = $this->option('reference');
            if (! is_string($version) || preg_match('/\A(?:0|[1-9][0-9]{0,8})\z/D', $version) !== 1 || ! is_string($reference)) {
                throw new \InvalidArgumentException;
            }
            $control = app(SoundKitDelivery::class)->control((string) $this->argument('order'), (string) $this->argument('action'), (int) $version, $reference);
            $this->line((string) $this->argument('order').' '.$control->state.' version='.$control->version);

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Kit test delivery control is unavailable or the request conflicts with retained evidence.');

            return self::FAILURE;
        }
    }
}
