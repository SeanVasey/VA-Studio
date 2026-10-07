<?php

namespace App\Domain\Commerce\ProductionCheckout;

use App\Domain\Commerce\ProductionPolicy\CapabilityHistory;
use App\Domain\Commerce\ProductionPolicy\SourceCommitment;
use Illuminate\Support\Facades\DB;
use PDO;

/** Callback-free captured writer and permanent-table fence for the exact checkout/source/catalog graph. */
final class PrimaryBoundary
{
    public static function tables(): array
    {
        return [...array_values(CheckoutSchema::TABLES), 'users', 'audit_events', 'customer_accounts',
            SourceCommitment::DRAFTS, SourceCommitment::VERSIONS, SourceCommitment::REVIEWS,
            CapabilityHistory::CANDIDATES, CapabilityHistory::APPROVALS, CapabilityHistory::CLOSURES,
            'tracks', 'offers', 'offer_revisions', 'rights_declarations', 'media_assets', 'stems_recordings',
            'license_versions', 'license_templates', 'license_review_evidence', 'media_processing_runs', 'rights_scope_offers'];
    }

    public static function prove(PDO $primary, string $driver): void
    {
        $connection = DB::connection();
        CheckoutException::require($connection->getRawPdo() === $primary && $connection->getDriverName() === $driver
            && in_array($driver, ['sqlite', 'mysql'], true), 'primary_changed');
        $tables = self::tables();
        if ($driver === 'sqlite') {
            $temporary = $primary->query('SELECT name, tbl_name FROM sqlite_temp_master')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($temporary as $object) {
                CheckoutException::require(! in_array(strtolower($object['name']), $tables, true)
                    && ! in_array(strtolower($object['tbl_name']), $tables, true), 'temporary_shadow');
            }
            $statement = $primary->prepare('SELECT name, type FROM main.sqlite_master WHERE name IN ('.implode(', ', array_fill(0, count($tables), '?')).')');
            $statement->execute($tables);
            $objects = $statement->fetchAll(PDO::FETCH_KEY_PAIR);
            CheckoutException::require(count($objects) === count($tables) && count(array_filter($objects, fn (string $type): bool => $type === 'table')) === count($tables), 'permanent_schema');
        } else {
            $database = $primary->query('SELECT DATABASE()')->fetchColumn();
            CheckoutException::require(is_string($database) && $database === $connection->getDatabaseName(), 'primary_schema');
            // MySQL database qualification still resolves connection-local temporary tables.
            // SHOW CREATE reveals that physical identity; do not accept a qualified temporary clone.
            $schema = '`'.str_replace('`', '``', $database).'`';
            foreach ($tables as $table) {
                $definition = $primary->query('SHOW CREATE TABLE '.$schema.'.`'.$table.'`')->fetch(PDO::FETCH_NUM);
                CheckoutException::require(is_array($definition) && is_string($definition[1] ?? null)
                    && str_starts_with($definition[1], 'CREATE TABLE '), 'temporary_or_foreign_schema');
            }
        }
        CheckoutException::require(DB::connection() === $connection && $connection->getRawPdo() === $primary, 'primary_changed');
    }
}
