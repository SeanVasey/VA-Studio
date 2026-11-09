<?php

namespace App\Console\Commands;

use App\Domain\Contracts\ContractFiles;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Removes contract renderer workspaces that a parent killed mid-render left under `contracts/render-cache` on the private
 * disk (each may still hold a copy of the signature). A render's child has a 60 s wall clock, so only workspaces unmodified
 * for at least an hour are removed; the root is never created, a symbolic link is never followed or removed, and anything
 * that is not a workspace is left in place and fails the run so it is noticed. Prints counts only, never a path.
 *
 * It refuses to run as root (effective uid 0) and removes only workspaces its own effective user owns, so it must run as
 * the site user that renders contracts (the scheduler's user). An absent private disk root or cache sweeps nothing.
 */
final class SweepContractRenderCache extends Command
{
    public const MIN_AGE_SECONDS = 3600;

    /** Test seam: a container binding that supplies the effective uid, honoured only in `testing`. */
    public const EUID_BINDING = 'vasey.contract-render-cache-sweep.euid';

    protected $signature = 'vasey:contract-render-cache-sweep {--older-than=3600 : Minimum age in seconds of a workspace to remove, at least 3600}';

    protected $description = 'Remove stale contract renderer workspaces (at least an hour old) under contracts/render-cache.';

    public function handle(): int
    {
        $age = filter_var($this->option('older-than'), FILTER_VALIDATE_INT);
        if ($age === false || $age < self::MIN_AGE_SECONDS) {
            $this->error('Invalid --older-than: at least '.self::MIN_AGE_SECONDS.' seconds.');

            return self::INVALID;
        }
        $euid = $this->laravel->environment('testing') && $this->laravel->bound(self::EUID_BINDING) ? $this->laravel->make(self::EUID_BINDING)
            : (function_exists('posix_geteuid') ? posix_geteuid() : null);
        if (! is_int($euid)) {
            $this->error('The effective user cannot be determined (the posix extension is missing); nothing was swept.');

            return self::FAILURE;
        }
        if ($euid === 0) {
            $this->error('Refusing to sweep as root: run the scheduler as the site user that renders contracts.');

            return self::FAILURE;
        }
        try {
            [$removed, $left] = (new ContractFiles)->sweepRendererWorkspaces(CarbonImmutable::now()->getTimestamp() - $age, $euid);
        } catch (Throwable $error) {
            $this->error(sprintf('The render cache could not be swept (%s).', $error::class));

            return self::FAILURE;
        }
        $this->info(sprintf('Removed %d stale render workspace(s); left %d other entr%s in place.', $removed, $left, $left === 1 ? 'y' : 'ies'));

        return $left === 0 ? self::SUCCESS : self::FAILURE;
    }
}
