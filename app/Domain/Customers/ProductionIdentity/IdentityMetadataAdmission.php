<?php

namespace App\Domain\Customers\ProductionIdentity;

use App\Models\User;
use Closure;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use PDO;
use PDOStatement;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionProperty;

/** Raw metadata admission only: no identity, SQL, frame, resolver or renewed deadline. */
final class IdentityMetadataAdmission
{
    private function __construct(private readonly array $captured) {}

    public static function capture(?PDO $ownedPrimary = null): self
    {
        return new self(self::inspect($ownedPrimary));
    }

    public static function admit(?PDO $ownedPrimary = null): void
    {
        self::inspect($ownedPrimary);
    }

    public function connection(): Connection
    {
        return $this->captured['connections'][$this->captured['default']];
    }

    public function requireCurrent(?PDO $ownedPrimary = null): void
    {
        if (self::inspect($ownedPrimary) !== $this->captured) {
            throw new IdentityException;
        }
    }

    /** Avoid Eloquent ID getters/casts even after canonical metadata has been admitted. */
    public static function actorId(User $actor): int
    {
        if ($actor::class !== User::class || self::raw($actor, Model::class, 'exists') !== true
            || self::raw($actor, Model::class, 'primaryKey') !== 'id'
            || self::raw($actor, Model::class, 'keyType') !== 'int'
            || self::raw($actor, Model::class, 'incrementing') !== true) {
            throw new IdentityException;
        }
        $attributes = self::array($actor, Model::class, 'attributes');
        $original = self::array($actor, Model::class, 'original');
        $casts = self::array($actor, Model::class, 'casts');
        $cache = self::array($actor, Model::class, 'classCastCache');
        $attributeCache = self::array($actor, Model::class, 'attributeCastCache');
        $table = self::raw($actor, Model::class, 'table');
        $connection = self::raw($actor, Model::class, 'connection');
        $id = $attributes['id'] ?? null;
        if ((! is_int($id) && (! is_string($id) || preg_match('/\A[1-9][0-9]{0,17}\z/D', $id) !== 1))
            || (int) $id < 1 || ($original['id'] ?? null) !== $id
            || (isset($casts['id']) && ! in_array($casts['id'], ['int', 'integer'], true))
            || array_key_exists('id', $cache) || array_key_exists('id', $attributeCache)
            || ! in_array($table, [null, 'users'], true) || (! is_null($connection) && ! is_string($connection))) {
            throw new IdentityException;
        }
        $remaining = 1024;
        self::plain($attributes, $remaining);
        self::plain($original, $remaining);

        return (int) $id;
    }

    private static function inspect(?PDO $ownedPrimary): array
    {
        $app = Container::getInstance();
        if ($app::class !== Application::class) {
            throw new IdentityException;
        }
        $instances = self::array($app, Container::class, 'instances');
        $repository = $instances['config'] ?? null;
        $manager = $instances['db'] ?? null;
        if (! $repository instanceof Repository || $repository::class !== Repository::class
            || ! $manager instanceof DatabaseManager || $manager::class !== DatabaseManager::class
            || self::raw($manager, DatabaseManager::class, 'app') !== $app) {
            throw new IdentityException;
        }
        foreach (['globalBeforeResolvingCallbacks', 'globalResolvingCallbacks', 'globalAfterResolvingCallbacks'] as $property) {
            if (self::raw($app, Container::class, $property) !== []) {
                throw new IdentityException;
            }
        }
        foreach (['aliases', 'beforeResolvingCallbacks', 'resolvingCallbacks', 'afterResolvingCallbacks', 'extenders'] as $property) {
            $values = self::array($app, Container::class, $property);
            foreach (['db', 'config', 'env'] as $key) {
                if (isset($values[$key])) {
                    throw new IdentityException;
                }
            }
        }
        self::resolverMetadata($app);
        $resolved = (new ReflectionProperty(Facade::class, 'resolvedInstance'))->getValue();
        if ((new ReflectionProperty(Facade::class, 'app'))->getValue() !== $app || ! is_array($resolved)
            || (array_key_exists('db', $resolved) && $resolved['db'] !== $manager)
            || (array_key_exists('config', $resolved) && $resolved['config'] !== $repository)) {
            throw new IdentityException;
        }
        $items = self::array($repository, Repository::class, 'items');
        $configuration = [];
        $remaining = 4096;
        foreach (['app', 'database', 'production-customer-identity'] as $parent) {
            if (! is_array($items[$parent] ?? null)) {
                throw new IdentityException;
            }
            $configuration[$parent] = self::plain($items[$parent], $remaining);
        }
        $default = $configuration['database']['default'] ?? null;
        if (! is_string($default) || $default === '') {
            throw new IdentityException;
        }
        $connections = self::array($manager, DatabaseManager::class, 'connections');
        if (! isset($connections[$default]) || count($connections) > 64) {
            throw new IdentityException;
        }
        $primaries = [];
        foreach ($connections as $name => $connection) {
            if (! is_string($name) || ! is_object($connection)
                || ! in_array($connection::class, [SQLiteConnection::class, MySqlConnection::class], true)) {
                throw new IdentityException;
            }
            $config = self::array($connection, Connection::class, 'config');
            $database = self::raw($connection, Connection::class, 'database');
            $prefix = self::raw($connection, Connection::class, 'tablePrefix');
            $primary = self::raw($connection, Connection::class, 'pdo');
            $level = self::raw($connection, Connection::class, 'transactions');
            if (! is_string($config['driver'] ?? null) || ! in_array($config['driver'], ['sqlite', 'mysql'], true)
                || ($config['name'] ?? null) !== $name || ! is_string($database) || ! is_string($prefix)
                || ! is_int($level) || $level < 0 || ! $primary instanceof PDO
                || ! in_array($primary::class, [PDO::class, 'Pdo\\Sqlite', 'Pdo\\Mysql'], true)
                || ! (new ReflectionClass($primary))->isInternal()) {
                throw new IdentityException;
            }
            if ($primary->getAttribute(PDO::ATTR_STATEMENT_CLASS) !== [PDOStatement::class]
                || $primary->getAttribute(PDO::ATTR_DRIVER_NAME) !== $config['driver']
                || $primary->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION
                || (($level !== 0 || $primary->inTransaction()) && ($name !== $default || $primary !== $ownedPrimary))) {
                throw new IdentityException;
            }
            $primaries[$name] = [$primary, $config['driver'], $name, $database, $prefix];
        }

        return ['application' => $app, 'repository' => $repository, 'manager' => $manager,
            'environment' => self::environment($app, $instances), 'configuration' => $configuration,
            'default' => $default, 'connections' => $connections, 'primaries' => $primaries];
    }

