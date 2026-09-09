<?php

namespace App\Console\Commands;

use App\Support\Diagnostics\InstallationReport;
use Illuminate\Console\Command;

final class Doctor extends Command
{
    protected $signature = 'vasey:doctor {--json : Emit a stable, redacted JSON installation report}';

    protected $description = 'Inspect installation prerequisites without changing configuration or contacting external providers';

    public function handle(InstallationReport $installation): int
    {
        $report = $installation->collect();
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Check', 'Status', 'Result / next action'], array_map(fn (array $check) => array_values($check), $report['checks']));
            $this->line('Read-only installation checks. Optional warnings identify unfinished setup; passing is not production acceptance.');
        }

        return $report['foundation_ready'] ? self::SUCCESS : self::FAILURE;
    }
}
