<?php

namespace Tests\Unit;

use App\Domain\Grants\Member\MemberActivationReadConfiguration;
use App\Domain\Grants\Member\MemberGrantException;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/** Actual Laravel binding storage; no application boot, PDO, producer or historical-read admission. */
final class MemberActivationReadEnvironmentTest extends TestCase
{
    private Container $priorContainer;

    private mixed $priorFacadeApplication;

    private array $priorFacades;

    private Application $application;

    private Repository $configuration;

    private DatabaseManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->priorContainer = Container::getInstance();
        $this->priorFacadeApplication = (new ReflectionProperty(Facade::class, 'app'))->getValue();
        $this->priorFacades = (new ReflectionProperty(Facade::class, 'resolvedInstance'))->getValue();
        $this->application = new Application;
        $this->configuration = new Repository(['database' => ['default' => 'sqlite',
            'connections' => ['sqlite' => ['driver' => 'sqlite']]]]);
        $this->manager = new DatabaseManager($this->application, new ConnectionFactory($this->application));
        $this->application->instance('config', $this->configuration);
        $this->application->instance('db', $this->manager);
        // The raw snapshot only reads this concrete connection's fields. It has no PDO to execute.
        (new ReflectionProperty(DatabaseManager::class, 'connections'))->setValue($this->manager,
            ['sqlite' => new SQLiteConnection(null, ':memory:', '', ['driver' => 'sqlite'])]);
        Facade::setFacadeApplication($this->application);
        (new ReflectionProperty(Facade::class, 'resolvedInstance'))->setValue(null, ['db' => $this->manager]);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->priorContainer);
        (new ReflectionProperty(Facade::class, 'app'))->setValue(null, $this->priorFacadeApplication);
        (new ReflectionProperty(Facade::class, 'resolvedInstance'))->setValue(null, $this->priorFacades);
        parent::tearDown();
    }

    public function test_actual_offset_set_environment_is_captured_without_resolving_its_binding(): void
    {
        $this->application['env'] = 'testing';
        $instances = (new ReflectionProperty(Container::class, 'instances'))->getValue($this->application);
        self::assertArrayNotHasKey('env', $instances);
        self::assertSame('testing', $this->snapshot()['environment']);
    }

    public function test_direct_instance_takes_precedence_over_an_unused_factory(): void
    {
        $called = false;
        $this->application->bind('env', function () use (&$called): string {
            $called = true;

            return 'production';
        });
        $this->application->instance('env', 'local');
        self::assertSame('local', $this->snapshot()['environment']);
        self::assertFalse($called);
    }

    public function test_replacing_the_framework_captured_environment_changes_the_snapshot(): void
    {
        $this->application['env'] = 'testing';
        $before = $this->snapshot();
        $this->application['env'] = 'production';
        $after = $this->snapshot();
        self::assertNotSame($before, $after);
        self::assertSame('testing', $before['environment']);
        self::assertSame('production', $after['environment']);
    }

    public function test_arbitrary_environment_factory_is_refused_without_calling_it(): void
    {
        $called = false;
        $this->application->bind('env', function () use (&$called): string {
            $called = true;

            return 'testing';
        });
        try {
            $this->snapshot();
            self::fail('An executable environment factory was accepted as captured scalar state.');
        } catch (MemberGrantException $error) {
            self::assertSame('changed_configuration', $error->reason);
        } finally {
            self::assertFalse($called);
        }
    }

    private function snapshot(): array
    {
        // Exercise the exact raw capture without manufacturing owner permits or passing capabilities.
        $capture = (new ReflectionClass(MemberActivationReadConfiguration::class))->newInstanceWithoutConstructor();
        foreach (['container' => $this->application, 'repository' => $this->configuration, 'manager' => $this->manager] as $name => $value) {
            (new ReflectionProperty(MemberActivationReadConfiguration::class, $name))->setValue($capture, $value);
        }

        return (new ReflectionMethod(MemberActivationReadConfiguration::class, 'snapshot'))->invoke($capture);
    }
}
