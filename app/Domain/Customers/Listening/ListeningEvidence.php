<?php

namespace App\Domain\Customers\Listening;

use App\Domain\Customers\CustomerAccessException;
use App\Domain\Customers\CustomerAccessPolicy;
use App\Domain\Customers\CustomerPrincipal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PDO;

/** Captured primary transaction proof; nothing here dispatches QueryExecuted callbacks. */
final class ListeningEvidence
{
    private Connection $connection;

    private PDO $primary;

    private string $driver;

    private string $database;

    private array $public = [];

    /** Fingerprint dependencies, never decide publication eligibility outside PublicCatalog. */
    public function capturePublic(array $ids): void
    {
        $this->public[] = ['ids' => $ids, 'proof' => $this->publicProof($ids)];
    }

    public function __construct()
    {
        $this->connection = DB::connection();
        $this->primary = $this->connection->getPdo();
        $this->driver = (string) $this->primary->getAttribute(PDO::ATTR_DRIVER_NAME);
        $this->database = $this->connection->getDatabaseName();
        if (! in_array($this->driver, ['sqlite', 'mysql'], true) || ! $this->primary->inTransaction()) {
            throw new ListeningException(503);
        }
    }

    public function prove(CustomerPrincipal $principal, ?array $expected): void
    {
        $this->assertCaptured();
        // Storage/configuration probes precede the final callback-free primary proof.
        foreach ($this->public as $public) {
            if ($public['proof'] !== $this->publicProof($public['ids'])) {
                throw new ListeningException(503);
            }
        }
        // Policy is pure and checked after the last framework callback, not before its queries.
        app(CustomerAccessPolicy::class)->requireEnabled();
        $this->assertCaptured();
        $user = $this->rows('users', 'id', $principal->userId);
        $account = $this->rows('customer_accounts', 'id', $principal->accountId);
        $key = config('app.key');
        if (count($user) !== 1 || count($account) !== 1 || ! is_string($key) || $key === '') {
            throw new CustomerAccessException;
        }
        $user = $user[0];
        $account = $account[0];
        $stamp = hash_hmac('sha256', "customer-credential-v1\0".$user['password'], $key);
        if ((int) $user['is_admin'] !== 0 || $user['email_verified_at'] === null || (int) $account['active'] !== 1
            || (int) $account['user_id'] !== $principal->userId || (int) $account['access_version'] !== $principal->accessVersion
            || ! hash_equals((string) $account['owner_key'], $principal->ownerKey) || ! hash_equals($stamp, $principal->credentialStamp)) {
            throw new CustomerAccessException;
        }
        $actual = $this->rows('customer_saved_tracks', 'customer_account_id', $principal->accountId);
        if (($expected === null && $actual !== []) || ($expected !== null
            && (count($actual) !== 1 || $this->strings($actual[0]) !== $this->strings($expected)))) {
            throw new ListeningException(503);
        }
        // Only the already-built pure projection and transaction commit follow this proof.
    }

    private function assertCaptured(): void
    {
        if (DB::connection() !== $this->connection || $this->connection->getPdo() !== $this->primary
            || $this->connection->getDatabaseName() !== $this->database || ! $this->primary->inTransaction()) {
            throw new ListeningException(503);
        }
    }

