<?php

namespace App\Console\Commands;

use App\Domain\Commerce\Readiness\ProductionCommerceReadiness;
use Illuminate\Console\Command;

final class CommerceReadiness extends Command
{
    protected $signature = 'vasey:commerce-readiness {--json : Emit the versioned redacted production-commerce preparation inventory}';

    protected $description = 'Report production track-commerce code, configuration and acceptance gaps without enabling or contacting services';

    public function handle(ProductionCommerceReadiness $readiness): int
    {
        $report = $readiness->collect();
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Category', 'Check', 'Status', 'Required work / boundary'], array_map(
                fn (array $check): array => [$check['category'], $check['id'], $check['status'], $check['message']], $report['checks'],
            ));
            $this->line('Production commerce remains blocked. Configured settings are not operational proof. This report changes nothing and authorizes no sale, deployment or cutover.');
        }

        return $report['production_commerce_ready'] ? self::SUCCESS : self::FAILURE;
    }
}
