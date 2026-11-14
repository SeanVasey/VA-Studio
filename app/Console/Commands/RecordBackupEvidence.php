<?php

namespace App\Console\Commands;

use App\Domain\Operations\OperationalSignals;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * Appends one backup or restore-drill result for the FP-028 dashboard. The host backup script calls it after each nightly
 * run (ops/staging/backup.sh and the production kit derived from it); an operator records a restore drill by hand.
 *
 * Accepts only a kind, a status, a byte count and a lowercase SHA-256: no path, host name, credential or free text, so
 * nothing secret can be stored through it. Refusals name the option, never echo its value.
 */
final class RecordBackupEvidence extends Command
{
    protected $signature = 'vasey:backup-record
        {--status= : ok or failed}
        {--kind= : daily (the nightly backup and its restore proof) or restore-drill}
        {--bytes= : Size of the backup set in bytes (required for a successful daily backup)}
        {--sha256= : Lowercase SHA-256 of the database dump (required for a successful daily backup)}';

    protected $description = 'Record a backup or restore-drill result for the operational health dashboard (append-only).';

    public function handle(OperationalSignals $signals): int
    {
        $values = [];
        foreach (['kind', 'status', 'bytes', 'sha256'] as $option) {
            $value = $this->option($option);
            if ($value !== null && ! is_string($value)) {
                $this->error("Invalid --{$option}.");

                return self::INVALID;
            }
            $values[$option] = $value;
        }
        if ($values['kind'] === null || $values['status'] === null) {
            $this->error('--kind and --status are required.');

            return self::INVALID;
        }
        try {
            $signals->recordBackup($values['kind'], $values['status'], $values['bytes'], $values['sha256']);
        } catch (InvalidArgumentException $refused) {
            $this->error($refused->getMessage() === 'evidence'
                ? 'A successful daily backup needs a nonzero --bytes and --sha256.'
                : 'Invalid --'.$refused->getMessage().'.');

            return self::INVALID;
        } catch (Throwable) {
            $this->error('The backup evidence could not be recorded.');

            return self::FAILURE;
        }
        $this->info("Recorded {$values['kind']} {$values['status']}.");

        return self::SUCCESS;
    }
}