    /** Application resolves deferred/contextual branches before its cached-instance return. */
    private static function resolverMetadata(Application $app): void
    {
        self::array($app, Container::class, 'with');
        self::array($app, Container::class, 'resolved');
        $stack = self::array($app, Container::class, 'buildStack');
        if (! array_is_list($stack) || count($stack) > 128) {
            throw new IdentityException;
        }
        foreach ($stack as $entry) {
            if (! is_string($entry)) {
                throw new IdentityException;
            }
        }
        $critical = ['db', 'config', 'env'];
        $aliases = self::array($app, Container::class, 'abstractAliases');
        foreach (['db', 'config', 'env'] as $key) {
            $names = $aliases[$key] ?? [];
            if (! is_array($names) || ! array_is_list($names)) {
                throw new IdentityException;
            }
            foreach ($names as $name) {
                if (! is_string($name)) {
                    throw new IdentityException;
                }
                $critical[] = $name;
            }
        }
        $contextual = self::array($app, Container::class, 'contextual');
        foreach ($contextual as $owner => $bindings) {
            if (! is_string($owner) || ! is_array($bindings)) {
                throw new IdentityException;
            }
            foreach ($critical as $name) {
                if (array_key_exists($name, $bindings)) {
                    throw new IdentityException;
                }
            }
        }
        $deferred = self::array($app, Application::class, 'deferredServices');
        foreach (['db', 'config', 'env'] as $name) {
            if (array_key_exists($name, $deferred)) {
                throw new IdentityException;
            }
        }
    }

    private static function environment(Application $app, array $instances): string
    {
        if (array_key_exists('env', $instances)) {
            $environment = $instances['env'];
        } else {
            $bindings = self::array($app, Container::class, 'bindings');
            if (! is_array($bindings['env'] ?? null) || ! ($bindings['env']['concrete'] ?? null) instanceof Closure) {
                throw new IdentityException;
            }
            $resolver = new ReflectionFunction($bindings['env']['concrete']);
            $method = new ReflectionMethod(Container::class, 'offsetSet');
            $variables = $resolver->getStaticVariables();
            if ($resolver->getFileName() !== $method->getFileName() || $resolver->getStartLine() < $method->getStartLine()
                || $resolver->getEndLine() > $method->getEndLine() || $resolver->getClosureThis() !== $app
                || array_keys($variables) !== ['value']) {
                throw new IdentityException;
            }
            $environment = $variables['value'];
        }
        if (! is_string($environment) || $environment === '') {
            throw new IdentityException;
        }

        return $environment;
    }

    private static function array(object $object, string $class, string $name): array
    {
        $value = self::raw($object, $class, $name);
        if (! is_array($value)) {
            throw new IdentityException;
        }

        return $value;
    }

    private static function raw(object $object, string $class, string $name): mixed
    {
        return (new ReflectionProperty($class, $name))->getRawValue($object);
    }

    private static function plain(mixed $value, int &$remaining, int $depth = 0): mixed
    {
        if (--$remaining < 0 || $depth > 24) {
            throw new IdentityException;
        }
        if (is_array($value)) {
            $copy = [];
            foreach ($value as $key => $child) {
                $copy[$key] = self::plain($child, $remaining, $depth + 1);
            }

            return $copy;
        }
        if (! is_null($value) && ! is_string($value) && ! is_int($value) && ! is_bool($value)
            && (! is_float($value) || ! is_finite($value))) {
            throw new IdentityException;
        }

        return $value;
    }
}
