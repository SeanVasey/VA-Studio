<?php

namespace Tests\Support;

use App\Domain\Migration\History\ExclusivityAuthority;
use App\Domain\Migration\History\LegacyGrantReview;
use App\Domain\Migration\History\LegacyHistoryArchive;
use App\Domain\Staff\StaffWriteAuthority;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Private copies of the synthetic history packages (tests/Fixtures/migration/history-synthetic) laid out exactly as
 * the private-source boundary requires: a caller-owned 0700 directory inside another 0700 directory under the
 * system temporary directory, 0600 single-link files and a 0700 `contracts/` directory. Synthetic data only.
 */
final class LegacyHistoryFixtures
{
    /** @var list<string> */
    private static array $created = [];

    /**
     * @param  callable(array{mapping: string, customers: string, orders: string, contracts: string, files: array<string, string>}): array|null  $change
     */
    public static function privateCopy(string $package, ?callable $change = null): string
    {
        $fixture = dirname(__DIR__).'/Fixtures/migration/history-synthetic/'.$package;
        $parts = ['mapping' => file_get_contents($fixture.'/mapping.json'), 'customers' => file_get_contents($fixture.'/customers.csv'),
            'orders' => file_get_contents($fixture.'/orders.csv'), 'contracts' => file_get_contents($fixture.'/contracts.csv'), 'files' => []];
        foreach (glob($fixture.'/contracts/*') as $file) {
            $parts['files'][basename($file)] = file_get_contents($file);
        }
        if ($change !== null) {
            $parts = $change($parts);
        }
        $base = sys_get_temp_dir().'/vasey-history-'.bin2hex(random_bytes(8));
        $previous = umask(0077);
        try {
            mkdir($base, 0700);
            self::$created[] = $base;
            $source = $base.'/source';
            mkdir($source, 0700);
            mkdir($source.'/contracts', 0700);
            foreach (['mapping' => 'mapping.json', 'customers' => 'customers.csv', 'orders' => 'orders.csv', 'contracts' => 'contracts.csv'] as $key => $name) {
                file_put_contents($source.'/'.$name, $parts[$key]);
                chmod($source.'/'.$name, 0600);
            }
            foreach ($parts['files'] as $name => $bytes) {
                file_put_contents($source.'/contracts/'.$name, $bytes);
                chmod($source.'/contracts/'.$name, 0600);
            }
        } finally {
            umask($previous);
        }
        chmod($base, 0700);
        chmod($source, 0700);
        chmod($source.'/contracts', 0700);

        return $source;
    }

    /** A private operator file (for example delta dispositions) in its own 0700 directory. */
    public static function privateFile(string $bytes): string
    {
        $base = sys_get_temp_dir().'/vasey-history-'.bin2hex(random_bytes(8));
        mkdir($base, 0700);
        self::$created[] = $base;
        mkdir($base.'/private', 0700);
        file_put_contents($base.'/private/file.json', $bytes);
        chmod($base.'/private/file.json', 0600);

        return $base.'/private/file.json';
    }

    /** A console attestation exactly as the commands record it (one per write; each write spends it). */
    public static function attestation(User $actor, string $action, string $scope, string $reference): StaffWriteAuthority
    {
        return StaffWriteAuthority::attest($actor, 'vasey:legacy-history', $action, $scope, null, $reference);
    }

    public static function import(array $snapshot, string $sha, array $files, User $actor, int $limit, ?callable $afterRecord = null): array
    {
        return (new LegacyHistoryArchive)->import($snapshot, $sha, $files, $actor,
            self::attestation($actor, LegacyHistoryArchive::ATTESTATION_ACTION, LegacyHistoryArchive::attestationScope($sha), $sha), $limit, $afterRecord);
    }

    public static function review(LegacyGrantReview $review, string $line, User $reviewer, string $reference, bool $write): array
    {
        return $review->review($line, $reviewer, $reference, $write
            ? self::attestation($reviewer, LegacyGrantReview::ATTESTATION_ACTION, LegacyGrantReview::attestationScope($line), $reference) : null, $write);
    }

    public static function transition(ExclusivityAuthority $authority, string $transition, User $actor, string $reference, bool $write, ?array $handover = null): array
    {
        return $authority->transition($transition, $actor, $reference, $write
            ? self::attestation($actor, ExclusivityAuthority::attestationAction($transition), ExclusivityAuthority::ATTESTATION_SCOPE, $reference) : null,
            $write, $handover);
    }

    public static function cleanup(): void
    {
        foreach (self::$created as $base) {
            self::remove($base);
        }
        self::$created = [];
    }

    /**
     * Every row of every table except the legacy archive and the audit log, so a test can prove the importer, the
     * grant review and the authority ledger write nothing else (no grant, entitlement, order, customer or consent).
     *
     * @return array<string, string>
     */
    public static function nonArchiveState(): array
    {
        $state = [];
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            if (str_starts_with($table, 'legacy_') || $table === 'audit_events' || $table === 'migrations' || str_starts_with($table, 'sqlite_')) {
                continue;
            }
            $state[$table] = DB::table($table)->get()->toJson();
        }
        ksort($state);

        return $state;
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @chmod($path, 0600);
            unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }
        chmod($path, 0700);
        foreach (scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::remove($path.'/'.$entry);
            }
        }
        rmdir($path);
    }
}
