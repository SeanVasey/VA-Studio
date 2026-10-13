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

    public function test_rehearsal_admission_binds_the_real_frame_and_terminal_checks_do_not_resolve_factories(): void
    {
        DB::transaction(function () {
            $rows = new MembershipRows;
            $policy = new MemberGrantPolicy;
            $baseline = $policy->currentFor($rows);
            $this->assertSame(DB::getDriverName(), $baseline['driver']);
            $this->assertSame(DB::getDefaultConnection(), $baseline['connection_name']);
            $this->assertSame([1, 1, 1], $this->calls);
            // A reconstructed equal array is legitimate after this exact issuer/frame was admitted.
            $equal = json_decode(json_encode($baseline, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            $policy->proveCurrent($equal, $rows);
            $this->assertSame($baseline, $policy->currentFor($rows));
            $this->assertSame([1, 1, 1], $this->calls);
            $this->assertSame(0, DB::table('production_membership_credit_events')->count());
            $this->assertSame(0, DB::table('production_member_origins')->count());
        });
    }

    public function test_native_verified_member_provenance_requires_and_binds_actual_mysql_despite_rehearsal_membership(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Actual disposable native MySQL frame required; this is not production authorization.');
        }
        config(['member-grants.provenance' => IdentityPolicy::PRODUCTION]);
        DB::transaction(function () {
            $rows = new MembershipRows;
            $policy = new MemberGrantPolicy;
            $baseline = $policy->currentFor($rows);
            $this->assertSame(['mysql', IdentityPolicy::PRODUCTION], [$baseline['driver'], $baseline['provenance']]);
            $this->assertSame('mysql', $rows->identity()->getAttribute(PDO::ATTR_DRIVER_NAME));
            $policy->proveCurrent($baseline, $rows);
            $this->assertSame([1, 1, 1], $this->calls);
        });
    }

    public function test_sqlite_verified_member_refuses_both_honest_and_mislabeled_configuration_before_factories(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite frame is required to prove that a configured MySQL label supplies no native proof.');
        }
        DB::transaction(function () {
            $rows = new MembershipRows;
            config(['member-grants.provenance' => IdentityPolicy::PRODUCTION]);
            $this->refused(fn () => (new MemberGrantPolicy)->currentFor($rows), 'provenance_driver');
            $driverKey = 'database.connections.'.DB::getDefaultConnection().'.driver';
            config([$driverKey => 'mysql']);
            try {
                $this->refused(fn () => (new MemberGrantPolicy)->currentFor($rows), 'provenance_driver');
                $this->assertSame([0, 0, 0], $this->calls);
            } finally {
                config([$driverKey => 'sqlite']);
            }
        });
    }

    public function test_capability_callback_configuration_drift_refuses_before_the_remaining_factories(): void
    {
        $connection = DB::getDefaultConnection();
        $baseline = (new MemberGrantPolicy)->current();
        foreach (['member-grants.enabled' => false, 'member-grants.provenance' => IdentityPolicy::PRODUCTION,
            'database.default' => 'unissued_connection', 'database.connections.'.$connection.'.driver' => 'foreign_driver'] as $key => $changed) {
            $original = config($key);
            $this->calls = [0, 0, 0];
            $this->factories(static fn () => config([$key => $changed]));
            DB::transaction(function () use ($key, $original, $baseline) {
                $rows = new MembershipRows;
                $policy = new MemberGrantPolicy;
                try {
                    $this->refused(fn () => $policy->currentFor($rows), 'changed_policy');
                    $this->assertSame([1, 0, 0], $this->calls);
                } finally {
                    config([$key => $original]);
                }
                // Restoring configuration cannot issue the failed first admission retroactively.
                $this->refused(fn () => $policy->proveCurrent($baseline, $rows), 'foreign_frame');
            });
            $this->factories();
        }
    }

    public function test_each_callback_boundary_checks_the_frame_before_resolving_later_factories(): void
    {
        foreach ([0, 1, 2] as $changedAt) {
            $connection = DB::connection();
            $original = $connection->getPdo();
            $this->calls = [0, 0, 0];
            $this->factories(static fn () => $connection->setPdo(new PDO('sqlite::memory:')), $changedAt);
            DB::transaction(function () use ($connection, $original, $changedAt) {
                $rows = new MembershipRows;
                try {
                    $this->refused(fn () => (new MemberGrantPolicy)->currentFor($rows), 'changed_primary');
                    $this->assertSame(array_map(static fn (int $i): int => $i <= $changedAt ? 1 : 0, [0, 1, 2]), $this->calls);
                } finally {
                    // setPdo resets Laravel's transaction counter; restore the captured fixture frame only.
                    $connection->setPdo($original);
                    (new \ReflectionProperty(Connection::class, 'transactions'))->setValue($connection, 1);
                }
            });
        }
    }

    public function test_replaced_repository_or_container_is_refused_before_other_factories(): void
    {
        foreach (['repository', 'container'] as $replacement) {
            $repository = $this->app->make('config');
            $this->calls = [0, 0, 0];
            $this->factories(function () use ($replacement, $repository) {
                if ($replacement === 'repository') {
                    $this->app->instance('config', new Repository($repository->all()));
                } else {
                    Container::setInstance(new Container);
                }
            });
            DB::transaction(function () use ($repository) {
                $rows = new MembershipRows;
                try {
                    $this->refused(fn () => (new MemberGrantPolicy)->currentFor($rows), 'changed_policy');
                    $this->assertSame([1, 0, 0], $this->calls);
                } finally {
                    Container::setInstance($this->app);
                    $this->app->instance('config', $repository);
                }
            });
        }
    }

    public function test_callback_cannot_withdraw_a_later_capability_and_run_intervening_factories(): void
    {
        $this->factories(fn () => $this->app->offsetUnset(MembershipReservationAuthority::class));
        DB::transaction(function () {
            $rows = new MembershipRows;
            $this->refused(fn () => (new MemberGrantPolicy)->currentFor($rows), 'capability_absent');
            $this->assertSame([1, 0, 0], $this->calls);
        });
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
            $this->refused(fn () => (new MemberGrantPolicy)->currentFor($rows), 'provenance_driver');
            $this->assertSame($driver, config($key));
            $this->assertSame([1, 0, 0], $this->calls);
        });
    }

    public function test_equal_configuration_cannot_transfer_an_issued_frame_to_another_policy_or_rows(): void
    {
        DB::transaction(function () {
            $rows = new MembershipRows;
            $other = new MembershipRows;
            $policy = new MemberGrantPolicy;
            $baseline = $policy->currentFor($rows);
            $this->assertSame($rows->identity(), $other->identity());
            $this->refused(fn () => $policy->proveCurrent($baseline, $other), 'foreign_frame');
            $this->refused(fn () => (new MemberGrantPolicy)->proveCurrent($baseline, $rows), 'foreign_frame');
            $this->refused(fn () => clone $policy, 'clone_refused');
            $policy->proveCurrent($baseline, $rows);
            $this->assertSame([1, 1, 1], $this->calls);
        });
    }

    public function test_terminal_reproof_rejects_policy_connection_name_and_capability_drift_without_callbacks(): void
    {
        DB::transaction(function () {
            $rows = new MembershipRows;
            $policy = new MemberGrantPolicy;
            $baseline = $policy->currentFor($rows);
            $default = DB::getDefaultConnection();
            config(['database.connections.member_same_driver' => config('database.connections.'.$default),
                'database.default' => 'member_same_driver']);
            try {
                $this->refused(fn () => $policy->proveCurrent($baseline, $rows), 'changed_policy');
            } finally {
                config(['database.default' => $default]);
            }
            config(['member-grants.enabled' => false]);
            try {
                $this->refused(fn () => $policy->proveCurrent($baseline, $rows), 'changed_policy');
            } finally {
                config(['member-grants.enabled' => true]);
            }
            $this->app->offsetUnset(MembershipReservationAuthority::class);
            $this->refused(fn () => $policy->proveCurrent($baseline, $rows), 'capability_absent');
            $this->assertSame([1, 1, 1], $this->calls);
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

    public function test_commit_and_reopen_on_the_same_pdo_cannot_reuse_an_original_admission(): void
    {
        DB::beginTransaction();
        try {
            $rows = new MembershipRows;
            $policy = new MemberGrantPolicy;
            $baseline = $policy->currentFor($rows);
            $pdo = $rows->identity();
            $pdo->commit();
            $pdo->beginTransaction();
            $this->refused(fn () => $policy->proveCurrent($baseline, $rows), 'changed_primary');
            $this->assertTrue($pdo->inTransaction(), 'Admission refuses without rolling back a replacement transaction.');
            $this->assertSame([1, 1, 1], $this->calls);
        } finally {
            DB::rollBack();
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
