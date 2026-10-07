<?php

namespace App\Domain\Customers\ProductionFeatures;

use Closure;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PDO;
use Pdo\Mysql;
use Pdo\Sqlite;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionProperty;

/** Direct raw admission before nested configuration lookups; never traverses ArrayAccess. */
final readonly class ProductionFeatureConfiguration
{
    private const PARENTS = ['app', 'database', 'production-customer-identity', 'production-account-features',
        'production-customer-listening', 'production-customer-preferences', 'media', 'commerce', 'filesystems'];

    private Container $application;

    private Repository $repository;

    private ReflectionProperty $instances;

    private ReflectionProperty $items;

    public function __construct()
    {
        $this->application = Container::getInstance();
        $repository = $this->application->make('config');
        if (! $repository instanceof Repository || get_class($repository) !== Repository::class) {
            throw new ProductionFeatureException;
        }
        $this->repository = $repository;
        $this->instances = new ReflectionProperty(Container::class, 'instances');
        $this->items = new ReflectionProperty(Repository::class, 'items');
        $this->admit();
    }

    public function admit(): void
    {
        $instances = $this->instances->getValue($this->application);
        if (Container::getInstance() !== $this->application || ! is_array($instances)
            || ($instances['config'] ?? null) !== $this->repository) {
            throw new ProductionFeatureException;
        }
        $this->rawEnvironment($instances);
        $items = $this->items->getValue($this->repository);
        if (! is_array($items)) {
            throw new ProductionFeatureException;
        }
        $remaining = 4096;
        foreach (self::PARENTS as $parent) {
            if (! isset($items[$parent]) || ! is_array($items[$parent])) {
                throw new ProductionFeatureException;
            }
            $this->plain($items[$parent], $remaining);
        }
        foreach (['env', 'key', 'cipher', 'timezone'] as $key) {
            if (! is_string($items['app'][$key] ?? null) || $items['app'][$key] === '') {
                throw new ProductionFeatureException;
            }
        }
        if (! is_string($items['database']['default'] ?? null) || ! is_array($items['database']['connections'] ?? null)) {
            throw new ProductionFeatureException;
        }
    }

    /** Detach all PHP references; a live scalar reference must not rewrite captured evidence. */
    public function snapshot(string $parent): array
    {
        $this->admit();
        if (! in_array($parent, self::PARENTS, true)) {
            throw new ProductionFeatureException;
        }

        return $this->copy($this->items->getValue($this->repository)[$parent]);
    }

    public function applicationEnvironment(): string
    {
        $this->admit();

        return $this->rawEnvironment($this->instances->getValue($this->application));
    }

    /** Laravel offsetSet wraps a scalar in its own closure; read that scalar without resolution. */
    private function rawEnvironment(array $instances): string
    {
        foreach (['globalBeforeResolvingCallbacks', 'globalResolvingCallbacks', 'globalAfterResolvingCallbacks'] as $property) {
            if ((new ReflectionProperty(Container::class, $property))->getValue($this->application) !== []) {
                throw new ProductionFeatureException;
            }
        }
        foreach (['aliases', 'beforeResolvingCallbacks', 'resolvingCallbacks', 'afterResolvingCallbacks', 'extenders'] as $property) {
            $values = (new ReflectionProperty(Container::class, $property))->getValue($this->application);
            if (! is_array($values) || isset($values['env']) || isset($values['config'])) {
                throw new ProductionFeatureException;
            }
        }
        if (array_key_exists('env', $instances)) {
            $environment = $instances['env'];
        } else {
            $bindings = (new ReflectionProperty(Container::class, 'bindings'))->getValue($this->application);
            if (! is_array($bindings) || ! is_array($bindings['env'] ?? null)
                || ! ($bindings['env']['concrete'] ?? null) instanceof Closure) {
                throw new ProductionFeatureException;
            }
            $resolver = new ReflectionFunction($bindings['env']['concrete']);
            $method = new ReflectionMethod(Container::class, 'offsetSet');
            $variables = $resolver->getStaticVariables();
            if ($resolver->getFileName() !== $method->getFileName() || $resolver->getStartLine() < $method->getStartLine()
                || $resolver->getEndLine() > $method->getEndLine() || $resolver->getClosureThis() !== $this->application
                || array_keys($variables) !== ['value']) {
                throw new ProductionFeatureException;
            }
            $environment = $variables['value'];
        }
        if (! is_string($environment) || $environment === '') {
            throw new ProductionFeatureException;
        }

        return $environment;
    }

    private function copy(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $snapshot = [];
        foreach ($value as $key => $child) {
            $snapshot[$key] = $this->copy($child);
        }

        return $snapshot;
    }

    /** PDO subclasses and custom statements can execute callbacks after a raw terminal fetch. */
    public static function plainPrimary(PDO $primary): void
    {
        // PHP8.4's built-in driver classes are concrete internal PDO sources, too.
        if (! in_array(get_class($primary), [PDO::class, Sqlite::class, Mysql::class], true)
            || $primary->getAttribute(PDO::ATTR_STATEMENT_CLASS) !== [\PDOStatement::class]) {
            throw new ProductionFeatureException;
        }
    }

    private function plain(mixed $value, int &$remaining, int $depth = 0): void
    {
        if (--$remaining < 0 || $depth > 32) {
            throw new ProductionFeatureException;
        }
        if (is_array($value)) {
            foreach ($value as $child) {
                $this->plain($child, $remaining, $depth + 1);
            }
        } elseif (! is_null($value) && ! is_string($value) && ! is_int($value) && ! is_bool($value)
            && (! is_float($value) || ! is_finite($value))) {
            throw new ProductionFeatureException;
        }
    }
}
