<?php

namespace App\Domain\Customers\ProductionFeatures;

use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Collection;
use ReflectionProperty;

/** Callback-free removal of the captured original scoped root; never a foreign manager sweep. */
final class ProductionFeatureBookkeeping
{
    private function __construct(private readonly DatabaseTransactionsManager $manager,
        private readonly DatabaseTransactionRecord $root, private readonly string $name) {}

    public static function capture(DatabaseTransactionsManager $manager, string $name): self
    {
        $current = self::property('currentTransaction')->getRawValue($manager);
        $root = is_array($current) ? ($current[$name] ?? null) : null;
        if (! $root instanceof DatabaseTransactionRecord || $root::class !== DatabaseTransactionRecord::class
            || $root->connection !== $name || $root->level !== 1 || $root->parent !== null) {
            throw new ProductionFeatureException;
        }

        return new self($manager, $root, $name);
    }

    public function discard(): void
    {
        $collections = [];
        $owned = [spl_object_id($this->root) => $this->root];
        foreach (['pendingTransactions', 'committedTransactions'] as $key) {
            $collection = self::property($key)->getRawValue($this->manager);
            if (! $collection instanceof Collection || $collection::class !== Collection::class) {
                throw new ProductionFeatureException;
            }
            $items = (new ReflectionProperty(Collection::class, 'items'))->getRawValue($collection);
            if (! is_array($items) || count($items) > 512) {
                throw new ProductionFeatureException;
            }
            foreach ($items as $record) {
                if ($this->owns($record)) {
                    $owned[spl_object_id($record)] = $record;
                }
            }
            $collections[] = [$collection, $items];
        }
        $current = self::property('currentTransaction')->getRawValue($this->manager);
        if (! is_array($current)) {
            throw new ProductionFeatureException;
        }
        foreach ($current as $key => $record) {
            if ($this->owns($record)) {
                $owned[spl_object_id($record)] = $record;
                $current[$key] = null;
            }
        }
        // Clear authenticated records too: an unrelated reopened root may retain a stale parent.
        foreach ($owned as $record) {
            foreach (['callbacks', 'callbacksForRollback'] as $key) {
                (new ReflectionProperty(DatabaseTransactionRecord::class, $key))->setValue($record, []);
            }
        }
        foreach ($collections as [$collection, $items]) {
            (new ReflectionProperty(Collection::class, 'items'))->setValue($collection,
                array_filter($items, fn ($record) => ! is_object($record) || ! isset($owned[spl_object_id($record)])));
        }
        self::property('currentTransaction')->setValue($this->manager, $current);
    }

    private function owns(mixed $record): bool
    {
        $previousLevel = PHP_INT_MAX;
        for ($depth = 0; $depth < 64; $depth++) {
            if ($record === $this->root) {
                return true;
            }
            if (! $record instanceof DatabaseTransactionRecord || $record::class !== DatabaseTransactionRecord::class
                || $record->connection !== $this->name || ! is_int($record->level)
                || $record->level <= 1 || $record->level >= $previousLevel) {
                return false;
            }
            $previousLevel = $record->level;
            $record = $record->parent;
        }

        return false;
    }

    private static function property(string $name): ReflectionProperty
    {
        return new ReflectionProperty(DatabaseTransactionsManager::class, $name);
    }
}
