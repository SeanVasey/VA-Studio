<?php

namespace App\Console\Commands;

use App\Support\Diagnostics\CutoverLedgerChecks;
use Illuminate\Console\Command;

final class CutoverCheck extends Command
{
    protected $signature = 'vasey:cutover-check
        {--expect= : Which states count as PASS: production (placeholders fail) or staging (placeholders are expected). Defaults to production when APP_ENV is production, otherwise staging}
        {--json : Emit the rows as JSON}';

    protected $description = 'Read-only check of the production cleanup ledger: one PASS, FAIL, INFO or NOT_CHECKABLE line per ledger id, without writing anything or contacting a provider';

    public function handle(CutoverLedgerChecks $checks): int
    {
        $environment = (string) config('app.env');
        $profile = $this->option('expect') ?? ($environment === 'production' ? CutoverLedgerChecks::PRODUCTION : CutoverLedgerChecks::STAGING);
        if (! is_string($profile) || ! in_array($profile, CutoverLedgerChecks::PROFILES, true)) {
            $this->error('--expect must be one of: '.implode(', ', CutoverLedgerChecks::PROFILES).'.');

            return 2;
        }
        $rows = $checks->run($profile);
        $counts = array_count_values(array_column($rows, 'status')) + [CutoverLedgerChecks::FAIL => 0, CutoverLedgerChecks::PASS => 0,
            CutoverLedgerChecks::INFO => 0, CutoverLedgerChecks::NOT_CHECKABLE => 0];
        // The environment is printed only when it is one of the known names; anything else is a host-chosen string.
        $environment = in_array($environment, ['production', 'staging', 'local', 'testing'], true) ? $environment : 'other';
        if ($this->option('json')) {
            $this->line(json_encode(['ledger' => 'docs/production-cleanup-ledger.md', 'profile' => $profile, 'environment' => $environment,
                'counts' => $counts, 'rows' => $rows], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line('Production cleanup ledger check. Profile: '.$profile.'; APP_ENV: '.$environment
                .($profile === $environment || ($profile === CutoverLedgerChecks::STAGING && $environment !== 'production') ? '' : ' (the profile differs from the environment)').'.');
            foreach ($rows as $row) {
                // With several findings each carries its own state, so a failing row shows which part failed.
                $several = count($row['findings']) > 1;
                $findings = array_map(fn (array $finding): string => ($several ? $finding['status'].' ' : '').$finding['detail'], $row['findings']);
                $this->line(sprintf('%-6s %-13s %s%s', $row['id'], $row['status'], $row['subject'], $findings === [] ? '' : ': '.implode('; ', $findings)));
                if ($row['manual'] !== null) {
                    $this->line('       by hand: '.$row['manual']);
                }
            }
            $this->line(sprintf('FAIL=%d PASS=%d INFO=%d NOT_CHECKABLE=%d', $counts[CutoverLedgerChecks::FAIL], $counts[CutoverLedgerChecks::PASS],
                $counts[CutoverLedgerChecks::INFO], $counts[CutoverLedgerChecks::NOT_CHECKABLE]));
            $this->line('Read-only: nothing was written and no provider was contacted. PASS covers only what configuration, the database and the '
                .'filesystem show; the "by hand" lines and every NOT_CHECKABLE entry still need their ledger evidence. INFO states a switch and judges '
                .'nothing. A clean run closes no ledger entry and authorizes no activation.');
        }

        return $counts[CutoverLedgerChecks::FAIL] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
