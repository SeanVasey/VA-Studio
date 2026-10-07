<?php

namespace Tests\Canary;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use ArrayObject;
use Illuminate\Config\Repository;
use Illuminate\Support\Facades\DB;
use PDO;
use ReflectionProperty;
use Tests\Support\IdentityHistoricalCommitFixture;
use Tests\Support\ProductionIdentityFixture;
use Tests\TestCase;

final class HistoricalNestedConfigurationCanaryTest extends TestCase
{
    use ProductionIdentityFixture;

    public function test_original_closure_never_resolves_nested_configuration_callbacks(): void
    {
        $this->identitySetup();
        config(['production-customer-identity.historical_receipts_enabled' => true,
            'production-customer-identity.historical_receipts_version' => 'identity-historical-committed-receipt-v1']);
        $owner = $this->enrollThroughLocalSmtp();
        $pdo = DB::connection()->getPdo();
        $pdo->exec('CREATE TABLE identity_nested_callback_fixture (id INTEGER PRIMARY KEY, value INTEGER NOT NULL)');
        $pdo->exec('INSERT INTO identity_nested_callback_fixture (id,value) VALUES (1,9123)');
        $repo = app('config');
        $prop = new ReflectionProperty(Repository::class, 'items');
        $before = $prop->getValue($repo);
        $items = $before;
        $parent = new HistoricalNestedConfigurationParent($items['app'], $pdo);
        $items['app'] = $parent;
        $prop->setValue($repo, $items);
        $frame = null;
        $closed = false;
        $refusal = null;
        try {
            $frame = DB::transaction(function () use ($owner): IdentityHistoricalCommitFixture {
                $reader = new CurrentRows(DB::connection()->getPdo(), DB::getDriverName());
                $raw = (new ProductionCustomerAccess)->verifyHistoricalBinding($owner['binding'], $reader);

                return IdentityHistoricalCommitFixture::capture($owner['binding'], $reader, $raw, hrtime(true) + 300_000_000_000);
            });
            $parent->armed = true;
            try {
                $frame->receipt(0)->proveClosed();
                $closed = true;
            } catch (IdentityException $error) {
                $refusal = $error->reason;
            }
            $value = (int) $pdo->query('SELECT value FROM identity_nested_callback_fixture WHERE id=1')->fetchColumn();
            file_put_contents(__DIR__.'/nested-snapshot.json', json_encode([
                'source' => 'dc17abcd3a60a0c47bd1247b52f239caede912c4',
                'callbacks' => $parent->calls, 'fixture_value' => $value,
                'receipt_closed' => $closed, 'refusal' => $refusal, 'active_transaction' => $pdo->inTransaction(),
            ], JSON_PRETTY_PRINT)."\n");
            $this->assertSame(0, $parent->calls, 'Raw configuration parent lookup invoked an actual ArrayAccess callback after commit.');
            $this->assertSame(9123, $value);
            $this->assertFalse($pdo->inTransaction());
        } finally {
            $parent->armed = false;
            $frame?->restore();
            $prop->setValue($repo, $before);
            $pdo->exec('DROP TABLE identity_nested_callback_fixture');
        }
    }
}

final class HistoricalNestedConfigurationParent extends ArrayObject
{
    public bool $armed = false;

    public int $calls = 0;

    public function __construct(array $items, private readonly PDO $primary)
    {
        parent::__construct($items);
    }

    public function offsetGet(mixed $key): mixed
    {
        if ($this->armed) {
            $this->calls++;
            $this->primary->exec('UPDATE identity_nested_callback_fixture SET value=value+10 WHERE id=1');
        }

        return parent::offsetGet($key);
    }
}
