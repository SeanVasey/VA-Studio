<?php

namespace Tests\Feature\ProductionFinancialRetrieval;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\CheckoutSchema;
use App\Domain\Commerce\ProductionCheckout\OriginalCommitDispatcher;
use App\Domain\Commerce\ProductionCheckout\ProductionPaidOrderLocatorV1;
use App\Domain\Commerce\ProductionCheckout\ProductionPaidOrderSourceV1;
use App\Domain\Commerce\ProductionFinancialRetrieval\FinancialReadPlan;
use App\Domain\Commerce\ProductionFinancialRetrieval\FinancialRetrievalException;
use App\Domain\Commerce\ProductionFinancialRetrieval\RetrievalPolicy;
use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxPaidOrderLocatorV2;
use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxPaidOrderSourceV2;
use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutSchema;
use App\Domain\Commerce\ProductionTaxCheckout\TaxPaidOriginalCommitDispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\Stripe;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\PaidTaxGrantFixtures;
use Tests\Support\ProductionCheckoutJourneyFixture;
use Tests\TestCase;

/** Genuine retained paid sources and stock SDK; transport replies are nonbinding synthetic arrays. */
final class FinancialReadPlanTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use PaidTaxGrantFixtures;
    use ProductionCheckoutJourneyFixture;

    private ClientInterface $previousTransport;

    private array $previousGlobals;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparePaidTaxFixture();
        $this->app->instance('env', 'testing');
        config(['production_checkout.fresh_checkout_enabled' => true, 'production_checkout.reconciliation_enabled' => true,
            'production_checkout.committed_read_receipts_enabled' => true,
            'production_checkout.committed_read_receipt_version' => 'production-checkout-committed-read-v1',
            'production-financial-inspection.enabled' => true, 'production-financial-retrieval.enabled' => true,
            'payments.stripe.mode' => 'test', 'payments.stripe.account_id' => 'acct_SYNTHETIC',
            'payments.stripe.secret_key' => 'sk_test_SYNTHETICFINANCIAL']);
        $this->previousTransport = ApiRequestor::httpClient();
        $this->previousGlobals = [Stripe::$accountId, Stripe::$verifySslCerts];
        Stripe::$accountId = null;
        Stripe::$verifySslCerts = true;
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient($this->previousTransport);
        [Stripe::$accountId, Stripe::$verifySslCerts] = $this->previousGlobals;
        $this->app->instance('env', 'testing');
        parent::tearDown();
    }

    private function paid(bool $tax): array
    {
        if ($tax) {
            return $this->paidTaxOrder(twoLines: true);
        }
        $f = $this->payable();
        $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $f['gateway']->paid = true;
        $this->assertSame('verified', $f['hosted']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId'])['paymentStatus']);

        return $f;
    }

    private function held(array $f, bool $tax, callable $use): mixed
    {
        $locator = $tax ? ProductionTaxPaidOrderLocatorV2::locate($f['order']['orderId'])
            : ProductionPaidOrderLocatorV1::locate($f['order']['orderId']);

        return DB::transaction(function () use ($f, $tax, $locator, $use) {
            $reader = new CurrentRows(DB::getPdo(), DB::getDriverName());
            $history = $f['access']->verifyHistoricalBinding($locator->historicalBuyerBinding(), $reader);
            $source = $tax ? ProductionTaxPaidOrderSourceV2::lockedRead($locator, $reader, $history)
                : ProductionPaidOrderSourceV1::lockedRead($locator, $reader, $history);

            return $use($source, $reader);
        });
    }

    private function makePlan(bool $tax = false, ?int $deadline = null): array
    {
        $f = $this->paid($tax);
        $delegate = DB::connection()->getEventDispatcher();
        [$plan, $line] = $this->held($f, $tax, function ($source, $reader) use ($tax, $deadline) {
            $line = $source->line(1);
            $plan = $tax ? FinancialReadPlan::fromTaxV2($source, $reader, $deadline ?? hrtime(true) + 60_000_000_000)
                : FinancialReadPlan::fromV1($source, $reader, $deadline ?? hrtime(true) + 60_000_000_000);

            return [$plan, $line];
        });

        return [$plan, $this->transport($line, $tax), $delegate, $f];
    }

    private function abandonHeld(array $f, bool $tax, callable $use): void
    {
        try {
            $this->held($f, $tax, static function ($source, $reader) use ($use): never {
                $use($source, $reader);
                throw new RuntimeException('Synthetic original caller cleanup.');
            });
            $this->fail('Original caller cleanup did not execute.');
        } catch (RuntimeException $error) {
            if ($error::class !== RuntimeException::class || $error->getMessage() !== 'Synthetic original caller cleanup.') {
                throw $error;
            }
            $this->addToAssertionCount(1);
        }
    }

    private function transport(array $line, bool $tax): RetrievalHttpFixture
    {
        $gross = $tax ? $line['tax']['order_total_minor'] : $line['amounts']['total_minor'];
        $payment = ['id' => $line['provider_payment_id'], 'object' => 'payment_intent', 'livemode' => false,
            'currency' => 'usd', 'amount' => $gross, 'amount_received' => $gross, 'amount_capturable' => 0,
            'status' => 'succeeded', 'capture_method' => $line['execution_context']['capture_method'],
            'latest_charge' => 'ch_SYNTHETIC', 'client_secret' => 'NONBINDING_SYNTHETIC_PRIVATE'];
        $charge = ['id' => 'ch_SYNTHETIC', 'object' => 'charge', 'payment_intent' => $payment['id'], 'livemode' => false,
            'currency' => 'usd', 'amount' => $gross, 'amount_captured' => $gross, 'amount_refunded' => 0,
            'refunded' => false, 'paid' => true, 'captured' => true, 'status' => 'succeeded', 'disputed' => false];
        $list = ['object' => 'list', 'has_more' => false, 'data' => []];

        return new RetrievalHttpFixture([['object' => 'account', 'id' => $line['provider_account']], $payment, $charge, $list, $list, $charge]);
    }

    private function refuses(callable $operation, ?string $reason = null): void
    {
        try {
            $operation();
            $this->fail('Invalid original read plan was admitted.');
        } catch (FinancialRetrievalException $error) {
            if ($reason !== null) {
                $this->assertSame($reason, $error->reason);
            }
            $this->assertSame('PAID_FINANCIAL_RETRIEVAL_UNAVAILABLE', $error->getMessage());
            $this->assertNull($error->getPrevious());
        }
    }

    public static function kinds(): array
    {
        return ['v1' => [false], 'tax_v2' => [true]];
    }

    #[DataProvider('kinds')]
    public function test_original_positive_closure_surrounds_six_exact_sdk_test_gets_and_returns_only_an_unattested_candidate(bool $tax): void
    {
        [$plan, $http, $delegate] = $this->makePlan($tax);
        $this->assertNotSame($delegate, DB::connection()->getEventDispatcher());
        $result = $plan->readSynthetic($http)->summary();
        $this->assertSame('consistent_candidate', $result['inputState']);
        $this->assertFalse($result['providerAuthenticated']);
        $this->assertSame('unattested_input', $result['inputProvenance']);
        $this->assertSame($tax ? 9372 : 4999, $result['originalGrossMinor']);
        $this->assertCount(6, $http->calls);
        $this->assertSame(array_fill(0, 6, 0), array_column($http->calls, 'transaction_level'));
        $this->assertSame(array_fill(0, 6, 'get'), array_column($http->calls, 'method'));
        $this->assertSame(['account', 'payment_intents/'.($tax ? 'pi_SYNTHETICTAX' : 'pi_SYNTHETIC'),
            'charges/ch_SYNTHETIC', 'refunds', 'disputes', 'charges/ch_SYNTHETIC'], array_column($http->calls, 'path'));
        $this->assertSame($delegate, DB::connection()->getEventDispatcher());
        $this->assertSame($this->previousTransport, ApiRequestor::httpClient());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::getPdo()->inTransaction());
        $this->assertDatabaseCount('license_grants', 0);
        $this->refuses(fn () => $plan->readSynthetic($http), 'used');
        $this->assertCount(6, $http->calls);
        $plan->close();
        $plan->close();
    }

    #[DataProvider('kinds')]
    public function test_unused_plan_close_destruction_and_clone_refusal_settle_only_the_original_receipts(bool $tax): void
    {
        [$plan, $http, $delegate] = $this->makePlan($tax);
        try {
            $copy = clone $plan;
            $this->fail('A copied plan was admitted.');
        } catch (LogicException) {
            $this->assertNotSame($delegate, DB::connection()->getEventDispatcher(), 'Failed clone disposal touched the original receipts.');
        }
        foreach ([fn () => serialize($plan), fn () => json_encode($plan, JSON_THROW_ON_ERROR),
            fn () => unserialize('O:'.strlen(FinancialReadPlan::class).':"'.FinancialReadPlan::class.'":0:{}', ['allowed_classes' => [FinancialReadPlan::class]])] as $operation) {
            try {
                $operation();
                $this->fail('Plan reconstruction was admitted.');
            } catch (LogicException) {
                $this->assertNotSame($delegate, DB::connection()->getEventDispatcher());
            }
        }
        $plan->close();
        $this->assertSame($delegate, DB::connection()->getEventDispatcher());
        $this->refuses(fn () => $plan->readSynthetic($http), 'used');
        $this->assertCount(0, $http->calls);
        unset($plan);
    }

    #[DataProvider('kinds')]
    public function test_early_policy_refusal_drains_remaining_receipts_and_restores_the_saved_sdk_slot(bool $tax): void
    {
        [$plan, $http, $delegate] = $this->makePlan($tax);
        config(['production-financial-retrieval.enabled' => false]);
        $this->refuses(fn () => $plan->readSynthetic($http));
        $this->assertCount(0, $http->calls);
        $this->assertSame($delegate, DB::connection()->getEventDispatcher());
        $this->assertSame($this->previousTransport, ApiRequestor::httpClient());
        $this->refuses(fn () => $plan->readSynthetic($http), 'used');
    }

    #[DataProvider('kinds')]
    public function test_changed_original_rows_suppress_the_result_and_release_the_original_observer(bool $tax): void
    {
        [$plan, $http, $delegate] = $this->makePlan($tax);
        $primary = DB::getPdo();
        $driver = DB::getDriverName();
        $table = $tax ? TaxCheckoutSchema::TABLES['order'] : CheckoutSchema::TABLES['order'];
        $definitions = $tax ? TaxCheckoutSchema::definitions() : CheckoutSchema::definitions();
        $trigger = $definitions[$table]['prefix'].'_update';
        $statements = $tax ? TaxCheckoutSchema::statements($driver) : CheckoutSchema::statements($driver);
        $changed = false;
        $http->after = function (int $position) use ($primary, $table, $trigger, $statements, &$changed): void {
            if ($position === 0) {
                // Controlled corruption of this isolated fixture only. Restore the exact
                // declared guard, so the terminal receipt must reject changed retained rows.
                $primary->exec('DROP TRIGGER '.$trigger);
                try {
                    $changed = $primary->exec('UPDATE '.$table." SET payload_hash='".hash('sha256', 'NONBINDING RETRIEVAL ROW DRIFT')."'") === 1;
                } finally {
                    $primary->exec($statements[$trigger]['statement']);
                }
            }
        };
        $this->refuses(fn () => $plan->readSynthetic($http));
        $this->assertTrue($changed, 'Fixture row corruption did not occur.');
        $this->assertCount(6, $http->calls, 'The second original receipt must recheck rows after the complete SDK path.');
        $this->assertSame($delegate, DB::connection()->getEventDispatcher());
        $this->assertSame($this->previousTransport, ApiRequestor::httpClient());
        $this->assertSame(0, DB::transactionLevel());
    }

    #[DataProvider('kinds')]
    public function test_existing_peer_is_refused_before_source_extraction_without_invalidating_the_peer(bool $tax): void
    {
        $f = $this->paid($tax);
        $delegate = DB::connection()->getEventDispatcher();
        $receipt = $this->held($f, $tax, function ($source, $reader) use ($tax) {
            $deadline = hrtime(true) + 60_000_000_000;
            $peer = $source->committedReadReceipt($reader, $deadline);
            $observer = DB::connection()->getEventDispatcher();
            $this->refuses(fn () => $tax ? FinancialReadPlan::fromTaxV2($source, $reader, $deadline)
                : FinancialReadPlan::fromV1($source, $reader, $deadline), 'existing_cohort');
            $this->assertSame($observer, DB::connection()->getEventDispatcher());
            $this->assertFalse($peer->used());

            return $peer;
        });
        $receipt->proveClosed();
        $this->assertTrue($receipt->used());
        $this->assertSame($delegate, DB::connection()->getEventDispatcher());
    }

    public static function noticeHeaders(): array
    {
        return ['upper' => ['Stripe-Notice'], 'lower' => ['stripe-notice'], 'mixed' => ['sTrIpE-nOtIcE']];
    }

    #[DataProvider('noticeHeaders')]
    public function test_sdk_notice_header_never_invokes_the_real_error_handler_or_displaced_transport(string $header): void
    {
        [$plan, $http, $delegate] = $this->makePlan();
        $displaced = new RetrievalHttpFixture([]);
        $notices = 0;
        $http->responseHeaders[0] = [$header => 'SYNTHETIC NOTICE'];
        set_error_handler(function () use (&$notices, $displaced): bool {
            $notices++;
            ApiRequestor::setHttpClient($displaced);

            return true;
        });
        try {
            $this->refuses(fn () => $plan->readSynthetic($http));
        } finally {
            restore_error_handler();
        }
        $this->assertSame(0, $notices);
        $this->assertCount(1, $http->calls);
        $this->assertCount(0, $displaced->calls);
        $this->assertSame($delegate, DB::connection()->getEventDispatcher());
        $this->assertSame($this->previousTransport, ApiRequestor::httpClient());
    }

    public function test_sdk_slot_and_tls_changes_stop_the_following_get_and_preserve_changed_values(): void
    {
        [$plan, $http, $delegate] = $this->makePlan();
        $displaced = new RetrievalHttpFixture([]);
        $http->after = function () use ($displaced): void {
            ApiRequestor::setHttpClient($displaced);
            Stripe::$verifySslCerts = false;
        };
        $this->refuses(fn () => $plan->readSynthetic($http));
        $this->assertCount(1, $http->calls);
        $this->assertCount(0, $displaced->calls);
        $this->assertFalse(Stripe::$verifySslCerts);
        $this->assertSame($this->previousTransport, ApiRequestor::httpClient());
        $this->assertSame($delegate, DB::connection()->getEventDispatcher());
    }

    #[DataProvider('kinds')]
    public function test_held_abandonment_keeps_physical_cleanup_with_the_original_caller(bool $tax): void
    {
        $f = $this->paid($tax);
        $delegate = DB::connection()->getEventDispatcher();
        try {
            $this->held($f, $tax, function ($source, $reader) use ($tax) {
                $plan = $tax ? FinancialReadPlan::fromTaxV2($source, $reader, hrtime(true) + 60_000_000_000)
                    : FinancialReadPlan::fromV1($source, $reader, hrtime(true) + 60_000_000_000);
                $plan->close();
                $this->assertSame(1, DB::transactionLevel());
                $this->assertTrue(DB::getPdo()->inTransaction());
                throw new RuntimeException('Original fixture caller abandons its read.');
            });
            $this->fail('Caller rollback was not exercised.');
        } catch (RuntimeException $error) {
            $this->assertSame('Original fixture caller abandons its read.', $error->getMessage());
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::getPdo()->inTransaction());
        $this->assertSame($delegate, DB::connection()->getEventDispatcher());
    }

    #[DataProvider('kinds')]
    public function test_unused_plan_is_actually_disposed_after_commit_without_leaving_an_observer(bool $tax): void
    {
        [$plan, $http, $delegate] = $this->makePlan($tax);
        $this->assertNotSame($delegate, DB::connection()->getEventDispatcher());
        unset($plan);
        gc_collect_cycles();
        $this->assertSame($delegate, DB::connection()->getEventDispatcher());
        $this->assertCount(0, $http->calls);
        $this->assertSame(0, DB::transactionLevel());
    }

    #[DataProvider('kinds')]
    public function test_sdk_exception_drains_both_owned_observations_without_retry(bool $tax): void
    {
        [$plan, $http, $delegate] = $this->makePlan($tax);
        $http->responses = [];
        $this->refuses(fn () => $plan->readSynthetic($http));
        $this->assertCount(1, $http->calls);
        $this->assertSame($delegate, DB::connection()->getEventDispatcher());
        $this->assertSame($this->previousTransport, ApiRequestor::httpClient());
        $this->refuses(fn () => $plan->readSynthetic($http), 'used');
        $this->assertCount(1, $http->calls);
    }

    #[DataProvider('kinds')]
    public function test_callback_reopened_frame_is_refused_and_left_for_its_creator_to_roll_back(bool $tax): void
    {
        [$plan, $http, $delegate] = $this->makePlan($tax);
        $http->after = function (int $index): void {
            if ($index === 0) {
                DB::beginTransaction();
            }
        };
        try {
            $this->refuses(fn () => $plan->readSynthetic($http));
            $this->assertCount(1, $http->calls);
            $this->assertSame(1, DB::transactionLevel());
            $this->assertTrue(DB::getPdo()->inTransaction());
        } finally {
            if (DB::transactionLevel() === 1) {
                DB::rollBack();
            }
        }
        $this->assertSame($delegate, DB::connection()->getEventDispatcher());
        $this->assertSame($this->previousTransport, ApiRequestor::httpClient());
    }

    public function test_incomplete_lists_remain_refusals_without_an_observation_or_rights_effect(): void
    {
        [$plan, $http, $delegate] = $this->makePlan();
        $http->responses[3]['has_more'] = true;
        $this->refuses(fn () => $plan->readSynthetic($http), 'incomplete');
        $this->assertCount(6, $http->calls);
        $this->assertSame($delegate, DB::connection()->getEventDispatcher());
        $this->assertDatabaseCount('license_grants', 0);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_default_off_and_nonliteral_policy_refuse_before_source_or_transport_work(): void
    {
        $shipped = require base_path('config/production-financial-retrieval.php');
        $this->assertFalse($shipped['enabled']);
        foreach ([false, 'true', 1, null, new \ArrayObject] as $value) {
            config(['production-financial-retrieval.enabled' => $value]);
            try {
                RetrievalPolicy::capture();
                $this->fail('Unsupported policy was admitted.');
            } catch (FinancialRetrievalException $error) {
                $this->assertSame('disabled', $error->reason);
            }
        }
    }

    public static function policyChanges(): array
    {
        return ['flag' => ['production-financial-retrieval.enabled', false],
            'credential' => ['payments.stripe.secret_key', 'sk_test_CHANGEDSYNTHETIC'],
            'account' => ['payments.stripe.account_id', 'acct_CHANGEDSYNTHETIC'],
            'mode' => ['payments.stripe.mode', 'live']];
    }

    #[DataProvider('policyChanges')]
    public function test_callback_policy_changes_stop_the_next_get_without_reverting_callback_work(string $key, mixed $value): void
    {
        [$plan, $http, $delegate] = $this->makePlan();
        $http->after = static function () use ($key, $value): void {
            config([$key => $value]);
        };
        $this->refuses(fn () => $plan->readSynthetic($http));
        $this->assertCount(1, $http->calls);
        $this->assertSame($value, config($key));
        $this->assertSame($delegate, DB::connection()->getEventDispatcher());
        $this->assertSame($this->previousTransport, ApiRequestor::httpClient());
    }

    public function test_sdk_slot_displacement_alone_cannot_dispatch_a_following_get(): void
    {
        [$plan, $http, $delegate] = $this->makePlan();
        $displaced = new RetrievalHttpFixture([]);
        $http->after = static function () use ($displaced): void {
            ApiRequestor::setHttpClient($displaced);
        };
        $this->refuses(fn () => $plan->readSynthetic($http));
        $this->assertCount(1, $http->calls);
        $this->assertCount(0, $displaced->calls);
        $this->assertTrue(Stripe::$verifySslCerts);
        $this->assertSame($this->previousTransport, ApiRequestor::httpClient());
        $this->assertSame($delegate, DB::connection()->getEventDispatcher());
    }

    public function test_loaded_manager_replacement_cannot_hide_the_original_connection(): void
    {
        [$plan, $http, $delegate] = $this->makePlan();
        $original = $this->app->make('db');
        $replacement = new DatabaseManager($this->app, $this->app->make('db.factory'));
        $http->after = function () use ($replacement): void {
            $this->app->instance('db', $replacement);
        };
        try {
            $this->refuses(fn () => $plan->readSynthetic($http));
            $this->assertCount(1, $http->calls);
            $this->assertSame($replacement, $this->app->make('db'), 'Wrapper reverted callback-owned manager work.');
            $this->assertSame($delegate, $original->connection()->getEventDispatcher());
        } finally {
            $this->app->instance('db', $original);
        }
        $this->assertSame($this->previousTransport, ApiRequestor::httpClient());
    }

    public static function reachedMetadata(): array
    {
        return ['facade cache' => ['facade', 'resolvedInstance'], 'connection config' => ['connection', 'config'],
            'database name' => ['connection', 'database'], 'table prefix' => ['connection', 'tablePrefix']];
    }

    #[DataProvider('reachedMetadata')]
    public function test_reached_metadata_objects_are_refused_without_offset_callbacks(string $target, string $field): void
    {
        [$plan, $http, $delegate] = $this->makePlan();
        $connection = DB::connection();
        $object = new class extends \ArrayObject
        {
            public int $calls = 0;

            public function offsetGet(mixed $key): mixed
            {
                $this->calls++;
                throw new RuntimeException('Metadata callback must not run.');
            }

            public function __toString(): string
            {
                $this->calls++;
                throw new RuntimeException('Metadata callback must not run.');
            }
        };
        $property = new \ReflectionProperty($target === 'facade' ? Facade::class
            : Connection::class, $field);
        $owner = $target === 'facade' ? null : $connection;
        $original = $property->getValue($owner);
        $http->after = static function () use ($property, $owner, $object): void {
            $property->setValue($owner, $object);
        };
        try {
            $this->refuses(fn () => $plan->readSynthetic($http));
            $this->assertCount(1, $http->calls);
            $this->assertSame(0, $object->calls);
            $this->assertSame($object, $property->getValue($owner));
            $this->assertSame($delegate, $connection->getEventDispatcher());
        } finally {
            $property->setValue($owner, $original);
        }
    }

    public function test_custom_statement_constructor_is_not_reached_after_callback_withdrawal(): void
    {
        [$plan, $http, $delegate] = $this->makePlan();
        $primary = DB::getPdo();
        RetrievalStatementFixture::$calls = 0;
        $http->after = static function () use ($primary): void {
            $primary->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [RetrievalStatementFixture::class, []]);
        };
        try {
            $this->refuses(fn () => $plan->readSynthetic($http));
            $this->assertCount(1, $http->calls);
            $this->assertSame(0, RetrievalStatementFixture::$calls);
            $this->assertSame([RetrievalStatementFixture::class, []], $primary->getAttribute(\PDO::ATTR_STATEMENT_CLASS));
            $this->assertSame($delegate, DB::connection()->getEventDispatcher());
        } finally {
            $primary->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [\PDOStatement::class]);
        }
    }

    public function test_native_primary_replacement_is_refused_before_invoking_a_subclass_method(): void
    {
        [$plan, $http, $delegate] = $this->makePlan();
        $connection = DB::connection();
        $original = $connection->getPdo();
        $replacement = new class extends \PDO
        {
            public int $calls = 0;

            public function __construct() {}

            public function inTransaction(): bool
            {
                $this->calls++;

                return false;
            }
        };
        $http->after = static function () use ($connection, $replacement): void {
            $connection->setPdo($replacement);
        };
        try {
            $this->refuses(fn () => $plan->readSynthetic($http));
            $this->assertCount(1, $http->calls);
            $this->assertSame(0, $replacement->calls);
            $this->assertSame($replacement, $connection->getPdo(), 'Wrapper reverted callback-owned PDO work.');
            $this->assertSame($delegate, $connection->getEventDispatcher());
        } finally {
            $connection->setPdo($original);
        }
    }

    public static function malformedResponses(): array
    {
        return ['object envelope' => [new \stdClass], 'wrong status' => [['{}', '200', []]],
            'nonstring body' => [[new \stdClass, 200, []]], 'object headers' => [['{}', 200, new \stdClass]],
            'object header value' => [['{}', 200, ['example' => new \stdClass]]],
            'body budget' => [[str_repeat(' ', 1048577), 200, []]],
            'depth budget' => [[str_repeat('[', 13).'0'.str_repeat(']', 13), 200, []]],
            'node budget' => [[json_encode(array_fill(0, 4097, 0)), 200, []]],
            'bad JSON' => [['{', 200, []]], 'lower notice' => [['{}', 200, ['stripe-notice' => 'SYNTHETIC']]]];
    }

    #[DataProvider('malformedResponses')]
    public function test_raw_response_admission_refuses_before_sdk_decode_and_restores_ownership(mixed $response): void
    {
        [$plan, $http, $delegate] = $this->makePlan();
        $http->rawResponses[0] = $response;
        $this->refuses(fn () => $plan->readSynthetic($http));
        $this->assertCount(1, $http->calls);
        $this->assertSame($delegate, DB::connection()->getEventDispatcher());
        $this->assertSame($this->previousTransport, ApiRequestor::httpClient());
        $this->assertDatabaseCount('license_grants', 0);
    }

    public function test_cumulative_wire_budget_is_applied_across_individually_small_responses(): void
    {
        [$plan, $http, $delegate] = $this->makePlan();
        foreach ([0, 1] as $index) {
            $http->responses[$index]['synthetic_padding'] = str_repeat('x', 600000);
        }
        $this->refuses(fn () => $plan->readSynthetic($http));
        $this->assertCount(2, $http->calls);
        $this->assertSame($delegate, DB::connection()->getEventDispatcher());
        $this->assertSame($this->previousTransport, ApiRequestor::httpClient());
    }

    public function test_environment_and_raw_parent_refusal_evaluate_no_metadata_object(): void
    {
        foreach (['production', 'staging', 'Staging'] as $environment) {
            $this->app->instance('env', $environment);
            $this->refuses(fn () => RetrievalPolicy::capture(), 'disabled');
        }
        $this->app->instance('env', 'testing');
        $object = new class extends \ArrayObject
        {
            public int $calls = 0;

            public function offsetGet(mixed $key): mixed
            {
                $this->calls++;
                throw new RuntimeException('Metadata callback must not run.');
            }
        };
        config(['payments.stripe' => $object]);
        $this->refuses(fn () => RetrievalPolicy::capture(), 'disabled');
        $this->assertSame(0, $object->calls);
    }

    #[DataProvider('kinds')]
    public function test_attempt_before_original_commit_refuses_and_keeps_caller_physical_cleanup(bool $tax): void
    {
        $f = $this->paid($tax);
        $delegate = DB::connection()->getEventDispatcher();
        $this->abandonHeld($f, $tax, function ($source, $reader) use ($tax): void {
            $plan = $tax ? FinancialReadPlan::fromTaxV2($source, $reader, hrtime(true) + 60_000_000_000)
                : FinancialReadPlan::fromV1($source, $reader, hrtime(true) + 60_000_000_000);
            $http = $this->transport($source->line(1), $tax);
            $this->refuses(fn () => $plan->readSynthetic($http));
            $this->assertCount(0, $http->calls);
            $this->assertSame(1, DB::transactionLevel());
            $this->assertTrue(DB::getPdo()->inTransaction());
        });
        $this->assertSame($delegate, DB::connection()->getEventDispatcher());
        $this->assertSame(0, DB::transactionLevel());
    }

    #[DataProvider('kinds')]
    public function test_callback_reentry_cannot_claim_the_plan_twice(bool $tax): void
    {
        [$plan, $http, $delegate] = $this->makePlan($tax);
        $http->after = function (int $index) use ($plan, $http): void {
            if ($index === 0) {
                $this->refuses(fn () => $plan->readSynthetic($http), 'used');
            }
        };
        $this->assertFalse($plan->readSynthetic($http)->summary()['providerAuthenticated']);
        $this->assertCount(6, $http->calls);
        $this->assertSame($delegate, DB::connection()->getEventDispatcher());
    }

    #[DataProvider('kinds')]
    public function test_failed_second_mint_settles_only_its_partial_cohort_and_preserves_the_held_caller_frame(bool $tax): void
    {
        $f = $this->paid($tax);
        $delegate = DB::connection()->getEventDispatcher();
        $first = null;
        $triggered = false;
        $this->abandonHeld($f, $tax, function ($source, $reader) use ($tax, &$first, &$triggered): void {
            $encrypter = Crypt::getFacadeRoot();
            Crypt::shouldReceive('decryptString')->andReturnUsing(function (#[\SensitiveParameter] string $cipher) use ($encrypter, &$first, &$triggered, $tax): string {
                $observer = DB::connection()->getEventDispatcher();
                if (! $triggered && ($observer instanceof OriginalCommitDispatcher || $observer instanceof TaxPaidOriginalCommitDispatcher)) {
                    $receipts = RetrievalPolicy::raw($observer, $observer::class, 'receipts');
                    if (count($receipts) === 1) {
                        $first = $receipts[0];
                        $triggered = true;
                        config([$tax ? 'production-tax-checkout.committed_read_receipts_enabled' : 'production_checkout.committed_read_receipts_enabled' => false]);
                    }
                }

                return $encrypter->decryptString($cipher);
            });
            try {
                $this->refuses(fn () => $tax ? FinancialReadPlan::fromTaxV2($source, $reader, hrtime(true) + 60_000_000_000)
                    : FinancialReadPlan::fromV1($source, $reader, hrtime(true) + 60_000_000_000));
            } finally {
                Crypt::swap($encrypter);
            }
            $this->assertTrue($triggered, 'Genuine decrypt callback did not interrupt the second mint.');
            $this->assertNotNull($first);
            $this->assertTrue($first->used());
            $this->assertSame(1, DB::transactionLevel());
            $this->assertTrue(DB::getPdo()->inTransaction());
        });
        $this->assertSame($delegate, DB::connection()->getEventDispatcher());
        $this->assertFalse(DB::getPdo()->inTransaction());
    }

    #[DataProvider('kinds')]
    public function test_original_rollback_never_admits_sdk_dispatch(bool $tax): void
    {
        $f = $this->paid($tax);
        $delegate = DB::connection()->getEventDispatcher();
        $plan = $http = null;
        try {
            $this->held($f, $tax, function ($source, $reader) use ($tax, &$plan, &$http): void {
                $plan = $tax ? FinancialReadPlan::fromTaxV2($source, $reader, hrtime(true) + 60_000_000_000)
                    : FinancialReadPlan::fromV1($source, $reader, hrtime(true) + 60_000_000_000);
                $http = $this->transport($source->line(1), $tax);
                throw new RuntimeException('Synthetic original caller rollback.');
            });
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic original caller rollback.', $error->getMessage());
        }
        $this->refuses(fn () => $plan->readSynthetic($http));
        $this->assertCount(0, $http->calls);
        $this->assertSame($delegate, DB::connection()->getEventDispatcher());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_oversized_dense_list_is_refused_before_sdk_conversion_and_following_get(): void
    {
        [$plan, $http, $delegate] = $this->makePlan();
        $http->responses[3]['data'] = array_fill(0, 101, ['object' => 'refund']);
        $this->refuses(fn () => $plan->readSynthetic($http));
        $this->assertCount(4, $http->calls);
        $this->assertSame($delegate, DB::connection()->getEventDispatcher());
    }

    public function test_nested_dense_list_is_refused_before_sdk_conversion_and_following_get(): void
    {
        [$plan, $http, $delegate] = $this->makePlan();
        $http->responses[0]['synthetic_extra'] = ['nested' => array_fill(0, 101, 0)];
        $this->refuses(fn () => $plan->readSynthetic($http));
        $this->assertCount(1, $http->calls);
        $this->assertSame($delegate, DB::connection()->getEventDispatcher());
    }

    #[DataProvider('kinds')]
    public function test_original_after_commit_failure_never_admits_sdk_dispatch(bool $tax): void
    {
        $f = $this->paid($tax);
        $delegate = DB::connection()->getEventDispatcher();
        $plan = $http = null;
        try {
            $this->held($f, $tax, function ($source, $reader) use ($tax, &$plan, &$http): void {
                $plan = $tax ? FinancialReadPlan::fromTaxV2($source, $reader, hrtime(true) + 60_000_000_000)
                    : FinancialReadPlan::fromV1($source, $reader, hrtime(true) + 60_000_000_000);
                $http = $this->transport($source->line(1), $tax);
                DB::connection()->afterCommit(static function (): never {
                    throw new RuntimeException('Synthetic original afterCommit failure.');
                });
            });
            $this->fail('Original afterCommit failure did not execute.');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic original afterCommit failure.', $error->getMessage());
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::getPdo()->inTransaction());
        $this->refuses(fn () => $plan->readSynthetic($http));
        $this->assertCount(0, $http->calls);
        $this->assertSame($delegate, DB::connection()->getEventDispatcher());
    }

    #[DataProvider('kinds')]
    public function test_direct_pdo_commit_cannot_substitute_for_the_original_framework_commit(bool $tax): void
    {
        $f = $this->paid($tax);
        $delegate = DB::connection()->getEventDispatcher();
        $plan = $http = null;
        try {
            $this->held($f, $tax, function ($source, $reader) use ($tax, &$plan, &$http): void {
                $plan = $tax ? FinancialReadPlan::fromTaxV2($source, $reader, hrtime(true) + 60_000_000_000)
                    : FinancialReadPlan::fromV1($source, $reader, hrtime(true) + 60_000_000_000);
                $http = $this->transport($source->line(1), $tax);
                DB::getPdo()->commit();
            });
            $this->fail('A direct PDO commit was treated as the original held frame.');
        } catch (CheckoutException $error) {
            $this->assertSame('committed_read_frame', $error->reason);
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::getPdo()->inTransaction());
        $this->refuses(fn () => $plan->readSynthetic($http));
        $this->assertCount(0, $http->calls);
        $this->assertSame($delegate, DB::connection()->getEventDispatcher());
    }

    #[DataProvider('kinds')]
    public function test_native_older_source_deadline_cannot_be_renewed_by_a_late_caller_or_sdk_callback(bool $tax): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Actual elapsed original five-minute source cap is a focused private MySQL control.');
        }
        $f = $this->paid($tax);
        $delegate = DB::connection()->getEventDispatcher();
        [$plan, $http, $sourceCap, $factoryEntered] = $this->held($f, $tax, function ($source, $reader) use ($tax): array {
            // Read the actual immutable birth metadata; never mutate a clock, source or context.
            if ($tax) {
                $context = RetrievalPolicy::raw($source, $source::class, 'receiptContext');
                $born = RetrievalPolicy::raw($context, $context::class, 'bornAtNs');
            } else {
                $context = $source->committedReadContext($reader);
                $born = RetrievalPolicy::raw($context, $context::class, 'capturedAtNs');
            }
            $sourceCap = $born + 300_000_000_000;
            while (hrtime(true) < $sourceCap - 20_000_000_000) {
                usleep(100000);
            }
            $entered = hrtime(true);
            $caller = $entered + 600_000_000_000;
            $plan = $tax ? FinancialReadPlan::fromTaxV2($source, $reader, $caller)
                : FinancialReadPlan::fromV1($source, $reader, $caller);
            $ownership = RetrievalPolicy::raw($plan, FinancialReadPlan::class, 'ownership');
            $this->assertSame($sourceCap, RetrievalPolicy::raw($ownership, $ownership::class, 'effectiveDeadline'));
            $this->assertLessThan($entered + 30_000_000_000, $sourceCap);

            return [$plan, $this->transport($source->line(1), $tax), $sourceCap, $entered];
        });
        $this->assertLessThan($sourceCap, hrtime(true));
        $http->after = static function (int $index) use ($sourceCap): void {
            if ($index === 0) {
                while (hrtime(true) <= $sourceCap) {
                    usleep(10000);
                }
            }
        };
        $this->refuses(fn () => $plan->readSynthetic($http));
        $this->assertGreaterThanOrEqual($sourceCap, hrtime(true));
        $this->assertLessThan($factoryEntered + 600_000_000_000, hrtime(true));
        $this->assertCount(1, $http->calls);
        $this->assertSame($delegate, DB::connection()->getEventDispatcher());
        $this->assertSame($this->previousTransport, ApiRequestor::httpClient());
        $this->assertFalse(DB::getPdo()->inTransaction());
    }
}

final class RetrievalHttpFixture implements ClientInterface
{
    public array $calls = [];

    public array $responseHeaders = [];

    public array $rawResponses = [];

    public ?\Closure $after = null;

    public function __construct(public array $responses) {}

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $index = count($this->calls);
        $this->calls[] = ['method' => $method, 'path' => substr($absUrl, strlen('https://api.stripe.com/v1/')),
            'params' => $params, 'hasFile' => $hasFile, 'apiMode' => $apiMode, 'retries' => $maxNetworkRetries,
            'transaction_level' => DB::transactionLevel()];
        ($this->after ?? static function (): void {})($index);
        if (array_key_exists($index, $this->rawResponses)) {
            return $this->rawResponses[$index];
        }
        if (! array_key_exists($index, $this->responses)) {
            throw new RuntimeException('Synthetic bounded transport failure.');
        }

        return [json_encode($this->responses[$index], JSON_THROW_ON_ERROR), 200, $this->responseHeaders[$index] ?? []];
    }
}

final class RetrievalStatementFixture extends \PDOStatement
{
    public static int $calls = 0;

    protected function __construct()
    {
        self::$calls++;
    }
}
