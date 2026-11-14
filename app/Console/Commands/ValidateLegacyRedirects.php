<?php

namespace App\Console\Commands;

use App\Domain\Migration\LegacyRedirects\LegacyRedirectMap;
use App\Domain\Migration\LegacyRedirects\LegacyRedirectMapInvalid;
use App\Domain\Migration\LegacyRedirects\LegacyRedirectValidator;
use App\Domain\Migration\LegacyRedirects\LegacyRouteInventory;
use Illuminate\Console\Command;

/** Read-only reconciliation of the legacy redirect map against the route inventory and the current routes. */
final class ValidateLegacyRedirects extends Command
{
    protected $signature = 'vasey:redirects-validate
        {--map= : Map file (default: config legacy-redirects.map_path)}
        {--inventory= : Route inventory CSV (default: config legacy-redirects.inventory_path)}
        {--require-resolved : Also fail while any inventory row is unresolved (cutover gate)}';

    protected $description = 'Validate the legacy redirect map against the route inventory and current routes (read-only)';

    public function handle(LegacyRedirectValidator $validator): int
    {
        $mapPath = (string) ($this->option('map') ?? config('legacy-redirects.map_path'));
        $inventoryPath = (string) ($this->option('inventory') ?? config('legacy-redirects.inventory_path'));
        try {
            $map = LegacyRedirectMap::fromFile($mapPath);
            $inventory = LegacyRouteInventory::read($inventoryPath);
        } catch (LegacyRedirectMapInvalid $invalid) {
            $this->error('Legacy redirect map or inventory refused:');
            foreach ($invalid->problems as $problem) {
                $this->line('  ERROR '.$problem);
            }

            return self::FAILURE;
        }
        $report = $validator->validate($map, $inventory);
        $counts = $report['counts'];
        $this->line('Legacy redirect reconciliation (activation flag legacy-redirects.enabled is '.(config('legacy-redirects.enabled') === true ? 'ON' : 'off').')');
        $this->line(sprintf('Inventory rows: %d · map entries: %d · redirect: %d · gone: %d · retained: %d · unresolved: %d',
            $counts['inventory'], $counts['entries'], $counts['redirect'], $counts['gone'], $counts['retained'], $counts['unresolved']));
        $this->line('Query strings: dropped on redirect. Matching: exact path only.');
        foreach ($report['redirects'] as $row) {
            $this->line(sprintf('  REDIRECT %s %s -> %s (%d)', $row['route_id'], $row['source'], $row['target'], $row['status']));
        }
        foreach ($report['gone'] as $row) {
            $this->line(sprintf('  GONE     %s %s (410)', $row['route_id'], $row['source']));
        }
        foreach ($report['retained'] as $row) {
            $this->line(sprintf('  RETAINED %s %s served by %s', $row['route_id'], $row['source'], $row['route'] ?? 'nothing'));
        }
        foreach ($report['unresolved'] as $row) {
            $this->line(sprintf('  UNRESOLVED %s %s: %s', $row['route_id'], $row['source'], $row['reason']));
        }
        foreach ($report['warnings'] as $warning) {
            $this->line('  WARNING '.$warning);
        }
        foreach ($report['errors'] as $error) {
            $this->line('  ERROR '.$error);
        }
        if ($report['errors'] !== []) {
            $this->error(count($report['errors']).' error(s): the map is not fit for activation.');

            return self::FAILURE;
        }
        if ($this->option('require-resolved') && $counts['unresolved'] > 0) {
            $this->error($counts['unresolved'].' inventory row(s) are unresolved; the cutover gate requires none.');

            return self::FAILURE;
        }
        $this->info('Map valid: no errors.');

        return self::SUCCESS;
    }
}
