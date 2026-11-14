<?php

namespace Tests\Feature\ProductionMemberOriginals;

use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Grants\Member\MemberGrantException;
use App\Domain\Grants\Member\MemberGrantFactsAuthority;
use App\Domain\Grants\Member\MemberGrantPolicy;
use App\Domain\Grants\Member\MemberOriginalArtifactAuthority;
use App\Domain\Grants\Member\MemberOriginalArtifactManifest;
use App\Domain\Memberships\Production\MemberGrantIntent;
use App\Domain\Memberships\Production\MembershipReservationAuthority;
use ArrayObject;
use Illuminate\Config\Repository;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class MemberOriginalPreparationTest extends TestCase
{
    public function test_new_member_family_remains_default_off_without_actual_original_facts(): void
    {
        $this->assertSame('production-member-origin-v1', MemberGrantIntent::FAMILY);
        $this->assertSame('production-member-license-grant-v1', MemberGrantIntent::PURPOSE);
        $this->expectException(MemberGrantException::class);
        (new MemberGrantPolicy)->current();
    }

    public function test_claimed_enablement_hashes_do_not_supply_an_actual_member_original_capability(): void
    {
        config(['member-grants.enabled' => true, 'member-grants.provenance' => 'synthetic_rehearsal',
            'member-grants.approved_definition_hash' => str_repeat('a', 64),
            'member-grants.approved_profile_hash' => str_repeat('a', 64),
            'member-grants.approved_original_terms_hash' => str_repeat('a', 64)]);
        $this->expectException(MemberGrantException::class);
        (new MemberGrantPolicy)->current();
    }

    public function test_pure_policy_baseline_refuses_an_overloaded_parent_without_invoking_it(): void
    {
        config(['production-customer-identity.enabled' => true]);
        $policy = new MemberGrantPolicy;
        $baseline = (new ReflectionMethod(MemberGrantPolicy::class, 'configuration'))->invoke($policy);
        $repository = app('config');
        $property = new ReflectionProperty(Repository::class, 'items');
        $before = $property->getValue($repository);
        $parent = new class($before['member-grants']) extends ArrayObject
        {
            public int $calls = 0;

            public function offsetGet(mixed $key): mixed
            {
                $this->calls++;
                config(['production-customer-identity.enabled' => false]);

                return parent::offsetGet($key);
            }
        };
        $items = $before;
        $items['member-grants'] = $parent;
        $property->setValue($repository, $items);
        try {
            $refused = false;
            try {
                $policy->proveConfiguration($baseline);
            } catch (MemberGrantException) {
                $refused = true;
            }
            $this->assertTrue($refused);
            $this->assertSame(0, $parent->calls);
            $this->assertTrue(config('production-customer-identity.enabled'));
        } finally {
            $property->setValue($repository, $before);
        }
    }

    public function test_verified_member_and_rehearsal_membership_on_configured_sqlite_refuse_before_factories(): void
    {
        $this->memberCapabilities();
        config(['member-grants.provenance' => IdentityPolicy::PRODUCTION,
            'production-memberships.provenance' => IdentityPolicy::REHEARSAL,
            'database.default' => 'member_config_only', 'database.connections.member_config_only' => ['driver' => 'sqlite']]);
        $calls = 0;
        foreach ($this->capabilities() as $capability) {
            $this->app->bind($capability, function () use (&$calls, $capability) {
                $calls++;

                return Mockery::mock($capability);
            });
        }
        $this->refused(fn () => (new MemberGrantPolicy)->current(), 'provenance_driver');
        $this->assertSame(0, $calls);
    }

    public function test_all_capabilities_must_exist_before_a_factory_can_supply_a_missing_one(): void
    {
        foreach ($this->capabilities() as $missing) {
            $this->memberCapabilities();
            $calls = 0;
            $this->app->offsetUnset($missing);
            if ($missing !== MemberGrantFactsAuthority::class) {
                $this->app->bind(MemberGrantFactsAuthority::class, function () use (&$calls, $missing) {
                    $calls++;
                    $this->app->instance($missing, Mockery::mock($missing));

                    return Mockery::mock(MemberGrantFactsAuthority::class);
                });
            }
            $this->refused(fn () => (new MemberGrantPolicy)->current(), 'capability_absent');
            $this->assertSame(0, $calls);
        }
    }

    public function test_capability_aliases_are_refused_before_any_factory_is_resolved(): void
    {
        $this->memberCapabilities();
        $calls = 0;
        $this->app->bind('member_alias_probe', function () use (&$calls) {
            $calls++;

            return Mockery::mock(MemberGrantFactsAuthority::class);
        });
        $this->app->alias('member_alias_probe', MemberGrantFactsAuthority::class);
        $this->refused(fn () => (new MemberGrantPolicy)->current(), 'capability_absent');
        $this->assertSame(0, $calls);
    }

    public function test_configuration_only_admission_rechecks_after_each_factory(): void
    {
        foreach ([0, 1, 2] as $changedAt) {
            $this->memberCapabilities();
            $calls = [0, 0, 0];
            foreach ($this->capabilities() as $index => $capability) {
                $this->app->bind($capability, function () use (&$calls, $index, $changedAt, $capability) {
                    $calls[$index]++;
                    if ($index === $changedAt) {
                        config(['member-grants.enabled' => false]);
                    }

                    return Mockery::mock($capability);
                });
            }
            $this->refused(fn () => (new MemberGrantPolicy)->current(), 'changed_policy');
            $this->assertSame(array_map(static fn (int $i): int => $i <= $changedAt ? 1 : 0, [0, 1, 2]), $calls);
        }
    }

    #[DataProvider('malformedDatabaseCases')]
    public function test_malformed_database_configuration_refuses_without_overloaded_access(string $case): void
    {
        $this->memberCapabilities();
        $repository = app('config');
        $property = new ReflectionProperty(Repository::class, 'items');
        $before = $property->getValue($repository);
        $armed = new class(['default' => 'probe', 'connections' => ['probe' => ['driver' => 'sqlite']]]) extends ArrayObject
        {
            public int $calls = 0;

            public function offsetGet(mixed $key): mixed
            {
                $this->calls++;

                return parent::offsetGet($key);
            }
        };
        $database = match ($case) {
            'absent' => null,
            'overloaded_database' => $armed,
            'missing_default' => ['connections' => []],
            'empty_default' => ['default' => '', 'connections' => []],
            'nonstring_default' => ['default' => 1, 'connections' => []],
            'missing_connections' => ['default' => 'probe'],
            'overloaded_connections' => ['default' => 'probe', 'connections' => $armed],
            'missing_connection' => ['default' => 'probe', 'connections' => []],
            'overloaded_connection' => ['default' => 'probe', 'connections' => ['probe' => $armed]],
            'missing_driver' => ['default' => 'probe', 'connections' => ['probe' => []]],
            'nonstring_driver' => ['default' => 'probe', 'connections' => ['probe' => ['driver' => $armed]]],
        };
        $items = $before;
        $items['database'] = $database;
        $property->setValue($repository, $items);
        try {
            $this->refused(fn () => (new MemberGrantPolicy)->current(), 'changed_policy');
            $this->assertSame(0, $armed->calls);
        } finally {
            $property->setValue($repository, $before);
        }
    }

    public static function malformedDatabaseCases(): array
    {
        return array_map(static fn (string $case): array => [$case], ['absent', 'overloaded_database', 'missing_default',
            'empty_default', 'nonstring_default', 'missing_connections', 'overloaded_connections', 'missing_connection',
            'overloaded_connection', 'missing_driver', 'nonstring_driver']);
    }

    public function test_captured_scalar_configuration_is_detached_from_repository_references(): void
    {
        $this->memberCapabilities();
        $repository = app('config');
        $property = new ReflectionProperty(Repository::class, 'items');
        $before = $property->getValue($repository);
        $items = $before;
        $enabled = true;
        $driver = 'sqlite';
        $connection = 'member_scalar_probe';
        $items['member-grants']['enabled'] = &$enabled;
        $items['database']['default'] = &$connection;
        $items['database']['connections']['member_scalar_probe'] = ['driver' => &$driver];
        $property->setValue($repository, $items);
        try {
            $policy = new MemberGrantPolicy;
            $baseline = $policy->current();
            $enabled = false;
            $driver = 'mysql';
            $connection = 'changed_name';
            $this->assertSame([true, 'sqlite', 'member_scalar_probe'], [$baseline['enabled'], $baseline['driver'], $baseline['connection_name']]);
            $this->refused(fn () => $policy->proveConfiguration($baseline), 'changed_policy');
        } finally {
            $property->setValue($repository, $before);
        }
    }

    private function capabilities(): array
    {
        return [MemberGrantFactsAuthority::class, MemberOriginalArtifactAuthority::class, MembershipReservationAuthority::class];
    }

    private function memberCapabilities(): void
    {
        config(['member-grants.enabled' => true, 'member-grants.provenance' => IdentityPolicy::REHEARSAL,
            'member-grants.approved_definition_hash' => str_repeat('a', 64),
            'member-grants.approved_profile_hash' => str_repeat('b', 64),
            'member-grants.approved_original_terms_hash' => str_repeat('c', 64)]);
        foreach ($this->capabilities() as $capability) {
            $this->app->instance($capability, Mockery::mock($capability));
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

    public function test_manifest_values_never_admit_private_paths_or_duplicate_roles(): void
    {
        foreach ([
            [['role' => 'contract', 'sha256' => str_repeat('a', 64), 'bytes' => 1, 'storage_policy_hash' => str_repeat('b', 64), 'path' => '/private/secret']],
            array_fill(0, 2, ['role' => 'contract', 'sha256' => str_repeat('a', 64), 'bytes' => 1, 'storage_policy_hash' => str_repeat('b', 64)]),
        ] as $originals) {
            try {
                new MemberOriginalArtifactManifest('11111111-1111-4111-8111-111111111111', str_repeat('c', 64), $originals);
                $this->fail('A public value must never carry a file path or ambiguous original role.');
            } catch (MemberGrantException) {
                $this->assertTrue(true);
            }
        }
    }
}
