<?php

namespace App\Domain\Memberships\Production;

use App\Domain\Customers\ProductionIdentity\CatalogLease;
use App\Domain\Customers\ProductionIdentity\CatalogRead;
use PDO;

/** Per-inspection batches; the closed enum compiler runs on the current catalog companion. */
final class MembershipSchemaCatalog
{
    private array $batches = [];

    public function __construct(private readonly PDO $pdo) {}

    public function named(CatalogRead $sql, array $names, string $name): array
    {
        return $this->rows($sql, array_combine($names, array_map(fn (string $value): array => [$value], $names)), $name);
    }

    public function rows(CatalogRead $sql, array $parameters, string $name): array
    {
        $key = $sql->value."\0".json_encode($parameters, JSON_THROW_ON_ERROR);
        $this->batches[$key] ??= CatalogLease::membership($this->pdo, $sql, $parameters, $name);

        return $this->batches[$key][$name];
    }
}
