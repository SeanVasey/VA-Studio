<?php

namespace Tests\Feature\ProductionMembership;

use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipPolicy;
use App\Domain\Memberships\Production\MembershipRows;
use ArrayObject;
use Illuminate\Config\Repository;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use ReflectionProperty;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

/** Real Repository parent replacement after capture; no invoice, grant or actor-context fabrication. */
class MembershipRawConfigurationClosureTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public function test_captured_reader_refuses_overloaded_database_parent_before_late_policy_callback(): void
    {
        config(['production-memberships.enabled' => true]);
        DB::transaction(function () {
            $rows = new MembershipRows;
            $parent = new ArmedMembershipConfigurationParent(config('database'));
            $this->replaceParent('database', $parent);
            try {
                $refused = false;
                try {
                    $rows->assertCurrent();
                } catch (MembershipException) {
                    $refused = true;
                }
                $this->assertTrue($refused, 'A claimed pure captured-reader fence must refuse a callback-capable raw parent.');
                $this->assertSame(0, $parent->callbacks);
                $this->assertTrue(config('production-memberships.enabled'));
            } finally {
                $this->replaceParent('database', $parent->getArrayCopy());
            }
        });
    }

    public function test_captured_policy_baseline_refuses_overloaded_parent_before_identity_policy_callback(): void
    {
        config(['production-customer-identity.enabled' => true]);
        $policy = new MembershipPolicy;
        // This is the internal raw baseline, not seller facts or invoice authority.
        $baseline = (new ReflectionMethod(MembershipPolicy::class, 'configuration'))->invoke($policy);
        $parent = new ArmedMembershipConfigurationParent(config('production-memberships'), 'production-customer-identity.enabled');
        $this->replaceParent('production-memberships', $parent);
        try {
            $refused = false;
            try {
                $policy->proveConfiguration($baseline);
            } catch (MembershipException) {
                $refused = true;
            }
            $this->assertTrue($refused, 'Pure policy comparison cannot execute a getter that withdraws another held authority.');
            $this->assertSame(0, $parent->callbacks);
            $this->assertTrue(config('production-customer-identity.enabled'));
        } finally {
            $this->replaceParent('production-memberships', $parent->getArrayCopy());
        }
    }

    private function replaceParent(string $key, array|ArrayObject $value): void
    {
        $repository = app('config');
        $property = new ReflectionProperty(Repository::class, 'items');
        $items = $property->getValue($repository);
        $items[$key] = $value;
        $property->setValue($repository, $items);
    }
}

final class ArmedMembershipConfigurationParent extends ArrayObject
{
    public int $callbacks = 0;

    public function __construct(array $values, private string $withdraw = 'production-memberships.enabled')
    {
        parent::__construct($values);
    }

    public function offsetGet(mixed $key): mixed
    {
        $this->callbacks++;
        config([$this->withdraw => false]);

        return parent::offsetGet($key);
    }
}
