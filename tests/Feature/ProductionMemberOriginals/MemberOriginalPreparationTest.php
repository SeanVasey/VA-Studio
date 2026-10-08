<?php

namespace Tests\Feature\ProductionMemberOriginals;

use App\Domain\Grants\Member\MemberGrantException;
use App\Domain\Grants\Member\MemberGrantPolicy;
use App\Domain\Grants\Member\MemberOriginalArtifactManifest;
use App\Domain\Memberships\Production\MemberGrantIntent;
use ArrayObject;
use Illuminate\Config\Repository;
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
