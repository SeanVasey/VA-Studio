<?php

namespace Tests\Unit;

use App\Domain\Grants\Member\MemberGrantCurrentSchema;
use App\Domain\Grants\Member\MemberGrantException;
use App\Domain\Grants\Member\MemberGrantSchema;
use App\Domain\Grants\Member\MemberGrantSchemaSnapshot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MemberGrantExpectedPlanTest extends TestCase
{
    /** Golden values taken from the original 7edd6144 installer, invoked through pure driver/prefix doubles. */
    public static function plans(): array
    {
        return [
            ['sqlite', 'main', '', '72e13d522cf24d25d94260a73adff3e577a0bced78e06ba8f75efafb4fb6bdb4'],
            ['sqlite', 'main', 'unit_', 'c2a7e7e87b0642d2d224ca92a46d12815dd3297163b5b03ec406d7b9a695dd92'],
            ['mysql', 'synthetic', '', '620c137688b5ce513b1627f44f11dbf8513e052d1370706b764a4552c6690d85'],
            ['mysql', 'synthetic', 'unit_', '98b451aaf37d68b135cc54243a7b4a0f56f5f2d61c41af8ba7dc5a3840ef5bd5'],
        ];
    }

    #[DataProvider('plans')]
    public function test_original_installed_ddl_is_byte_identical_without_a_database_or_application(string $driver, string $schema, string $prefix, string $hash): void
    {
        $plan = MemberGrantSchema::expectedPlan($driver, $schema, $prefix);
        $this->assertSame($hash, hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR)));
        $this->assertCount(5, $plan['tables']);
        $this->assertSame(16, array_sum(array_map(static fn (array $table): int => count($table['guards']), $plan['tables'])));
    }

    public function test_callers_cannot_adopt_a_snapshot_or_select_arbitrary_tables(): void
    {
        $this->expectException(MemberGrantException::class);
        MemberGrantCurrentSchema::table(new MemberGrantSchemaSnapshot, 'production_member_profiles');
    }

    public function test_driver_lookup_also_requires_issuance(): void
    {
        $this->expectException(MemberGrantException::class);
        MemberGrantCurrentSchema::captureDriver(new MemberGrantSchemaSnapshot);
    }

    public function test_snapshots_do_not_serialize(): void
    {
        $this->expectException(MemberGrantException::class);
        serialize(new MemberGrantSchemaSnapshot);
    }

    public function test_configuration_baselines_detach_nested_caller_held_references(): void
    {
        $host = 'synthetic-original-host';
        $key = 'synthetic-original-key';
        $previous = ['synthetic-previous-key'];
        $source = ['database' => ['connections' => ['mysql' => ['host' => &$host]]],
            'connection' => ['host' => &$host], 'keys' => [&$key, &$previous]];
        $method = new \ReflectionMethod(MemberGrantCurrentSchema::class, 'detachedValues');
        $captured = $method->invoke(null, $source);
        $expected = ['database' => ['connections' => ['mysql' => ['host' => 'synthetic-original-host']]],
            'connection' => ['host' => 'synthetic-original-host'], 'keys' => ['synthetic-original-key', ['synthetic-previous-key']]];
        $this->assertSame($expected, $captured);
        $host = 'synthetic-replacement-host';
        $key = 'synthetic-replacement-key';
        $previous[] = 'synthetic-new-key';
        $this->assertSame($expected, $captured);
        $this->assertNotSame($source, $captured);
    }

    public function test_configuration_baseline_refuses_recursive_values_with_a_bounded_depth(): void
    {
        $cycle = [];
        $cycle['self'] = &$cycle;
        $this->expectException(MemberGrantException::class);
        (new \ReflectionMethod(MemberGrantCurrentSchema::class, 'detachedValues'))->invoke(null, $cycle);
    }

    public function test_configuration_baseline_refuses_application_objects(): void
    {
        $this->expectException(MemberGrantException::class);
        (new \ReflectionMethod(MemberGrantCurrentSchema::class, 'detachedValues'))->invoke(null, ['value' => new \stdClass]);
    }
}
