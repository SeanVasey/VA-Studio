<?php
declare(strict_types=1);

namespace AccountIntegratedHarness;

use Composer\Autoload\ClassLoader;
use Illuminate\Foundation\Application;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

/** Shared source binding only; never constructs an application or reads credentials. */
final class Bootstrap
{
    public static array $context = [];

    public static function require(bool $condition): void
    {
        if (! $condition) {
            throw new RuntimeException('Integrated source bootstrap refused.');
        }
    }

    private static function sourceNamespace(string $class): bool
    {
        return str_starts_with($class, 'App\\') || str_starts_with($class, 'Tests\\') || str_starts_with($class, 'Database\\');
    }

    private static function declared(): array
    {
        return array_merge(get_declared_classes(), get_declared_interfaces(), get_declared_traits());
    }

    private static function noSourceLoaded(): void
    {
        foreach (self::declared() as $class) {
            self::require(! self::sourceNamespace($class));
        }
    }

    public static function environment(string $name, string $value): void
    {
        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    public static function prepare(array $binding, string $sha, ?array $selection, ?string $directory): void
    {
        $candidate = $binding['candidate_root'];
        $canonical = $binding['canonical_root'];
        $vendor = $binding['vendor_realpath'];
        self::require($candidate === '/candidate'
            && $canonical === '/dependencies' && $vendor === $canonical.'/vendor'
            && realpath($candidate) === $candidate && is_link($candidate.'/vendor')
            && realpath($candidate.'/vendor') === $vendor && preg_match('/\A[0-9a-f]{40}\z/D', $sha) === 1);
        self::require(! file_exists($candidate.'/.env') && ! file_exists($candidate.'/.env.testing'));
        self::noSourceLoaded();
        self::environment('APP_BASE_PATH', $candidate);
        self::environment('COMPOSER_VENDOR_DIR', $vendor);
        self::environment('PAO_DISABLE', '1');
        $loader = require $candidate.'/vendor/autoload.php';
        self::require($loader instanceof ClassLoader && ! $loader->isClassMapAuthoritative());
        self::noSourceLoaded();
        $prefixes = ['App\\' => 'app', 'Tests\\' => 'tests',
            'Database\\Factories\\' => 'database/factories', 'Database\\Seeders\\' => 'database/seeders'];
        $seen = [];
        foreach ($loader->getPrefixesPsr4() as $prefix => $paths) {
            if (self::sourceNamespace($prefix)) {
                self::require(isset($prefixes[$prefix]) && count($paths) === ($prefix === 'Tests\\' ? 1 : 2)
                    && realpath($paths[0]) === $canonical.'/'.$prefixes[$prefix]);
                if ($prefix !== 'Tests\\') {
                    // Pinned Composer metadata includes Pint's own application namespace. Never retain its fallback.
                    self::require($paths[1] === $vendor.'/composer/../laravel/pint/'.$prefixes[$prefix]);
                }
                $loader->setPsr4($prefix, $candidate.'/'.$prefixes[$prefix]);
                $seen[] = $prefix;
            }
        }
        sort($seen);
        $expected = array_keys($prefixes);
        sort($expected);
        self::require($seen === $expected && $loader->getFallbackDirsPsr4() === [] && $loader->getFallbackDirs() === []);
        foreach ($loader->getPrefixes() as $prefix => $paths) {
            self::require(! self::sourceNamespace($prefix));
        }
        $remapped = [];
        $removedPint = [];
        $rebound = $loader->getClassMap();
        foreach ($rebound as $class => $path) {
            if (self::sourceNamespace($class)) {
                // These pinned dependency entries are discarded, never loaded. Their resolved mount alias is irrelevant.
                $pintPrefix = $vendor.'/composer/../laravel/pint/app/';
                if (str_starts_with($path, $pintPrefix)) {
                    self::require(str_starts_with($class, 'App\\')
                        && ! in_array('..', explode('/', substr($path, strlen($pintPrefix))), true));
                    $removedPint[] = $class;
                    unset($rebound[$class]);
                    continue;
                }
                $original = realpath($path);
                self::require(is_string($original));
                $directoryName = str_starts_with($class, 'App\\') ? 'app' : (str_starts_with($class, 'Tests\\') ? 'tests' : 'database');
                self::require(str_starts_with($original, $canonical.'/'.$directoryName.'/'));
                $replacement = $candidate.substr($original, strlen($canonical));
                self::require(realpath($replacement) === $replacement && is_file($replacement));
                $remapped[$class] = $replacement;
                $rebound[$class] = $replacement;
            } else {
                // Preserve every dependency mapping exactly, including unused optional entries absent in this deployment.
                $vendorPrefix = $vendor.'/composer/../';
                self::require(str_starts_with($path, $vendorPrefix)
                    && ! in_array('..', explode('/', substr($path, strlen($vendorPrefix))), true));
            }
        }
        self::require(count($remapped) > 0 && count($removedPint) === 48);
        // Composer has no public remove-classmap API. Its pinned private array is changed only in this child.
        (new ReflectionProperty(ClassLoader::class, 'classMap'))->setValue($loader, $rebound);
        self::require($loader->getClassMap() === $rebound);
        foreach ($removedPint as $class) {
            self::require(! array_key_exists($class, $loader->getClassMap()));
            $resolved = $loader->findFile($class);
            self::require($resolved === false || (is_string($resolved) && str_starts_with($resolved, $candidate.'/')));
        }
        foreach ($remapped as $class => $path) {
            self::require($loader->getClassMap()[$class] === $path && $loader->findFile($class) === $path);
        }
        self::$context = compact('candidate', 'canonical', 'vendor', 'sha', 'selection', 'directory');
        self::representatives();
        self::origins();
        self::require(Application::inferBasePath() === $candidate);
        if ($selection !== null) {
            $file = $candidate.'/'.$selection['test_path'];
            self::require(realpath($file) === $file && hash_file('sha256', $file) === $selection['test_sha256']);
            self::require((new ReflectionClass($selection['class']))->getFileName() === $file);
        }
    }

    public static function representatives(): void
    {
        foreach (['Tests\\TestCase' => 'tests/TestCase.php',
            'App\\Domain\\Customers\\ProductionIdentity\\CurrentCatalog' => 'app/Domain/Customers/ProductionIdentity/CurrentCatalog.php',
            'Database\\Factories\\UserFactory' => 'database/factories/UserFactory.php'] as $class => $path) {
            self::require((new ReflectionClass($class))->getFileName() === self::$context['candidate'].'/'.$path);
        }
    }

    public static function origins(): void
    {
        foreach (self::declared() as $class) {
            if (self::sourceNamespace($class)) {
                $file = (new ReflectionClass($class))->getFileName();
                self::require(is_string($file) && str_starts_with($file, self::$context['candidate'].'/'));
            }
        }
        foreach (get_included_files() as $file) {
            self::require(! str_starts_with($file, self::$context['canonical'].'/')
                || str_starts_with($file, self::$context['vendor'].'/'));
        }
    }

    public static function privateDirectories(string $directory): void
    {
        foreach (['runtime', 'runtime/bootstrap', 'runtime/tmp', 'runtime/storage', 'runtime/storage/app',
            'runtime/storage/app/private', 'runtime/storage/app/public', 'runtime/storage/framework',
            'runtime/storage/framework/cache', 'runtime/storage/framework/cache/data', 'runtime/storage/framework/sessions',
            'runtime/storage/framework/views', 'runtime/storage/testing', 'runtime/storage/logs'] as $suffix) {
            $path = $directory.'/'.$suffix;
            self::require(! file_exists($path) && ! is_link($path) && mkdir($path, 0700));
            self::require(realpath($path) === $path && (fileperms($path) & 0777) === 0700);
        }
        self::environment('LARAVEL_STORAGE_PATH', $directory.'/runtime/storage');
        self::environment('VIEW_COMPILED_PATH', $directory.'/runtime/storage/framework/views');
        self::environment('TMPDIR', $directory.'/runtime/tmp');
        foreach (['CONFIG', 'SERVICES', 'PACKAGES', 'ROUTES', 'EVENTS'] as $kind) {
            self::environment('APP_'.$kind.'_CACHE', $directory.'/runtime/bootstrap/'.strtolower($kind).'.php');
        }
    }
}
