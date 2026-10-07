<?php

namespace Tests\Feature\ProductionIdentityAdapters;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Customers\ProductionCustomerAccess;
use Illuminate\Config\Repository;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\Support\IdentityHistoricalCommitFixture;
use Tests\Support\ProductionIdentityFixture;
use Tests\TestCase;

/** An actual resolved configuration extension must not execute after the original source's final raw fence. */
final class HistoricalCommittedConfigurationCallbackCanaryTest extends TestCase
{
    use ProductionIdentityFixture;

    public function test_original_closed_prefix_does_not_invoke_configuration_callbacks_after_the_original_commit(): void
    {
        $this->identitySetup();
        config(['production-customer-identity.historical_receipts_enabled' => true,
            'production-customer-identity.historical_receipts_version' => 'identity-historical-committed-receipt-v1']);
        $owner = $this->enrollThroughLocalSmtp();
        $pdo = DB::connection()->getPdo();
        $pdo->exec('CREATE TABLE identity_closed_callback_fixture (id INTEGER PRIMARY KEY, value INTEGER NOT NULL)');
        $pdo->exec('INSERT INTO identity_closed_callback_fixture (id,value) VALUES (1,9123)');
        $original = app('config');
        $extension = new HistoricalCommittedConfigurationExtension($original->all(), $pdo);
        app()->instance('config', $extension);
        $frame = null;
        try {
            $frame = DB::transaction(function () use ($owner): IdentityHistoricalCommitFixture {
                $reader = new CurrentRows(DB::connection()->getPdo(), DB::getDriverName());
                $raw = (new ProductionCustomerAccess)->verifyHistoricalBinding($owner['binding'], $reader);

                return IdentityHistoricalCommitFixture::capture($owner['binding'], $reader, $raw, hrtime(true) + 300_000_000_000);
            });
            $extension->armed = true;
            $frame->receipt(0)->proveClosed();
            $this->assertSame(0, $extension->calls, 'Original closure must use fixed raw rows, not callback-capable configuration/crypto helpers.');
            $this->assertSame(9123, (int) $pdo->query('SELECT value FROM identity_closed_callback_fixture WHERE id=1')->fetchColumn());
            $this->assertFalse($pdo->inTransaction());
        } finally {
            $extension->armed = false;
            $frame?->restore();
            app()->instance('config', $original);
            $pdo->exec('DROP TABLE identity_closed_callback_fixture');
        }
    }
}

final class HistoricalCommittedConfigurationExtension extends Repository
{
    public bool $armed = false;

    public int $calls = 0;

    public function __construct(array $items, private readonly PDO $primary)
    {
        parent::__construct($items);
    }

    public function get($key, $default = null)
    {
        if ($this->armed) {
            $this->calls++;
            $this->primary->exec('UPDATE identity_closed_callback_fixture SET value=value+10 WHERE id=1');
        }

        return parent::get($key, $default);
    }
}
