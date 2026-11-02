<?php

namespace App\Domain\Customers\ProductionIdentity;

use LogicException;
use PDO;
use ReflectionClass;
use Throwable;
use WeakMap;
use WeakReference;

/** Only explicit operation/frame leases retain a companion; the registry owns no strong lifetime. */
final class CatalogLease
{
    /** @var WeakMap<PDO,WeakReference<CurrentCatalog>>|null */
    private static ?WeakMap $active = null;

    private bool $closed = false;

    private function __construct(private ?CurrentCatalog $catalog, private readonly bool $boundary, private readonly ?PDO $primary = null) {}

    public static function open(PDO $primary, bool $boundary = true): self
    {
        if (($primary::class !== PDO::class && $primary::class !== 'Pdo\\Mysql' && $primary::class !== 'Pdo\\Sqlite') || ! (new ReflectionClass($primary))->isInternal()) {
            throw new LogicException('Native captured primary required.');
        }
        $driver = $primary->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver !== 'mysql' && $driver !== 'sqlite') {
            throw new LogicException('Supported captured primary required.');
        }
        if ($driver === 'sqlite') {
            return new self(null, false);
        }
        CatalogSocketBinding::requireUntainted();
        self::$active ??= new WeakMap;
        $catalog = isset(self::$active[$primary]) ? self::$active[$primary]->get() : null;
        if (! $catalog instanceof CurrentCatalog) {
            $catalog = CurrentCatalog::capture($primary);
            self::$active[$primary] = WeakReference::create($catalog);
        }
        try {
            if ($boundary) {
                $catalog->enter();
            }
            $catalog->retain();
        } catch (Throwable $error) {
            if (! $catalog->hasHolders()) {
                $catalog->invalidate();
                unset(self::$active[$primary]);
            }
            throw $error;
        }

        return new self($catalog, $boundary, $primary);
    }

    public static function read(PDO $primary, CatalogRead $query, array $bindings = []): array
    {
        $lease = self::open($primary);
        try {
            return $lease->catalog?->rows($query, $bindings) ?? throw new LogicException('Native catalog required.');
        } finally {
            $lease->close();
        }
    }

    public static function group(PDO $primary, CatalogGroup $group, string $version = AccountAdmissionGuards::V2): array
    {
        $lease = self::open($primary);
        try {
            return $lease->catalog?->group($group, $version) ?? throw new LogicException('Native catalog required.');
        } finally {
            $lease->close();
        }
    }

    public static function identity(PDO $primary, CatalogRead $query, array $names): array
    {
        $lease = self::open($primary);
        try {
            return $lease->catalog?->identity($query, $names) ?? throw new LogicException('Native catalog required.');
        } finally {
            $lease->close();
        }
    }

    public static function membership(PDO $primary, CatalogRead $query, array $parameters, string $name): array
    {
        $lease = self::open($primary);
        try {
            return $lease->catalog?->membership($query, $parameters, $name) ?? throw new LogicException('Native catalog required.');
        } finally {
            $lease->close();
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $catalog = $this->catalog;
        $this->catalog = null;
        try {
            if ($this->boundary) {
                $catalog?->leave();
            }
        } finally {
            $this->release($catalog);
        }
    }

    private function release(?CurrentCatalog $catalog): void
    {
        if ($catalog !== null && $catalog->release() && $this->primary !== null && self::$active !== null
            && isset(self::$active[$this->primary]) && self::$active[$this->primary]->get() === $catalog) {
            unset(self::$active[$this->primary]);
        }
    }

    public function __destruct()
    {
        // An abandoned boundary cannot suppress subsequent live checks in another retained lease.
        if (! $this->closed) {
            if ($this->boundary) {
                $this->catalog?->invalidate();
            }
            $this->release($this->catalog);
        }
        $this->catalog = null;
    }

    public function __serialize(): never
    {
        throw new LogicException('Private catalog lease.');
    }

    public function __debugInfo(): array
    {
        return ['catalog' => 'private'];
    }

    private function __clone() {}
}
