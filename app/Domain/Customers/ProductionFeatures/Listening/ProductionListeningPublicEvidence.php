<?php

namespace App\Domain\Customers\ProductionFeatures\Listening;

use App\Domain\Customers\Listening\ListeningException;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureConfiguration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use PDO;

/** Production-only bounded publication fingerprint; PublicCatalog alone decides eligibility.
 * Pure source copied from main9f ListeningEvidence, with separate current/committed closure.
 */
final class ProductionListeningPublicEvidence
{
    private array $proofs = [];

    private array $scopes = [];

    private bool $capturing = true;

    private bool $committed = false;

    public function __construct(private readonly PDO $primary, private readonly string $driver, private readonly string $database,
        private readonly ProductionFeatureConfiguration $configuration) {}

    public function capturePublic(array $ids): void
    {
        $this->proofs[] = ['ids' => $ids, 'proof' => $this->publicProof($ids)];
    }

    public function prove(bool $committed): void
    {
        $this->capturing = false;
        $this->committed = $committed;
        // All extensible storage/clock probes first, then closure of every earlier scope.
        foreach ($this->proofs as $capture) {
            if ($capture['proof'] !== $this->publicProof($capture['ids'])) {
                throw new ListeningException(503);
            }
        }
        $this->close($committed);
    }

    public function close(bool $committed): void
    {
        $this->configuration->admit();
        ProductionFeatureConfiguration::plainPrimary($this->primary);
        $this->capturing = false;
        $this->committed = $committed;
        foreach ($this->scopes as [$table, $column, $ids, $expected]) {
            if ($this->scope($table, $column, $ids) !== $expected) {
                throw new ListeningException(503);
            }
        }
        foreach ($this->proofs as $capture) {
            $proof = $capture['proof'];
            if ($proof['configuration'] !== $this->configurationSnapshot()) {
                throw new ListeningException(503);
            }
            foreach ($proof['files'] as $path => $expected) {
                clearstatcache(true, $path);
                $stat = @lstat($path);
                $actual = $stat === false ? false : array_intersect_key($stat, array_flip(['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'rdev', 'size', 'mtime', 'ctime']));
                if ($actual !== $expected) {
                    throw new ListeningException(503);
                }
            }
        }
    }

    private function table(string $table): string
    {
        $allowed = ['tracks', 'rights_declarations', 'media_assets', 'media_processing_runs', 'stems_recordings', 'offers', 'offer_revisions', 'license_versions', 'license_templates', 'license_review_evidence', 'rights_scope_offers', 'rights_scopes', 'exclusive_activations', 'exclusive_sales', 'inventory_claims', 'inventory_reservations'];
        if (! in_array($table, $allowed, true)) {
            throw new ListeningException(503);
        }
        $qualified = $this->driver === 'sqlite' ? 'main."'.$table.'"' : '`'.str_replace('`', '``', $this->database).'`.`'.$table.'`';
        if ($this->driver === 'sqlite') {
            $statement = $this->primary->prepare('SELECT COUNT(*) FROM sqlite_temp_master WHERE name COLLATE NOCASE=?');
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
        $this->configuration->admit();
        ProductionFeatureConfiguration::plainPrimary($this->primary);
        $disk = Storage::disk('local');
        $at = CarbonImmutable::now();
        $this->configuration->admit();
        $proof = ['configuration' => $this->configurationSnapshot(),
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

    private function configurationSnapshot(): array
    {
        $app = $this->configuration->snapshot('app');
        $filesystems = $this->configuration->snapshot('filesystems');
        if (! is_array($filesystems['disks'] ?? null) || ! is_array($filesystems['disks']['local'] ?? null)) {
            throw new ListeningException(503);
        }

        return [$this->configuration->applicationEnvironment(), $app['timezone'],
            $this->configuration->snapshot('media'), $this->configuration->snapshot('commerce'), $filesystems['disks']['local']];
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
            .implode(',', array_fill(0, count($ids), '?')).') ORDER BY id LIMIT 257'.($this->driver === 'mysql' && ! $this->committed ? ' FOR UPDATE' : ''));
        foreach ($ids as $index => $id) {
            $statement->bindValue($index + 1, (int) $id, PDO::PARAM_INT);
        }
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 256) {
            throw new ListeningException(503);
        }

        $result = array_map($this->strings(...), $rows);
        $key = json_encode([$table, $column, $ids], JSON_THROW_ON_ERROR);
        if ($this->capturing) {
            if (isset($this->scopes[$key]) && $this->scopes[$key][3] !== $result) {
                throw new ListeningException(503);
            }
            $this->scopes[$key] = [$table, $column, $ids, $result];
        }

        return $result;
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
