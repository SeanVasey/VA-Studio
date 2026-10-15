<?php

namespace Tests\Feature\ProductionTaxCheckout;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\CommandTransaction;
use App\Domain\Commerce\ProductionCheckout\Evidence;
use App\Domain\Commerce\ProductionCheckout\Records;
use App\Domain\Commerce\ProductionPolicy\PrepareProductionTrackCapabilities;
use App\Domain\Commerce\ProductionPolicy\ReviewProductionTrackCapabilities;
use App\Domain\Commerce\ProductionPolicy\SaveProductionTrackCapabilities;
use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxCheckout;
use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutEvidence;
use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutRawPlans;
use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutSchema;
use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutTransport;
use App\Domain\Commerce\ProductionTaxCheckout\TaxExecutionContext;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionTaxCheckoutFixtures;
use Tests\Support\ProductionTrackPreparationFixtures;
use Tests\TestCase;

/** NEW Tax255 writes must survive the ordinary committing delegates before physical admission. */
class ProductionTaxCheckoutCommitAdmissionTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use ProductionTaxCheckoutFixtures {
        taxCatalog as baseTaxCatalog;
    }

    private ?string $admissionLicenseUntil = null;

    /** Publish the bounded synthetic license through the actual lifecycle; never mutate frozen terms. */
    protected function taxCatalog(string $behavior = 'exclusive', int $ceiling = 2500): array
    {
        if ($this->admissionLicenseUntil === null) {
            return $this->baseTaxCatalog($behavior, $ceiling);
        }
        self::configureTax();
        $fixture = ProductionTrackPreparationFixtures::prepared(licenseUntil: $this->admissionLicenseUntil);
        $machine = self::taxMachine($behavior, $ceiling);
        $candidate = app(SaveProductionTrackCapabilities::class)->applyReviewed(
            app(PrepareProductionTrackCapabilities::class)->review($fixture['candidate'], $fixture['source'], $machine, $fixture['actor']), $fixture['actor']);
        app(ReviewProductionTrackCapabilities::class)->applyReviewed(app(ReviewProductionTrackCapabilities::class)->review($candidate, $fixture['reviewer']),
            ProductionTrackPreparationFixtures::reference('software_choices_reviewed'), $fixture['reviewer']);

        return array_replace($fixture, compact('machine', 'candidate'));
    }

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

    public static function currentWithdrawals(): array
    {
        return ['order catalog' => ['order', 'catalog'], 'request catalog' => ['request', 'catalog'],
            'order credential' => ['order', 'credential'], 'request credential' => ['request', 'credential']];
    }

    public static function currentWrites(): array
    {
        return ['order' => ['order'], 'request' => ['request']];
    }

    #[DataProvider('currentWrites')]
    public function test_live_current_license_cap_expires_during_the_ordinary_commit_delegate_and_clears_its_callback(string $kind): void
    {
        $at = CarbonImmutable::now('UTC')->startOfSecond();
        $this->admissionLicenseUntil = $at->addSeconds(5)->format('Y-m-d H:i:s');
        // Wall time is fixed during fixture assembly. Expiry below is real monotonic elapsed time.
        CarbonImmutable::setTestNow($at);
        $connection = DB::connection();
        $primary = $connection->getPdo();
        $dispatcher = $connection->getEventDispatcher();
        $hits = 0;
        $afterCommit = 0;
        $elapsed = 0;
        try {
            $fixture = $this->fixture($kind);
            $before = $this->snapshot();
            $table = TaxCheckoutSchema::TABLES[$kind];
            $dispatcher->listen(TransactionCommitting::class, function (TransactionCommitting $event) use ($connection, $primary, $table, $before, &$hits, &$afterCommit, &$elapsed): void {
                if ($hits === 0 && $event->connection === $connection && $connection->transactionLevel() === 1
                    && (int) $primary->query('SELECT COUNT(*) FROM '.$table)->fetchColumn() === $before[$table]['count'] + 1) {
                    $hits++;
                    $connection->afterCommit(function () use (&$afterCommit): void {
                        $afterCommit++;
                    });
                    $tick = hrtime(true);
                    usleep(5_100_000);
                    $elapsed = hrtime(true) - $tick;
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
            $this->assertSame(1, $hits);
            $this->assertGreaterThanOrEqual(5_000_000_000, $elapsed);
            $this->assertSame($at->format('U.u'), CarbonImmutable::now('UTC')->format('U.u'), 'The test did not advance its wall clock to cause refusal.');
            $this->assertInstanceOf(CheckoutException::class, $refusal);
            $this->assertSame('write_frame', $refusal->reason);
            $this->assertSame($before, $this->snapshot());
            $this->assertSame(0, $afterCommit);
            $this->assertSame(0, $connection->transactionLevel());
            $this->assertFalse($primary->inTransaction());
            $this->assertSame($dispatcher, $connection->getEventDispatcher());
        } finally {
            $dispatcher->forget(TransactionCommitting::class);
            CarbonImmutable::setTestNow();
            $this->admissionLicenseUntil = null;
        }
        // A new independently admitted catalog/order is valid; the expired immutable license is never extended.
        $catalog = $this->taxCatalog();
        self::configureTax(self::SYNTHETIC_PROVIDER);
        $preview = $fixture['checkout']->preview($fixture['buyer']['principal'], $fixture['buyer']['user'], $catalog['candidate']->id, $catalog['items']);
        $valid = array_replace($fixture, compact('catalog', 'preview'));
        if ($kind === 'request') {
            $valid['order'] = $fixture['checkout']->order($fixture['buyer']['principal'], $fixture['buyer']['user'], $catalog['candidate']->id,
                $catalog['items'], $preview['previewHash'], true, ['legalName' => 'Declared synthetic buyer'], 'tax-deadline-valid-retry-order');
        }
        $count = DB::table(TaxCheckoutSchema::TABLES[$kind])->count();
        $this->write($valid, $kind);
        $this->assertSame($count + 1, DB::table(TaxCheckoutSchema::TABLES[$kind])->count());
        $this->assertSame(0, $afterCommit);
        $this->assertDatabaseCount('paid_order_origins', 0);
        $this->assertDatabaseCount('license_grants', 0);
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
    public function test_historical_owner_key_withdrawal_in_a_committing_delegate_rolls_back_new_provider_evidence(string $kind): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Transactional guard replacement fixture is SQLite-only; MySQL DDL would implicitly commit.');
        }
        $fixture = $this->fixture($kind);
        $before = $this->snapshot();
        $table = TaxCheckoutSchema::TABLES[$kind];
        $account = DB::table('customer_accounts')->where('user_id', $fixture['buyer']['user']->id)->sole();
        // Synthetic guard replacement only: healthy immutable account guards prevent this mutation.
        $otherOwner = (array) DB::table('quote_owners')->where('owner_key', $account->owner_key)->sole();
        $otherOwner['owner_key'] = hash('sha256', 'NONBINDING-OWNER-WITHDRAWAL');
        DB::table('quote_owners')->insert($otherOwner);
        $guard = DB::table('sqlite_master')->where('name', 'customer_accounts_update')->value('sql');
        $connection = DB::connection();
        $primary = $connection->getPdo();
        $dispatcher = $connection->getEventDispatcher();
        $hits = 0;
        $dispatcher->listen(TransactionCommitting::class, function (TransactionCommitting $event) use ($account, $guard, $connection, $primary, $table, $before, &$hits): void {
            if ($hits === 0 && $event->connection === $connection && $connection->transactionLevel() === 1
                && (int) $primary->query('SELECT COUNT(*) FROM '.$table)->fetchColumn() === $before[$table]['count'] + 1) {
                $hits++;
                // SQLite DDL is transactional; restore the identical guard before the final proof.
                $primary->exec('DROP TRIGGER customer_accounts_update');
                $statement = $primary->prepare('UPDATE customer_accounts SET owner_key = ? WHERE id = ?');
                $statement->execute([hash('sha256', 'NONBINDING-OWNER-WITHDRAWAL'), $account->id]);
                $primary->exec($guard);
            }
        });
        $refusal = null;
        try {
            $this->write($fixture, $kind);
        } catch (CheckoutException|IdentityException $error) {
            $refusal = $error;
        } finally {
            $dispatcher->forget(TransactionCommitting::class);
        }
        $this->assertSame(1, $hits);
        $this->assertSame($before, $this->snapshot(), 'Historical ownership drift must refuse before retaining new evidence.');
        $this->assertInstanceOf(CheckoutException::class, $refusal);
        $this->assertSame(['write_source_changed', 409], [$refusal->reason, $refusal->status]);
        $this->assertSame($account->owner_key, DB::table('customer_accounts')->where('id', $account->id)->value('owner_key'));
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($primary->inTransaction());
        $this->assertSame($dispatcher, $connection->getEventDispatcher());
        $result = $this->write($fixture, $kind);
        $this->assertSame($before[$table]['count'] + 1, DB::table($table)->count());
        $this->assertSame($kind === 'reviewed' ? 'verified' : 'unverified', $result['paymentStatus']);
    }

    public function test_new_request_preserves_the_inclusive_creation_time_provider_lifetime_boundary(): void
    {
        $fixture = $this->taxOrder();
        $order = Evidence::open((array) DB::table(TaxCheckoutSchema::TABLES['order'])->sole(), 'production_tax_checkout_order');
        $at = CarbonImmutable::parse($order['expires_at'], 'UTC')->subSeconds($fixture['catalog']['machine']['choices']['reservation_and_exclusives']['provider_lifetime_seconds']);
        self::configureTax();
        CarbonImmutable::setTestNow($at);
        $refusal = null;
        try {
            $fixture['checkout']->initiate($fixture['buyer']['principal'], $fixture['buyer']['user'], $fixture['order']['orderId']);
        } catch (CheckoutException $error) {
            $refusal = $error;
        } finally {
            CarbonImmutable::setTestNow();
        }
        $this->assertSame(1, DB::table(TaxCheckoutSchema::TABLES['request'])->count(), 'A valid creation-time equality must retain the original request.');
        $this->assertInstanceOf(CheckoutException::class, $refusal);
        $this->assertSame(['provider_unbound', 503], [$refusal->reason, $refusal->status]);
        $row = (array) DB::table(TaxCheckoutSchema::TABLES['request'])->sole();
        $request = Evidence::open($row, 'production_tax_checkout_provider_request');
        $this->assertSame($at->format('Y-m-d\\TH:i:s\\Z'), $row['created_at']);
        $this->assertSame($order['expires_at'], $request['provider_expires_at']);
        $this->assertSame([], $fixture['transport']->calls);
    }

    #[DataProvider('retainedWrites')]
    public function test_global_provider_identity_reuse_in_an_unrelated_request_refuses_before_commit(string $kind): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Synthetic transactional index replacement is SQLite-only; MySQL DDL would implicitly commit.');
        }
        $fixture = $this->fixture($kind);
        $other = $fixture['checkout']->order($fixture['buyer']['principal'], $fixture['buyer']['user'], $fixture['catalog']['candidate']->id,
            $fixture['catalog']['items'], $fixture['preview']['previewHash'], true, ['legalName' => 'Declared synthetic buyer'], 'tax-family-closure-secondary');
        self::configureTax();
        try {
            $fixture['checkout']->initiate($fixture['buyer']['principal'], $fixture['buyer']['user'], $other['orderId']);
            $this->fail('Secondary fixture must retain an unbound request.');
        } catch (CheckoutException $error) {
            $this->assertSame(['provider_unbound', 503], [$error->reason, $error->status]);
        }
        self::configureTax(self::SYNTHETIC_PROVIDER);
        $otherOrder = DB::table(TaxCheckoutSchema::TABLES['order'])->where('public_id', $other['orderId'])->sole();
        $otherRequest = DB::table(TaxCheckoutSchema::TABLES['request'])->where('order_id', $otherOrder->id)->sole();
        $before = $this->snapshot();
        $table = TaxCheckoutSchema::TABLES[$kind];
        $connection = DB::connection();
        $primary = $connection->getPdo();
        $dispatcher = $connection->getEventDispatcher();
        $hits = 0;
        $index = $kind === 'binding' ? 'ptx_b_u1' : 'ptx_s_u3';
        $dispatcher->listen(TransactionCommitting::class, function (TransactionCommitting $event) use ($kind, $otherOrder, $otherRequest, $connection, $primary, $table, $before, $index, &$hits): void {
            if ($hits === 0 && $event->connection === $connection && $connection->transactionLevel() === 1
                && (int) $primary->query('SELECT COUNT(*) FROM '.$table)->fetchColumn() === $before[$table]['count'] + 1) {
                $hits++;
                // Declared damaged-index fixture. Raw conflicting projections are never provider facts.
                $primary->exec('DROP INDEX '.$index);
                $target = $primary->query('SELECT * FROM '.$table.' ORDER BY id DESC LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
                $binding = $kind === 'binding' ? $target
                    : $primary->query('SELECT * FROM '.TaxCheckoutSchema::TABLES['binding'].' WHERE id = '.(int) $target['binding_id'])->fetch(\PDO::FETCH_ASSOC);
                unset($binding['id']);
                $binding['public_id'] = (string) Str::uuid();
                $binding['request_id'] = $otherRequest->id;
                if ($kind === 'reviewed') {
                    $binding['provider_session_id'] = 'cs_test_OTHERRETENTION';
                    $binding['created_at'] = $target['observed_at'];
                }
                $sql = $primary->prepare('INSERT INTO '.TaxCheckoutSchema::TABLES['binding'].' ('.implode(', ', array_keys($binding))
                    .') VALUES ('.implode(', ', array_fill(0, count($binding), '?')).')');
                $sql->execute(array_values($binding));
                if ($kind === 'reviewed') {
                    unset($target['id']);
                    $target['public_id'] = (string) Str::uuid();
                    $target['binding_id'] = (int) $primary->lastInsertId();
                    $target['request_id'] = $otherRequest->id;
                    $target['order_id'] = $otherOrder->id;
                    $target['provider_session_id'] = $binding['provider_session_id'];
                    $sql = $primary->prepare('INSERT INTO '.$table.' ('.implode(', ', array_keys($target))
                        .') VALUES ('.implode(', ', array_fill(0, count($target), '?')).')');
                    $sql->execute(array_values($target));
                }
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
        $this->assertSame(1, $hits);
        $this->assertInstanceOf(CheckoutException::class, $refusal);
        $this->assertSame('write_source_changed', $refusal->reason);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(1, DB::table('sqlite_master')->where('type', 'index')->where('name', $index)->count(), 'Refusal also restores the transactional index change.');
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($primary->inTransaction());
        $this->assertSame($dispatcher, $connection->getEventDispatcher());
        $this->write($fixture, $kind);
        $this->assertSame($before[$table]['count'] + 1, DB::table($table)->count());
        $this->assertDatabaseCount('license_grants', 0);
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

    #[DataProvider('currentWithdrawals')]
    public function test_current_credential_or_catalog_withdrawal_refuses_new_order_and_request_before_a_clean_retry(string $kind, string $withdrawal): void
    {
        $fixture = $this->fixture($kind);
        $before = $this->snapshot();
        $table = TaxCheckoutSchema::TABLES[$kind];
        $connection = DB::connection();
        $primary = $connection->getPdo();
        $dispatcher = $connection->getEventDispatcher();
        $hits = 0;
        $password = DB::table('users')->where('id', $fixture['buyer']['user']->id)->value('password');
        $offer = DB::table('offers')->where('track_id', $fixture['catalog']['items'][0]['trackId'])->sole();
        $dispatcher->listen(TransactionCommitting::class, function (TransactionCommitting $event) use ($fixture, $connection, $primary, $table, $before, $withdrawal, $offer, &$hits): void {
            if ($hits === 0 && $event->connection === $connection && $connection->transactionLevel() === 1
                && (int) $primary->query('SELECT COUNT(*) FROM '.$table)->fetchColumn() === $before[$table]['count'] + 1) {
                $hits++;
                $statement = $primary->prepare($withdrawal === 'catalog'
                    ? 'UPDATE offers SET is_active = 0 WHERE id = ?' : 'UPDATE users SET password = ? WHERE id = ?');
                $statement->execute($withdrawal === 'catalog' ? [$offer->id]
                    : [password_hash('NONBINDING-CURRENT-WITHDRAWAL', PASSWORD_BCRYPT), $fixture['buyer']['user']->id]);
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
        $this->assertSame(1, $hits);
        $this->assertInstanceOf(CheckoutException::class, $refusal);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame($password, DB::table('users')->where('id', $fixture['buyer']['user']->id)->value('password'));
        $this->assertSame(1, (int) DB::table('offers')->where('id', $offer->id)->value('is_active'));
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($primary->inTransaction());
        $this->assertSame($dispatcher, $connection->getEventDispatcher());
        $this->write($fixture, $kind);
        $this->assertSame($before[$table]['count'] + 1, DB::table($table)->count());
        $this->assertDatabaseCount('paid_order_origins', 0);
        $this->assertDatabaseCount('license_grants', 0);
    }

    public function test_paid_review_retains_the_admitted_transport_identity_from_before_provider_reads(): void
    {
        $fixture = $this->fixture('reviewed');
        $transport = new class($fixture['transport']) implements TaxCheckoutTransport
        {
            public bool $read = false;

            public int $identities = 0;

            public function __construct(private TaxCheckoutTransport $delegate) {}

            public function boundTo(): string
            {
                $this->identities++;

                return $this->read ? 'NONBINDING-CHANGED-IDENTITY' : $this->delegate->boundTo();
            }

            public function create(TaxExecutionContext $context, array $params, string $idempotencyKey): array
            {
                return $this->delegate->create($context, $params, $idempotencyKey);
            }

            public function retrieve(TaxExecutionContext $context, string $sessionId): array
            {
                $this->read = true;

                return $this->delegate->retrieve($context, $sessionId);
            }

            public function paymentIntent(TaxExecutionContext $context, string $paymentId): array
            {
                return $this->delegate->paymentIntent($context, $paymentId);
            }
        };
        $checkout = new ProductionTaxCheckout($fixture['access'], $transport);
        $result = $checkout->reconcile($fixture['buyer']['principal'], $fixture['buyer']['user'], $fixture['order']['orderId']);
        $reviewed = Evidence::open((array) DB::table(TaxCheckoutSchema::TABLES['reviewed'])->sole(), 'production_tax_checkout_reviewed_session');
        $this->assertSame('verified', $result['paymentStatus']);
        $this->assertSame(self::SYNTHETIC_PROVIDER, $reviewed['transport']);
        $this->assertSame(2, $transport->identities);
        $this->assertDatabaseCount('license_grants', 0);
    }

    #[DataProvider('writes')]
    public function test_native_nontransactional_owned_table_refuses_before_any_new_write_even_with_a_misleading_comment(string $kind): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires the private MySQL server and structured storage-engine metadata.');
        }
        $fixture = $this->fixture($kind);
        $before = $this->snapshot();
        $primary = DB::connection()->getPdo();
        $wait = (int) $primary->query('SELECT @@session.lock_wait_timeout')->fetchColumn();
        $bad = TaxCheckoutSchema::TABLES['reviewed'];
        // Explicit disposable damaged-restore fixture, outside an application transaction.
        foreach (['binding_id', 'request_id', 'order_id'] as $field) {
            $primary->exec('ALTER TABLE '.$bad.' DROP FOREIGN KEY ptx_s_f_'.$field);
        }
        $primary->exec('ALTER TABLE '.$bad." ENGINE=MyISAM COMMENT='restore annotation ENGINE=InnoDB'");
        $probe = $primary->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $probe->execute([$bad]);
        $this->assertSame('MyISAM', $probe->fetchColumn());
        $this->assertStringContainsString('ENGINE=InnoDB', $primary->query('SHOW CREATE TABLE '.$bad)->fetch(\PDO::FETCH_NUM)[1]);
        $refusal = null;
        try {
            $this->write($fixture, $kind);
        } catch (CheckoutException $error) {
            $refusal = $error;
        }
        $this->assertInstanceOf(CheckoutException::class, $refusal);
        $this->assertSame(['permanent_schema', 409], [$refusal->reason, $refusal->status]);
        $this->assertSame($before, $this->snapshot(), 'Damaged storage must refuse before target DML.');
        $this->assertSame($wait, (int) $primary->query('SELECT @@session.lock_wait_timeout')->fetchColumn());
        $this->assertSame(0, DB::connection()->transactionLevel());
        $this->assertFalse($primary->inTransaction());
        $this->assertDatabaseCount('paid_order_origins', 0);
        $this->assertDatabaseCount('license_grants', 0);
    }

    public function test_native_global_binding_capture_reads_a_peer_commit_after_the_repeatable_read_snapshot(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires native repeatable-read snapshots and a distinct committed MySQL peer.');
        }
        $fixture = $this->fixture('binding');
        $other = $fixture['checkout']->order($fixture['buyer']['principal'], $fixture['buyer']['user'], $fixture['catalog']['candidate']->id,
            $fixture['catalog']['items'], $fixture['preview']['previewHash'], true, ['legalName' => 'Declared synthetic buyer'], 'tax-native-peer-secondary');
        self::configureTax();
        try {
            $fixture['checkout']->initiate($fixture['buyer']['principal'], $fixture['buyer']['user'], $other['orderId']);
            $this->fail('Secondary fixture must retain an unbound request.');
        } catch (CheckoutException $error) {
            $this->assertSame(['provider_unbound', 503], [$error->reason, $error->status]);
        }
        self::configureTax(self::SYNTHETIC_PROVIDER);
        $otherOrder = DB::table(TaxCheckoutSchema::TABLES['order'])->where('public_id', $other['orderId'])->sole();
        $otherRequest = (array) DB::table(TaxCheckoutSchema::TABLES['request'])->where('order_id', $otherOrder->id)->sole();
        $connection = DB::connection();
        $primary = $connection->getPdo();
        $configuration = $connection->getConfig();
        $configuration['options'][\PDO::ATTR_PERSISTENT] = false;
        $peer = app('db.factory')->make($configuration)->getPdo();
        $this->assertNotSame($primary->query('SELECT CONNECTION_ID()')->fetchColumn(), $peer->query('SELECT CONNECTION_ID()')->fetchColumn());
        $this->assertSame($primary->query('SELECT DATABASE(), @@server_uuid')->fetch(\PDO::FETCH_NUM),
            $peer->query('SELECT DATABASE(), @@server_uuid')->fetch(\PDO::FETCH_NUM));
        // Declared damaged-restore condition, before the owned binding frame. No native implicit-DDL rollback claim.
        $primary->exec('ALTER TABLE '.TaxCheckoutSchema::TABLES['binding'].' DROP INDEX ptx_b_u1');
        $public = (string) Str::uuid();
        $created = CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z');
        $body = TaxCheckoutEvidence::binding(['row' => $otherRequest,
            'body' => Evidence::open($otherRequest, 'production_tax_checkout_provider_request')], $public, $created, 'cs_test_SYNTHETICTAX');
        $seed = ['public_id' => $public, 'created_at' => $created, ...Evidence::seal($body), 'request_id' => $otherRequest['id'],
            'account_id' => $otherRequest['account_id'], 'funds_mode' => $otherRequest['funds_mode'], 'provider_session_id' => 'cs_test_SYNTHETICTAX'];
        $dispatcher = $connection->getEventDispatcher();
        $hits = 0;
        $afterCommit = 0;
        $peerExpected = null;
        $isolation = null;
        $dispatcher->listen(TransactionBeginning::class, function (TransactionBeginning $event) use ($connection, $primary, $peer, $seed, &$hits, &$afterCommit, &$peerExpected, &$isolation): void {
            if ($hits !== 0 || $event->connection !== $connection) {
                return;
            }
            $bindingFrame = false;
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $call) {
                $bindingFrame = $bindingFrame || (($call['class'] ?? null) === ProductionTaxCheckout::class && ($call['function'] ?? null) === 'bind');
            }
            if (! $bindingFrame) {
                return;
            }
            $hits++;
            $this->assertSame(0, (int) $primary->query('SELECT COUNT(*) FROM '.TaxCheckoutSchema::TABLES['binding'])->fetchColumn());
            $isolation = $primary->query("SELECT e.ISOLATION_LEVEL FROM performance_schema.events_transactions_current e JOIN performance_schema.threads t ON t.THREAD_ID = e.THREAD_ID WHERE t.PROCESSLIST_ID = CONNECTION_ID() AND e.STATE = 'ACTIVE'")->fetchColumn();
            $statement = $peer->prepare('INSERT INTO '.TaxCheckoutSchema::TABLES['binding'].' ('.implode(', ', array_keys($seed))
                .') VALUES ('.implode(', ', array_fill(0, count($seed), '?')).')');
            $statement->execute(array_values($seed));
            $peerExpected = [];
            foreach (TaxCheckoutSchema::TABLES as $table) {
                $rows = $peer->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
                $peerExpected[$table] = ['count' => count($rows), 'digest' => CanonicalJson::hash($rows)];
            }
            $connection->afterCommit(function () use (&$afterCommit): void {
                $afterCommit++;
            });
        });
        $refusal = null;
        try {
            $this->write($fixture, 'binding');
        } catch (CheckoutException $error) {
            $refusal = $error;
        } finally {
            $dispatcher->forget(TransactionBeginning::class);
        }
        $this->assertSame(1, $hits);
        $this->assertSame('REPEATABLE READ', $isolation, 'This records the actual active transaction, not only its session default.');
        $this->assertSame(0, DB::table(TaxCheckoutSchema::TABLES['binding'])->where('request_id', DB::table(TaxCheckoutSchema::TABLES['request'])->where('order_id',
            DB::table(TaxCheckoutSchema::TABLES['order'])->where('public_id', $fixture['order']['orderId'])->value('id'))->value('id'))->count(),
            'A repeatable-read snapshot must not hide the committed peer from NEW global admission.');
        $this->assertInstanceOf(CheckoutException::class, $refusal);
        $this->assertSame('write_source_changed', $refusal->reason);
        $this->assertSame($peerExpected, $this->snapshot(), 'The independently committed peer is preserved; only the owned target refuses.');
        $this->assertSame(0, $afterCommit);
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($primary->inTransaction());
        $this->assertSame($dispatcher, $connection->getEventDispatcher());
        $this->assertDatabaseCount('license_grants', 0);
    }

    public function test_native_raw_plan_row_wait_is_bounded_restored_and_cannot_commit_staged_work_or_touch_a_peer_frame(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires a separately held native InnoDB row lock.');
        }
        $fixture = $this->fixture('reviewed');
        $binding = (array) DB::table(TaxCheckoutSchema::TABLES['binding'])->sole();
        $connection = DB::connection();
        $primary = $connection->getPdo();
        $configuration = $connection->getConfig();
        $configuration['options'][\PDO::ATTR_PERSISTENT] = false;
        $peer = app('db.factory')->make($configuration)->getPdo();
        $original = (int) $primary->query('SELECT @@session.innodb_lock_wait_timeout')->fetchColumn();
        $password = DB::table('users')->where('id', $fixture['buyer']['user']->id)->value('password');
        $afterCommit = 0;
        $refusal = null;
        $elapsed = 0;
        $restored = null;
        $peerActive = null;
        $primary->exec('SET SESSION innodb_lock_wait_timeout = 117');
        $peer->beginTransaction();
        $statement = $peer->prepare('SELECT id FROM '.TaxCheckoutSchema::TABLES['binding'].' WHERE id = ? FOR UPDATE');
        $statement->execute([$binding['id']]);
        try {
            $tick = hrtime(true);
            try {
                CommandTransaction::run(function (Records $rows) use ($fixture, $connection, $primary, $binding, &$afterCommit): void {
                    $connection->afterCommit(function () use (&$afterCommit): void {
                        $afterCommit++;
                    });
                    $statement = $primary->prepare('UPDATE users SET password = ? WHERE id = ?');
                    $statement->execute([hash('sha256', 'NONBINDING-ROW-WAIT-WORK'), $fixture['buyer']['user']->id]);
                    $plans = new TaxCheckoutRawPlans($rows->commandFrame());
                    $plans->plan(TaxCheckoutSchema::TABLES['binding'], 'id = ?', [$binding['id']], 2, [$binding]);
                    $plans->proveCurrent();
                });
            } catch (CheckoutException $error) {
                $refusal = $error;
            }
            $elapsed = hrtime(true) - $tick;
            $restored = (int) $primary->query('SELECT @@session.innodb_lock_wait_timeout')->fetchColumn();
            $peerActive = $peer->inTransaction();
        } finally {
            if ($peer->inTransaction()) {
                $peer->rollBack();
            }
            $primary->exec('SET SESSION innodb_lock_wait_timeout = '.$original);
        }
        $this->assertInstanceOf(CheckoutException::class, $refusal, 'A current raw proof cannot read through the separately held row lock.');
        $this->assertSame(['contention', 503], [$refusal->reason, $refusal->status]);
        $this->assertGreaterThanOrEqual(4_000_000_000, $elapsed);
        $this->assertLessThan(15_000_000_000, $elapsed, 'The captured row wait is bounded; this is not a general SQL-interruption claim.');
        $this->assertSame(117, $restored);
        $this->assertTrue($peerActive, 'Refusal must not roll back an independently owned peer transaction.');
        $this->assertSame($password, DB::table('users')->where('id', $fixture['buyer']['user']->id)->value('password'));
        $this->assertSame(0, $afterCommit);
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($primary->inTransaction());
        CommandTransaction::run(function (Records $rows) use ($binding): void {
            $plans = new TaxCheckoutRawPlans($rows->commandFrame());
            $plans->plan(TaxCheckoutSchema::TABLES['binding'], 'id = ?', [$binding['id']], 2, [$binding]);
            $plans->proveCurrent();
        });
        $this->assertSame(0, $afterCommit);
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
