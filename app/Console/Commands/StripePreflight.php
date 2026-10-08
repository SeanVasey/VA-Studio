<?php

namespace App\Console\Commands;

use App\Domain\Commerce\Readiness\StripeCapabilityPreflight;
use Illuminate\Console\Command;

final class StripePreflight extends Command
{
    protected $signature = 'vasey:stripe-preflight
        {--json : Emit the versioned redacted Stripe configuration, pin and capability report}
        {--probe : Request read-only GET /v1/account and capability observation (never default)}
        {--i-understand-this-calls-stripe : Explicit confirmation required with --probe; provider I/O must also be enabled}';

    protected $description = 'Check Stripe checkout configuration shape and SDK/API pins without provider I/O; an explicit, confirmed probe reads account capabilities only';

    public function handle(StripeCapabilityPreflight $preflight): int
    {
        $report = $preflight->collect((bool) $this->option('probe'), (bool) $this->option('i-understand-this-calls-stripe'));
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Category', 'Check', 'Status', 'Boundary'], array_map(
                fn (array $check): array => [$check['category'], $check['id'], $check['status'], $check['message']], $report['checks'],
            ));
            $this->line('This preflight authorizes no activation, credential change, payment, refund or deployment. Configured values are not operational proof.');
        }

        $probe = $report['probe']['status'];

        return $report['configuration_shape_valid'] && $report['pins_valid'] && in_array($probe, ['not_requested', 'pass'], true)
            ? self::SUCCESS : self::FAILURE;
    }
}
