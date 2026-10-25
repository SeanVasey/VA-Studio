<?php

namespace App\Console\Commands;

use App\Domain\Customers\DataRights\CustomerDataRights;
use App\Domain\Customers\DataRights\DataRightsException;
use Illuminate\Console\Command;

/**
 * Purges the encrypted bytes of customer data exports whose download window has passed (FP-082). The request and its
 * events stay as evidence; only the ciphertext is removed. Prints a count only. Not scheduled automatically: the operator
 * installs it once a production host exists (U-02).
 */
final class PruneCustomerDataExports extends Command
{
    protected $signature = 'vasey:customer-data-exports-prune {--limit=100 : Exports purged in this run, 1 to 500}';

    protected $description = 'Purge expired customer data export files; requests and their history are kept.';

    public function handle(CustomerDataRights $rights): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
        if ($limit === false) {
            $this->error('Use a limit from 1 to 500.');

            return self::INVALID;
        }
        try {
            $this->line('purged='.$rights->pruneExpired($limit));
        } catch (DataRightsException $error) {
            $this->error('refused='.$error->reason);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
