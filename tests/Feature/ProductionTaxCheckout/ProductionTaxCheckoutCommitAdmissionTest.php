<?php

namespace Tests\Feature\ProductionTaxCheckout;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\Evidence;
use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutSchema;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionTaxCheckoutFixtures;
use Tests\TestCase;

/** NEW Tax255 writes must survive the ordinary committing delegates before physical admission. */
class ProductionTaxCheckoutCommitAdmissionTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use ProductionTaxCheckoutFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('c', 32)),
            'production-customer-identity.enabled' => true, 'production-customer-identity.provenance' => IdentityPolicy::REHEARSAL,
            'production-customer-identity.public_origin' => 'http://localhost', 'production-customer-identity.notifications_enabled' => true,
            'production-customer-identity.transport_capability' => LoopbackSmtp::CAPABILITY]);
        Queue::fake();
    }

    public static function writes(): array
    {
        return ['order' => ['order'], 'request' => ['request'], 'binding' => ['binding'], 'reviewed' => ['reviewed']];
    }

    public static function retainedWrites(): array
    {
        return ['binding' => ['binding'], 'reviewed' => ['reviewed']];
    }

    private function fixture(string $kind): array
    {
        $fixture = $this->taxOrder();
        if ($kind === 'binding') {
            try {
                $fixture['checkout']->initiate($fixture['buyer']['principal'], $fixture['buyer']['user'], $fixture['order']['orderId']);
                $this->fail('The unbound setup must retain only a request.');
            } catch (CheckoutException $error) {
                $this->assertSame(['provider_unbound', 503], [$error->reason, $error->status]);
            }
        }
        self::configureTax(self::SYNTHETIC_PROVIDER);
        if ($kind === 'reviewed') {
            $fixture['checkout']->initiate($fixture['buyer']['principal'], $fixture['buyer']['user'], $fixture['order']['orderId']);
            $fixture['transport']->paid = true;
        }

        return $fixture;
    }

    private function write(array $fixture, string $kind): array
    {
        if ($kind === 'order') {
            return $fixture['checkout']->order($fixture['buyer']['principal'], $fixture['buyer']['user'], $fixture['catalog']['candidate']->id,
                $fixture['catalog']['items'], $fixture['preview']['previewHash'], true,
                ['legalName' => 'Declared synthetic buyer'], 'tax-commit-admission-order');
        }
        if ($kind === 'reviewed') {
            return $fixture['checkout']->reconcile($fixture['buyer']['principal'], $fixture['buyer']['user'], $fixture['order']['orderId']);
        }

        return $fixture['checkout']->initiate($fixture['buyer']['principal'], $fixture['buyer']['user'], $fixture['order']['orderId']);
    }

    /** Fixed counts and digests keep failure output free of private retained bodies. */
    private function snapshot(): array
    {
        $snapshot = [];
        foreach (TaxCheckoutSchema::TABLES as $table) {
            $rows = DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
            $snapshot[$table] = ['count' => count($rows), 'digest' => CanonicalJson::hash($rows)];
        }

        return $snapshot;
    }

    #[DataProvider('writes')]
    public function test_late_configuration_withdrawal_rolls_back_each_new_write_and_its_callback_before_a_valid_retry(string $kind): void
    {
        $fixture = $this->fixture($kind);
        $before = $this->snapshot();
        $table = TaxCheckoutSchema::TABLES[$kind];
        $connection = DB::connection();
        $primary = $connection->getPdo();
        $dispatcher = $connection->getEventDispatcher();
        $hits = 0;
        $afterCommit = 0;
        $dispatcher->listen(TransactionCommitting::class, function (TransactionCommitting $event) use ($connection, $primary, $table, $before, &$hits, &$afterCommit): void {
            if ($hits === 0 && $event->connection === $connection && $connection->transactionLevel() === 1
                && (int) $primary->query('SELECT COUNT(*) FROM '.$table)->fetchColumn() === $before[$table]['count'] + 1) {
                $hits++;
                $connection->afterCommit(function () use (&$afterCommit): void {
                    $afterCommit++;
                });
                config(['production-tax-checkout.enabled' => false]);
            }
        });
        $refusal = null;
        try {
            $this->write($fixture, $kind);
        } catch (CheckoutException $error) {
            $refusal = $error;
        } finally {
            $dispatcher->forget(TransactionCommitting::class);
        }
        $this->assertSame(1, $hits, 'The genuine ordinary commit delegate observed the staged target row.');
        $this->assertSame($before[$table]['count'], DB::table($table)->count(), 'A withdrawn NEW write reached the physical database.');
        $this->assertSame($before, $this->snapshot(), 'Refusal preserves every previously retained family row.');
        $this->assertInstanceOf(CheckoutException::class, $refusal);
        $this->assertSame(503, $refusal->status);
        $this->assertSame(0, $afterCommit);
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($primary->inTransaction());
        $this->assertSame($dispatcher, $connection->getEventDispatcher());

        self::configureTax(self::SYNTHETIC_PROVIDER);
        $result = $this->write($fixture, $kind);
        $this->assertSame($before[$table]['count'] + 1, DB::table($table)->count());
        $this->assertSame(0, $afterCommit, 'The refused callback never runs on a later valid commit.');
        $this->assertDatabaseCount('paid_order_origins', 0);
        $this->assertDatabaseCount('license_grants', 0);
        if ($kind === 'reviewed') {
            $this->assertSame('verified', $result['paymentStatus']);
        } elseif ($kind !== 'order') {
            $this->assertSame('unverified', $result['paymentStatus']);
        }
    }

    #[DataProvider('retainedWrites')]
    public function test_historical_provider_evidence_survives_a_committing_password_change_even_if_current_status_refuses(string $kind): void
    {
        $fixture = $this->fixture($kind);
        $table = TaxCheckoutSchema::TABLES[$kind];
        $before = DB::table($table)->count();
        $connection = DB::connection();
        $primary = $connection->getPdo();
        $dispatcher = $connection->getEventDispatcher();
        $hits = 0;
        $password = password_hash('NONBINDING-REHEARSAL-CHANGE', PASSWORD_BCRYPT);
        $dispatcher->listen(TransactionCommitting::class, function (TransactionCommitting $event) use ($fixture, $connection, $primary, $table, $before, $password, &$hits): void {
            if ($hits === 0 && $event->connection === $connection && $connection->transactionLevel() === 1
                && (int) $primary->query('SELECT COUNT(*) FROM '.$table)->fetchColumn() === $before + 1) {
                $hits++;
                $statement = $primary->prepare('UPDATE users SET password = ? WHERE id = ?');
                $statement->execute([$password, $fixture['buyer']['user']->id]);
            }
        });
        try {
            $this->write($fixture, $kind);
        } catch (IdentityException) {
            // A stale principal may suppress the later status response; it cannot undo verified historical evidence.
        } finally {
            $dispatcher->forget(TransactionCommitting::class);
        }
        $this->assertSame(1, $hits);
        $this->assertSame($before + 1, DB::table($table)->count());
        $this->assertSame($password, DB::table('users')->where('id', $fixture['buyer']['user']->id)->value('password'));
        if ($kind === 'reviewed') {
            $reviewed = Evidence::open((array) DB::table($table)->sole(), 'production_tax_checkout_reviewed_session');
            $this->assertSame('on_time', $reviewed['outcome']);
            $this->assertSame(5436, $reviewed['amounts']['total_minor']);
        }
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($primary->inTransaction());
        $this->assertSame($dispatcher, $connection->getEventDispatcher());
        $this->assertDatabaseCount('paid_order_origins', 0);
        $this->assertDatabaseCount('license_grants', 0);
    }

    public function test_late_paid_exception_retains_the_original_observation_after_current_offer_withdrawal_and_order_expiry(): void
    {
        $fixture = $this->fixture('reviewed');
        $order = Evidence::open((array) DB::table(TaxCheckoutSchema::TABLES['order'])->sole(), 'production_tax_checkout_order');
        $at = CarbonImmutable::parse($order['expires_at'], 'UTC')->addSecond();
        $hits = 0;
        $fixture['transport']->mutateSession = function (array $session) use ($fixture, $at, &$hits): array {
            $hits++;
            DB::table('offers')->where('track_id', $fixture['catalog']['items'][0]['trackId'])->update(['is_active' => false]);
            CarbonImmutable::setTestNow($at);

            return $session;
        };
        try {
            $result = $this->write($fixture, 'reviewed');
        } finally {
            CarbonImmutable::setTestNow();
        }
        $this->assertSame(1, $hits);
        $this->assertSame(['verified', 'paid_exception'], [$result['paymentStatus'], $result['fulfillmentStatus']]);
        $this->assertDatabaseCount(TaxCheckoutSchema::TABLES['reviewed'], 1);
        $reviewed = Evidence::open((array) DB::table(TaxCheckoutSchema::TABLES['reviewed'])->sole(), 'production_tax_checkout_reviewed_session');
        $this->assertSame([$at->format('Y-m-d\TH:i:s\Z'), 'paid_exception', 5436],
            [$reviewed['observed_at'], $reviewed['outcome'], $reviewed['amounts']['total_minor']]);
        $this->assertDatabaseCount('paid_order_origins', 0);
        $this->assertDatabaseCount('license_grants', 0);
    }
}
