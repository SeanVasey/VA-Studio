<?php

namespace App\Domain\Customers\ProductionFeatures\Suppression;

use App\Domain\Customers\ProductionFeatures\ProductionFeatureConfiguration;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureContext;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureException;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureOperation;
use App\Support\CanonicalJson;
use PDO;
use Throwable;

/** Closed raw-PDO scopes over the owned 254 tables, held by a live sealed 253 operation.
 * Every observed scope is re-read and compared by the context's committing and postcommit guards.
 */
final class ProductionSuppressionRows
{
    public const LIMIT = 8;

    private const COLUMNS = [
        'production_suppression_targets' => ['id', 'binding_id', 'public_id'],
        'production_suppression_intents' => ['id', 'target_id', 'withdrawal_event_id'],
        'production_suppression_attempts' => ['id', 'target_id', 'intent_id'],
        'production_suppression_confirmations' => ['id', 'attempt_id'],
    ];

    private array $scopes = [];

    public function __construct(private readonly ProductionFeatureContext $context)
    {
        // Only a context minted by run() and still inside its callback (review condition C2).
        ProductionFeatureOperation::assertLive($context);
        $this->schema();
        $context->guard(fn (bool $committed) => $this->prove($committed));
    }

    /** Snapshot a bounded scope. Observe again only after an intended, independently checked write. */
    public function observe(string $table, array $where, int $limit = 1): array
    {
        ProductionFeatureOperation::assertLive($this->context);
        $rows = $this->rows($table, $where, $limit, false);
        $this->scopes[CanonicalJson::encode([$table, $where, $limit])] = [$table, $where, $limit, $rows];

        return $rows;
    }

    /** Callback-free append on the held primary; the caller re-observes and fences the exact bytes. */
    public function insert(string $table, array $attributes): void
    {
        ProductionFeatureOperation::assertLive($this->context);
        if (! isset(self::COLUMNS[$table]) || $attributes === []) {
            throw new ProductionFeatureException;
        }
        [$primary] = $this->context->heldStorage();
        ProductionFeatureConfiguration::plainPrimary($primary);
        $columns = implode(',', array_map(fn ($column) => '`'.$column.'`', array_keys($attributes)));
        try {
            $statement = $primary->prepare('INSERT INTO '.$this->qualified($table).' ('.$columns.') VALUES ('.implode(',', array_fill(0, count($attributes), '?')).')');
            $statement->execute(array_values($attributes));
        } catch (Throwable) {
            throw new ProductionFeatureException;
        }
    }

    private function prove(bool $committed): void
    {
        $this->schema();
        foreach ($this->scopes as [$table, $where, $limit, $expected]) {
            if ($this->rows($table, $where, $limit, $committed) !== $expected) {
                throw new ProductionFeatureException;
            }
        }
    }

    private function schema(): void
    {
        [$primary, $driver, $database] = $this->context->heldStorage();
        ProductionFeatureConfiguration::plainPrimary($primary);
        try {
            (new ProductionSuppressionSchema)->assertHeld($primary, $driver, $database);
        } catch (Throwable) {
            throw new ProductionFeatureException;
        }
    }

    private function rows(string $table, array $where, int $limit, bool $committed): array
    {
        $columns = self::COLUMNS[$table] ?? throw new ProductionFeatureException;
        if ($where === [] || $limit < 1 || $limit > self::LIMIT || array_diff(array_keys($where), $columns) !== []) {
            throw new ProductionFeatureException;
        }
        [$primary, $driver] = $this->context->heldStorage();
        ProductionFeatureConfiguration::plainPrimary($primary);
        $condition = implode(' AND ', array_map(fn ($column) => '`'.$column.'`=?', array_keys($where)));
        $statement = $primary->prepare('SELECT * FROM '.$this->qualified($table).' WHERE '.$condition.' ORDER BY id ASC LIMIT '.($limit + 1)
            .($driver === 'mysql' && ! $committed ? ' FOR UPDATE' : ''));
        $statement->execute(array_values($where));
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > $limit) {
            throw new ProductionFeatureException;
        }

        return $rows;
    }

    private function qualified(string $table): string
    {
        [, $driver, $database] = $this->context->heldStorage();

        return $driver === 'sqlite' ? 'main."'.$table.'"' : '`'.str_replace('`', '``', $database).'`.`'.$table.'`';
    }
}
