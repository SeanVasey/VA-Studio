<?php

declare(strict_types=1);

namespace App\Domain\Migration\History;

/**
 * Environment admission for the history archive and the exclusivity authority ledger.
 *
 * Admitted in `local` and `testing`. Any other environment is refused unless the reviewed `legacy-archive`
 * configuration names it exactly (empty by default, no environment variable). An ambiguous environment (the
 * configured and the running one differ, for example through `--env`) is refused, and a synthetic fixture is
 * never admitted into production.
 */
final class LegacyArchivePolicy
{
    public const LOCAL = ['local', 'testing'];

    public function admit(): void
    {
        $configured = config('app.env');
        $running = app()->environment();
        if (! is_string($configured) || $configured !== $running) {
            throw new LegacyArchiveRefusal('environment_ambiguous');
        }
        if (in_array($running, self::LOCAL, true)) {
            return;
        }
        $admitted = config('legacy-archive.non_local_environments');
        if (! is_array($admitted) || ! in_array($running, $admitted, true)) {
            throw new LegacyArchiveRefusal('environment_refused');
        }
    }

    /** Synthetic fixtures are rehearsal data: they never enter a production archive. */
    public function admitSource(array $snapshot): void
    {
        if ($snapshot['acquisition_method'] === 'synthetic_fixture' && (app()->isProduction() || config('app.env') === 'production')) {
            throw new LegacyArchiveRefusal('synthetic_source_in_production');
        }
    }
}
