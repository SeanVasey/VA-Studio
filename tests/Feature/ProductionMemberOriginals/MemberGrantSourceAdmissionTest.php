<?php

namespace Tests\Feature\ProductionMemberOriginals;

use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Grants\Member\MemberGrantException;
use App\Domain\Grants\Member\MemberGrantFactsAuthority;
use App\Domain\Grants\Member\MemberGrantPolicy;
use App\Domain\Grants\Member\MemberOriginalArtifactAuthority;
use App\Domain\Memberships\Production\MembershipReservationAuthority;
use App\Domain\Memberships\Production\MembershipRows;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use LogicException;
use Mockery;
use PDO;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

/** Real disposable frames; nonoperative capability doubles never produce facts, originals or credit. */
class MemberGrantSourceAdmissionTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const CAPABILITIES = [MemberGrantFactsAuthority::class, MemberOriginalArtifactAuthority::class, MembershipReservationAuthority::class];

    private array $calls = [0, 0, 0];

    protected function setUp(): void
    {
        parent::setUp();
        config(['production-memberships.provenance' => IdentityPolicy::REHEARSAL,
            'member-grants.enabled' => true, 'member-grants.provenance' => IdentityPolicy::REHEARSAL,
            'member-grants.approved_definition_hash' => str_repeat('a', 64),
            'member-grants.approved_profile_hash' => str_repeat('b', 64),
            'member-grants.approved_original_terms_hash' => str_repeat('c', 64)]);
        $this->factories();
    }

    public function test_transient_configuration_cannot_admit_a_nested_mismatched_frame(): void
    {
        DB::transaction(function () {
            $rows = new MembershipRows;
            $key = 'database.connections.'.DB::getDefaultConnection().'.driver';
            $driver = config($key);
            $this->factories(function () use ($rows, $key, $driver) {
                config([$key => $driver === 'sqlite' ? 'mysql' : 'sqlite']);
                try {
                    // This observable nested admission must refuse; there is no claim about invisible,
                    // fully restored callback history that performs no attempted admission.
                    (new MemberGrantPolicy)->currentFor($rows);
                } finally {
                    config([$key => $driver]);
                }
            });
            try {
                $this->refused(fn () => (new MemberGrantPolicy)->currentFor($rows), 'provenance_driver');
                $this->assertSame($driver, config($key));
                $this->assertSame([1, 0, 0], $this->calls);
            } finally {
                // Do not retain the captured Rows/SQLite statement in a container callback past this fixture.
                $this->factories();
            }
        });
    }

    public function test_terminal_proof_reads_raw_capability_maps_without_virtual_container_getters(): void
    {
        $container = new class extends Container
        {
            public bool $armed = false;

            public array $getterCalls = [];

            public function bound($abstract)
            {
                if ($this->armed) {
                    $this->getterCalls[] = 'bound';
                    throw new LogicException('Terminal source proof invoked a virtual container getter.');
                }

                return parent::bound($abstract);
            }

            public function isAlias($name)
            {
                if ($this->armed) {
                    $this->getterCalls[] = 'isAlias';
                    throw new LogicException('Terminal source proof invoked a virtual alias getter.');
                }

                return parent::isAlias($name);
            }
        };
        // This is a real alternate captured container sharing the fixture's actual manager/repository,
        // not a fabricated Rows or PDO. MembershipRows independently admits this exact combination.
        $container->instance('config', $this->app->make('config'));
        $container->instance('db', DB::getFacadeRoot());
        $container->instance('env', 'testing');
        foreach (self::CAPABILITIES as $capability) {
            $container->instance($capability, Mockery::mock($capability));
        }
        Container::setInstance($container);
        try {
            DB::transaction(function () use ($container) {
                $rows = new MembershipRows;
                $policy = new MemberGrantPolicy;
                $baseline = $policy->currentFor($rows);
                $container->armed = true;
                try {
                    $policy->proveCurrent($baseline, $rows);
                    $this->assertSame($baseline, $policy->currentFor($rows));
                    $this->assertSame([], $container->getterCalls);
                } finally {
                    $container->armed = false;
                }
            });
        } finally {
            Container::setInstance($this->app);
        }
    }

    private function factories(?callable $callback = null, int $callbackAt = 0): void
    {
        foreach (self::CAPABILITIES as $index => $capability) {
            $double = Mockery::mock($capability); // Any authority method invocation is an unexpected call.
            $this->app->bind($capability, function () use ($index, $double, $callback, $callbackAt) {
                $this->calls[$index]++;
                if ($index === $callbackAt && $callback !== null) {
                    $callback();
                }

                return $double;
            });
        }
    }

    private function refused(callable $operation, string $reason): void
    {
        try {
            $operation();
            $this->fail('Expected member source refusal: '.$reason);
        } catch (MemberGrantException $error) {
            $this->assertSame($reason, $error->reason);
        }
    }
}
