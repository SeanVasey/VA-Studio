<?php

namespace App\Console\Commands;

use App\Domain\Contracts\ContractFiles;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Removes contract renderer workspaces that a parent killed mid-render left in the renderer caches under `contracts/` on
 * the private disk ({@see ContractFiles::RENDER_CACHES}: the v1 test and v2 license cache, whose workspaces may still hold
 * a copy of the signature, and the paid, paid-tax and member caches). Every render's child has a 60 s wall clock, so only
 * workspaces unmodified for at least an hour are removed; no cache is ever created, a symbolic link is never followed or
 * removed, and anything that is not a workspace is left in place and fails the run so it is noticed. Each cache is swept
 * on its own: one that is a link or not private is refused (and fails the run) without stopping the others. Prints
 * counts per cache only, never a path.
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

    protected $description = 'Remove stale contract renderer workspaces (at least an hour old) from the contract render caches.';

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
        $cutoff = CarbonImmutable::now()->getTimestamp() - $age;
        $clean = true;
        foreach (ContractFiles::RENDER_CACHES as $cache) {
            try {
                [$removed, $left] = (new ContractFiles)->sweepRendererWorkspaces($cutoff, $euid, $cache);
            } catch (Throwable $error) {
                $this->error(sprintf('%s: could not be swept (%s).', $cache, $error::class));
                $clean = false;

                continue;
            }
            $this->info(sprintf('%s: removed %d stale render workspace(s); left %d other entr%s in place.', $cache, $removed, $left, $left === 1 ? 'y' : 'ies'));
            $clean = $clean && $left === 0;
        }

        return $clean ? self::SUCCESS : self::FAILURE;
    }
}
