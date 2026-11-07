<?php

namespace Tests\Feature\ProductionIdentityAdapters;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Customers\ProductionIdentity\IdentityHistoricalCommittedReceipt;
use App\Domain\Customers\ProductionIdentity\IdentityOriginalCommitWitness;
use ArrayObject;
use Illuminate\Config\Repository;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\Support\ProductionIdentityFixture;
use Tests\TestCase;

final class IdentityHistoricalConfigurationAdmissionTest extends TestCase
{
    use ProductionIdentityFixture;

    public static function parents(): array
    {
        return [['items'], ['app'], ['production-customer-identity'], ['database']];
    }

    #[DataProvider('parents')]
    public function test_factory_refuses_non_array_configuration_before_any_parent_callback(string $parent): void
    {
        $this->identitySetup();
        config(['production-customer-identity.historical_receipts_enabled' => true,
            'production-customer-identity.historical_receipts_version' => IdentityHistoricalCommittedReceipt::VERSION]);
        $owner = $this->enrollThroughLocalSmtp();
        $repo = app('config');
        $property = new ReflectionProperty(Repository::class, 'items');
        $before = $property->getValue($repo);
        $extension = new HistoricalConfigurationParentCallback($parent === 'items' ? $before : $before[$parent]);
        $items = $before;
        $items = $parent === 'items' ? $extension : array_replace($items, [$parent => $extension]);
        // Positive control: the same capture is admitted while every configuration parent is a plain array.
        DB::transaction(function (): void {
            $this->assertInstanceOf(IdentityOriginalCommitWitness::class,
                IdentityOriginalCommitWitness::capture(new CurrentRows(DB::connection()->getPdo(), DB::getDriverName()), hrtime(true) + 30_000_000_000));
        });
        $refused = false;
        DB::transaction(function () use ($property, $repo, $items, $before, &$refused): void {
            $reader = new CurrentRows(DB::connection()->getPdo(), DB::getDriverName());
            $property->setValue($repo, $items);
            try {
                IdentityOriginalCommitWitness::capture($reader, hrtime(true) + 30_000_000_000);
            } catch (IdentityException $error) {
                // Since #197 the raw metadata admission (IdentityMetadataAdmission::capture) runs first and refuses an
                // ArrayAccess parent with its own reason, still before any parent callback; configuration admission is the
                // second line. Either refusal is acceptable; the control below rules out a vacuous generic refusal.
                $refused = in_array($error->reason, ['unavailable', 'historical_plain_configuration_required'], true);
            } finally {
                $property->setValue($repo, $before);
            }
        });
        $this->assertTrue($refused);
        $this->assertSame(0, $extension->calls, 'Initial default-connection resolution must also follow plain parent admission.');
        $this->assertSame(1, DB::table('production_identity_origins')->where('public_id', $owner['binding']['origin_id'])->count());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }
}

final class HistoricalConfigurationParentCallback extends ArrayObject
{
    public int $calls = 0;

    public function offsetGet(mixed $key): mixed
    {
        $this->calls++;

        return parent::offsetGet($key);
    }
}
