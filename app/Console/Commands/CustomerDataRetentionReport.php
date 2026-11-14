<?php

namespace App\Console\Commands;

use App\Domain\Customers\DataRights\DataRightsException;
use App\Domain\Customers\DataRights\RetentionReport;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Dry run of the retention schedule (FP-076, U-13): per class and table, how many rows are past their configured period and
 * when they fell due. Read-only and privacy-safe (counts and dates only). It refuses while the retention configuration is
 * unset or invalid. Purging or anonymizing is a later step that needs its own review and Sean's authorization.
 */
final class CustomerDataRetentionReport extends Command
{
    protected $signature = 'vasey:retention-report';

    protected $description = 'Read-only dry run: count customer records past their retention period. Nothing is deleted.';

    public function handle(RetentionReport $report): int
    {
        try {
            $result = $report->build(CarbonImmutable::now());
        } catch (DataRightsException $error) {
            $this->error('refused='.$error->reason.($error->reason === 'retention_policy_unset'
                ? ' Set every customer-data-rights.retention value on this host (the approved U-13 schedule); periods are never inferred.'
                : ' A scheduled table or column is missing; run the migrations first.'));

            return self::FAILURE;
        }

        $this->line('retention-report dry_run=true writes=none as_of='.$result['as_of'].' policy_reference='.json_encode($result['policy_reference'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        foreach ($result['classes'] as $class) {
            $this->line('class='.$class['class'].' '.$class['unit'].'='.$class['period'].' cutoff='.$class['cutoff']);
            foreach ($class['tables'] as $table) {
                if (isset($table['held'])) {
                    $this->line('  '.$table['table'].' held='.$table['held']);

                    continue;
                }
                $this->line('  '.$table['table'].' past_period='.$table['past_period']
                    .(isset($table['follows']) ? ' follows='.$table['follows']
                        : ' oldest_due='.($table['oldest_due'] ?? '-').' newest_due='.($table['newest_due'] ?? '-')
                            .' clock_index='.($table['clock_index'] ? 'yes' : 'missing')));
            }
        }
        $this->line('class=indefinite never_purged_by_this_mechanism='.implode(',', $result['indefinite']));
        $this->line('class=not_applicable account_lifecycle_or_not_customer_data='.implode(',', $result['not_applicable']));
        $this->line('unclassified='.implode(',', $result['unclassified']).' count='.count($result['unclassified'])
            .' note=each_needs_owner_retention_decision');
        $this->line('Nothing was changed. Past-period counts are review candidates: any purge or anonymization is a later, separately authorized step.');

        return self::SUCCESS;
    }
}
