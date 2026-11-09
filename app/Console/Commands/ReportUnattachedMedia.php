<?php

namespace App\Console\Commands;

use App\Domain\Media\Lifecycle\MediaLifecycleInventory;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Dry-run report of unattached completed media (FP-034 tier 3). Lists completed asset families (a processed source and its
 * derived assets) created at least --older-than-days ago that the full reference proof found unreferenced, each with the
 * names of the checks that found zero references. It deletes, locks and writes nothing; there is no execute mode. A family
 * is "unattached" only when coverage is complete; the blockers that make coverage incomplete are printed instead.
 *
 * Prints asset ids, roles, recorded sizes and check names only: never a storage path, file name or customer value.
 */
final class ReportUnattachedMedia extends Command
{
    protected $signature = 'vasey:media-unattached-report
        {--older-than-days=30 : Minimum age in days of the source upload, 1 to 3650}
        {--limit=50 : Families to prove, 1 to 200}
        {--proof : Print every zero-reference check name for each unattached family}';

    protected $description = 'Dry-run list of unattached completed media families with their reference proof; never deletes.';

    public function handle(): int
    {
        $days = filter_var($this->option('older-than-days'), FILTER_VALIDATE_INT);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($days === false || $days < 1 || $days > 3650 || $limit === false || $limit < 1 || $limit > 200) {
            $this->error('Invalid --older-than-days (1 to 3650) or --limit (1 to 200).');

            return self::INVALID;
        }
        try {
            // One read transaction gives every check the same snapshot where the engine supports it.
            $report = DB::transaction(fn (): array => app(MediaLifecycleInventory::class)->candidates($days, CarbonImmutable::now(), $limit));
        } catch (Throwable $error) {
            $this->error(sprintf('The report failed (%s).', $error::class));

            return self::FAILURE;
        }
        $this->info(sprintf('Dry run: nothing was changed. Completed families older than %d day(s) without a structural reference: %d proven%s.',
            $days, count($report['families']), $report['proven_bound'] ? ' (more remain beyond --limit)' : ''));
        $this->info('Coverage: '.$report['coverage'].($report['blockers'] === [] ? '' : '; blockers: '.implode(', ', $report['blockers'])));
        $unattached = 0;
        foreach ($report['families'] as $family) {
            $unattached += $family['state'] === 'unattached' ? 1 : 0;
            $this->line(sprintf('#%d %s track #%d assets %s (%s) recorded bytes %s; %d zero-reference checks%s', $family['id'], $family['state'],
                $family['track_id'], implode(',', $family['members']), implode(',', $family['roles']), $family['recorded_bytes'] ?? 'unknown',
                count($family['zero_checks']), $family['referencing_checks'] === [] ? '' : '; referenced by '.implode(', ', array_keys($family['referencing_checks']))));
            if ($this->option('proof') && $family['state'] === 'unattached') {
                $this->line('  proof: '.implode(' ', $family['zero_checks']));
            }
        }
        $this->info(sprintf('Unattached: %d. Deleting them is not implemented; see docs/media-processing.md.', $unattached));

        return self::SUCCESS;
    }
}