    private function rows(string $table, string $column, int $id): array
    {
        $qualified = $this->table($table);

        $statement = $this->primary->prepare('SELECT * FROM '.$qualified.' WHERE `'.$column.'`=? LIMIT 2'.($this->driver === 'mysql' ? ' FOR UPDATE' : ''));
        $statement->bindValue(1, $id, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function table(string $table): string
    {
        $qualified = $this->driver === 'sqlite' ? 'main."'.$table.'"' : '`'.str_replace('`', '``', $this->database).'`.`'.$table.'`';
        if ($this->driver === 'sqlite') {
            $statement = $this->primary->prepare('SELECT COUNT(*) FROM sqlite_temp_master WHERE lower(name)=?');
            $statement->execute([$table]);
            if ((int) $statement->fetchColumn() !== 0) {
                throw new ListeningException(503);
            }
        } else {
            $schema = (array) $this->primary->query('SHOW CREATE TABLE '.$qualified)->fetch(PDO::FETCH_ASSOC);
            if (str_contains(strtoupper((string) ($schema['Create Table'] ?? '')), 'CREATE TEMPORARY TABLE')) {
                throw new ListeningException(503);
            }
        }

        return $qualified;
    }

    private function publicProof(array $ids): array
    {
        $disk = Storage::disk('local');
        $at = CarbonImmutable::now();
        $proof = ['configuration' => [app()->environment(), config('app.timezone'), config('media'), config('commerce'), config('filesystems.disks.local')],
            'disk' => spl_object_id($disk), 'root' => $disk->path('')];
        $proof['tracks'] = $this->scope('tracks', 'id', $ids);
        $proof['rights'] = $this->scope('rights_declarations', 'track_id', $ids);
        $assets = $this->scope('media_assets', 'track_id', $ids);
        $runs = $this->scope('media_processing_runs', 'source_asset_id', array_column($assets, 'id'));
        $runs = $this->merge($runs, $this->scope('media_processing_runs', 'id', array_column($assets, 'processing_run_id')));
        $proof['runs'] = $runs;
        $assets = $this->merge($assets, $this->scope('media_assets', 'id', array_column($assets, 'parent_asset_id')));
        $proof['assets'] = $this->merge($assets, $this->scope('media_assets', 'processing_run_id', array_column($runs, 'id')));
        $proof['recordings'] = $this->scope('stems_recordings', 'track_id', $ids);
        $proof['offers'] = $this->scope('offers', 'track_id', $ids);
        $proof['revisions'] = $this->scope('offer_revisions', 'track_id', $ids);
        $proof['licenses'] = $this->scope('license_versions', 'id', array_column($proof['revisions'], 'license_version_id'));
        $active = array_column(array_filter($proof['offers'], fn ($offer) => (int) $offer['is_active'] === 1), 'current_revision_id');
        $currentLicenses = array_column(array_filter($proof['revisions'], fn ($revision) => in_array($revision['id'], $active, true)), 'license_version_id');
        $proof['effective'] = [];
        foreach ($proof['licenses'] as $license) {
            if (in_array($license['id'], $currentLicenses, true)) {
                $proof['effective'][$license['id']] = ($license['effective_from'] === null || $at->greaterThanOrEqualTo(CarbonImmutable::parse($license['effective_from'])))
                    && ($license['effective_until'] === null || $at->lessThan(CarbonImmutable::parse($license['effective_until'])));
            }
        }
        $proof['templates'] = $this->scope('license_templates', 'id', array_column($proof['licenses'], 'license_template_id'));
        $proof['reviews'] = $this->scope('license_review_evidence', 'license_version_id', array_column($proof['licenses'], 'id'));
        $proof['links'] = $this->scope('rights_scope_offers', 'offer_revision_id', array_column($proof['revisions'], 'id'));
        $scopes = array_column($proof['links'], 'rights_scope_id');
        $proof['scopes'] = $this->scope('rights_scopes', 'id', $scopes);
        $proof['activations'] = $this->scope('exclusive_activations', 'rights_scope_id', $scopes);
        $proof['sales'] = $this->scope('exclusive_sales', 'rights_scope_id', $scopes);
        $proof['claims'] = $this->scope('inventory_claims', 'rights_scope_id', $scopes);
        $proof['reservations'] = $this->scope('inventory_reservations', 'id', array_column($proof['claims'], 'inventory_reservation_id'));
        $proof['files'] = [];
        foreach ($proof['assets'] as $asset) {
            if ($asset['disk'] === 'local' && $asset['status'] === 'ready'
                && preg_match('~\Amedia/revisions/[a-f0-9-]{36}/(?:master\.wav|delivery\.mp3|preview\.mp3|artwork\.png|stems\.zip)\z~D', $asset['storage_path'])) {
                $path = rtrim($proof['root'], '/');
                // Directory identities and links count too; atime changes from reading do not.
                foreach (['', ...explode('/', $asset['storage_path'])] as $component) {
                    if ($component !== '') {
                        $path .= '/'.$component;
                    }
                    clearstatcache(true, $path);
                    $stat = @lstat($path);
                    $proof['files'][$path] = $stat === false ? false : array_intersect_key($stat,
                        array_flip(['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'rdev', 'size', 'mtime', 'ctime']));
                }
            }
        }
        ksort($proof['files']);

        return $proof;
    }

    /** Closed ID sets only; heavy history fails closed instead of scanning the catalog. */
    private function scope(string $table, string $column, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, fn ($id) => $id !== null)));
        if ($ids === []) {
            return [];
        }
        if (count($ids) > 256) {
            throw new ListeningException(503);
        }
        $statement = $this->primary->prepare('SELECT * FROM '.$this->table($table).' WHERE `'.$column.'` IN ('
            .implode(',', array_fill(0, count($ids), '?')).') ORDER BY id LIMIT 257'.($this->driver === 'mysql' ? ' FOR UPDATE' : ''));
        foreach ($ids as $index => $id) {
            $statement->bindValue($index + 1, (int) $id, PDO::PARAM_INT);
        }
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 256) {
            throw new ListeningException(503);
        }

        return array_map($this->strings(...), $rows);
    }

    private function merge(array $a, array $b): array
    {
        $rows = [];
        foreach ([...$a, ...$b] as $row) {
            $rows[(int) $row['id']] = $row;
        }
        if (count($rows) > 256) {
            throw new ListeningException(503);
        }
        ksort($rows);

        return array_values($rows);
    }

    private function strings(array $row): array
    {
        $row = array_map(fn ($value) => $value === null ? null : (string) $value, $row);
        ksort($row);

        return $row;
    }
}
