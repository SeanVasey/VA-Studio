<?php

namespace Tests\Feature;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxPaidLineAdapterV2;
use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxPaidOrderLocatorV2;
use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxPaidOrderSourceV2;
use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Grants\PaidTax\PaidTaxActor;
use App\Domain\Grants\PaidTax\PaidTaxCleanup;
use App\Domain\Grants\PaidTax\PaidTaxCommands;
use App\Domain\Grants\PaidTax\PaidTaxDeadline;
use App\Domain\Grants\PaidTax\PaidTaxDocuments;
use App\Domain\Grants\PaidTax\PaidTaxException;
use App\Domain\Grants\PaidTax\PaidTaxFiles;
use App\Domain\Grants\PaidTax\PaidTaxGrants;
use App\Domain\Grants\PaidTax\PaidTaxProjectionRead;
use App\Domain\Grants\PaidTax\PaidTaxRecords;
use App\Domain\Grants\PaidTax\PaidTaxRendererProcess;
use App\Domain\Grants\PaidTax\PaidTaxRows;
use App\Domain\Grants\PaidTax\PaidTaxRuntime;
use App\Domain\Grants\PaidTax\PaidTaxTransaction;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use ReflectionProperty;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\PaidTaxGrantFixtures;
use Tests\TestCase;

/** Genuine retained synthetic payments/SMTP/private child processes; no provider or host claim. */
class PaidTaxAdversarialJourneyTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use PaidTaxGrantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparePaidTaxFixture();
    }

    public function test_before_bytes_owner_refusal_consumes_the_sibling_and_allows_a_fresh_exact_retry(): void
    {
        $f = $this->paidTaxOrder();
        $read = PaidTaxProjectionRead::begin();
        $retained = (new PaidTaxGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId'], $read);
        $pdo = DB::getPdo();
        $userId = $f['buyer']['user']->id;
        $password = $pdo->query('SELECT password FROM users WHERE id='.$userId)->fetchColumn();
        $pdo->exec("UPDATE users SET password='synthetic-before-bytes-withdrawal' WHERE id=".$userId);
        try {
            $failure = null;
            try {
                $read->proveBeforeBytes();
            } catch (PaidTaxException $error) {
                $failure = $error;
            }
            $this->assertInstanceOf(PaidTaxException::class, $failure);
            $this->assertSame(403, $failure->status);
            $this->assertDatabaseCount('paid_tax_order_origins', 1);
            $this->assertFalse($pdo->inTransaction());
            $this->refuses(fn () => $read->proveBeforeBytes(), 503);
        } finally {
            $statement = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
            $statement->execute([$password, $userId]);
        }
        $retry = null;
        $retryFailure = null;
        try {
            $retry = (new PaidTaxGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        } catch (\Throwable $error) {
            $retryFailure = $error;
        }
        $this->assertNull($retryFailure, 'A refused before-bytes sibling stranded its original source observer.');
        $this->assertSame($retained, $retry);
        $this->assertDatabaseCount('paid_tax_order_origins', 1);
    }

    public function test_before_bytes_live_original_budget_cannot_be_renewed_after_commit(): void
    {
        $f = $this->paidTaxOrder();
        $grants = new PaidTaxGrants;
        $retained = $grants->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $budget = PaidTaxDeadline::start();
        $read = PaidTaxProjectionRead::begin();
        $projection = (new PaidTaxCommands)->run($retained['id'], $f['buyer']['principal'], $f['buyer']['user'],
            fn (array $graph): array => $grants->project($graph), $budget, null, $read);
        $this->assertSame($retained, $projection);
        $budget->shortenTo(hrtime(true) - 1);
        $this->refuses(fn () => $read->proveBeforeBytes(), 410);
        $this->refuses(fn () => $read->proveBeforeBytes(), 503);
        $this->assertSame($retained, $grants->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']));
        $this->assertDatabaseCount('paid_tax_order_origins', 1);
    }

    public function test_two_valid_same_buyer_orders_cannot_swap_authenticated_line_or_expected_target(): void
    {
        $a = $this->paidTaxOrder();
        $b = $this->secondPaidTaxOrder($a, 'cs_test_SECONDVALID', 'pi_SECONDVALID');
        $grants = new PaidTaxGrants;
        $first = $grants->finalize($a['buyer']['principal'], $a['buyer']['user'], $a['order']['orderId']);
        $second = $grants->finalize($b['buyer']['principal'], $b['buyer']['user'], $b['order']['orderId']);
        $lineB = PaidTaxRecords::decode((array) DB::table('paid_tax_grant_origins')->where('public_id', $second['lines'][0]['id'])->sole())['source'];
        $this->assertSame($b['order']['orderId'], $lineB['order_id']);
        $locator = ProductionTaxPaidOrderLocatorV2::locate($a['order']['orderId']);
        $runtime = PaidTaxRuntime::capture(PaidTaxDeadline::start());
        $frame = null;
        $rows = null;
        $source = null;
        try {
            PaidTaxTransaction::run($runtime, PaidTaxActor::capture($a['buyer']['user']), function ($original) use ($a, $b, $locator, $runtime, $lineB, &$rows, &$source): array {
                $rows = new PaidTaxRows($runtime, $original);
                $a['access']->lock($a['buyer']['principal'], $a['buyer']['user'], $rows->current());
                $historical = $a['access']->verifyHistoricalBinding($locator->historicalBuyerBinding(), $rows->current());
                $reader = $rows->admitSource();
                $source = ProductionTaxPaidOrderSourceV2::lockedRead($locator, $reader, $historical);
                foreach ([[$lineB, $a['order']['orderId'], 'source_binding'], [$source->line(1), $b['order']['orderId'], 'source_order']] as [$line, $target, $reason]) {
                    try {
                        ProductionTaxPaidLineAdapterV2::accept($source, 1, $line, 'synthetic_rehearsal', $reader, $target);
                        $this->fail('A different valid same-buyer order was accepted as the original target.');
                    } catch (CheckoutException $error) {
                        $this->assertSame([$reason, 409], [$error->reason, $error->status]);
                    }
                }
                $this->assertSame($a['order']['orderId'], ProductionTaxPaidLineAdapterV2::accept($source, 1, $source->line(1), 'synthetic_rehearsal', $reader, $a['order']['orderId'])['order_id']);

                return [];
            }, $frame);
        } finally {
            PaidTaxCleanup::abort($source, $rows, $frame);
        }
        $this->assertSame($first, $grants->finalize($a['buyer']['principal'], $a['buyer']['user'], $a['order']['orderId']));
        $this->assertSame($second, $grants->finalize($b['buyer']['principal'], $b['buyer']['user'], $b['order']['orderId']));
        $this->assertDatabaseCount('paid_tax_order_origins', 2);
    }

    public function test_genuine_second_child_render_failure_preserves_first_original_until_exact_retry(): void
    {
        $f = $this->paidTaxOrder(twoLines: true);
        $retained = (new PaidTaxGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $renders = 0;
        app()->instance(PaidTaxRendererProcess::class, new PaidTaxRendererProcess(static function ($argv, $root, $env, $payload) use (&$renders): Process {
            if (++$renders === 2) {
                $input = json_decode($payload, true, 128, JSON_THROW_ON_ERROR);
                $input['input']['schema_version'] = 1;
                $payload = json_encode($input, JSON_THROW_ON_ERROR);
            }

            // The actual pinned child executes the same command; only its second input is invalid.
            return new Process($argv, $root, $env, $payload, 60);
        }));
        $failure = null;
        try {
            (new PaidTaxDocuments)->prepare($retained['id'], $f['buyer']['principal'], $f['buyer']['user']);
        } catch (ContractIssuanceException $error) {
            $failure = $error;
        }
        $this->assertInstanceOf(ContractIssuanceException::class, $failure);
        $this->assertSame('unsupported_input', $failure->reason);
        $this->assertSame(2, $renders);
        $this->assertSame(['complete', 'failed'], DB::table('paid_tax_document_work')->orderBy('id')->pluck('state')->all());
        $this->assertDatabaseCount('paid_tax_originals', 1);
        $this->assertDatabaseCount('paid_tax_fulfillments', 0);
        $first = (array) DB::table('paid_tax_originals')->sole();
        $artifact = PaidTaxRecords::decode($first)['artifact'];
        $bytes = (new PaidTaxFiles)->verify($artifact);
        $complete = (new PaidTaxDocuments)->prepare($retained['id'], $f['buyer']['principal'], $f['buyer']['user']);
        $this->assertTrue($complete['fulfilled']);
        $this->assertSame([1, 2], array_column($complete['lines'], 'attempts'));
        $this->assertSame(3, $renders);
        $this->assertSame($first, (array) DB::table('paid_tax_originals')->where('id', $first['id'])->sole());
        $this->assertSame($bytes, (new PaidTaxFiles)->verify($artifact));
        $this->assertDatabaseCount('paid_tax_originals', 2);
        $this->assertDatabaseCount('paid_tax_fulfillments', 1);
        $this->assertDatabaseCount('license_grants', 0);
    }

    public function test_current_owner_withdrawal_in_later_actual_source_decryption_rolls_back_all_origins(): void
    {
        $f = $this->paidTaxOrder();
        $pdo = DB::getPdo();
        $id = $f['buyer']['user']->id;
        $before = (array) DB::table('users')->where('id', $id)->sole();
        $encrypter = Crypt::getFacadeRoot();
        $hits = 0;
        $delegates = 0;
        DB::connection()->getEventDispatcher()->listen(TransactionCommitting::class, static function () use ($encrypter, $pdo, $id, &$hits, &$delegates): void {
            if ($delegates++ !== 0) {
                return;
            }
            Crypt::shouldReceive('decryptString')->andReturnUsing(static function (string $cipher) use ($encrypter, $pdo, $id, &$hits): string {
                if ($hits++ === 0) {
                    $pdo->exec("UPDATE users SET password='synthetic-late-source-withdrawal' WHERE id=".$id);
                }

                return $encrypter->decryptString($cipher);
            });
        });
        try {
            $this->refuses(fn () => (new PaidTaxGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']), 403);
        } finally {
            Crypt::swap($encrypter);
        }
        $this->assertGreaterThan(0, $hits);
        $this->assertSame(1, $delegates);
        $this->assertSame($before, (array) DB::table('users')->where('id', $id)->sole());
        $this->assertDatabaseCount('paid_tax_order_origins', 0);
        $this->assertFalse($pdo->inTransaction());
        $this->assertSame($f['order']['orderId'], (new PaidTaxGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId'])['orderId']);
    }

    public function test_caught_early_source_receipt_proof_poisons_the_original_consumer_commit(): void
    {
        $f = $this->paidTaxOrder();
        $calls = 0;
        $reason = null;
        $connection = DB::connection();
        $connection->getEventDispatcher()->listen(TransactionCommitting::class, static function () use ($connection, &$calls, &$reason): void {
            if ($calls++ !== 0) {
                return;
            }
            $observer = $connection->getEventDispatcher();
            $receipts = (new ReflectionProperty($observer, 'receipts'))->getValue($observer);
            try {
                $receipts[0]->proveClosed();
            } catch (CheckoutException $error) {
                $reason = $error->reason;
            }
        });
        $this->refuses(fn () => (new PaidTaxGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']), 409);
        $this->assertSame('committed_read_frame', $reason);
        $this->assertSame(1, $calls);
        $this->assertDatabaseCount('paid_tax_order_origins', 0);
        $this->assertFalse(DB::getPdo()->inTransaction());
        $this->assertSame($f['order']['orderId'], (new PaidTaxGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId'])['orderId']);
    }

    public function test_non_delegating_terminal_dispatcher_replacement_refuses_without_overwriting_replacement(): void
    {
        $f = $this->paidTaxOrder();
        $connection = DB::connection();
        $replacement = new Dispatcher(app());
        $connection->getEventDispatcher()->listen(TransactionCommitting::class, static function () use ($connection, $replacement): void {
            $connection->setEventDispatcher($replacement);
        });
        $this->refuses(fn () => (new PaidTaxGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']), 409);
        $this->assertSame($replacement, $connection->getEventDispatcher());
        $this->assertDatabaseCount('paid_tax_order_origins', 0);
        $this->assertFalse(DB::getPdo()->inTransaction());
        $this->assertSame($f['order']['orderId'], (new PaidTaxGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId'])['orderId']);
    }

    private function refuses(\Closure $operation, int $status): void
    {
        try {
            $operation();
            $this->fail('The changed original authority must refuse.');
        } catch (PaidTaxException $error) {
            $this->assertSame($status, $error->status);
        }
    }

    public function test_unpaid_and_different_verified_buyer_refuse_without_origin_then_the_original_buyer_replays_once(): void
    {
        $f = $this->taxOrder();
        self::configureTax(self::SYNTHETIC_PROVIDER);
        $grants = new PaidTaxGrants;
        $this->refuses(fn () => $grants->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']), 409);
        $this->assertDatabaseCount('paid_tax_order_origins', 0);
        $this->assertFalse(DB::getPdo()->inTransaction());
        $f['checkout']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $f['transport']->paid = true;
        $this->assertSame('verified', $f['checkout']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId'])['paymentStatus']);
        $other = $this->enrollThroughLocalSmtp('other-tax-buyer@example.test');
        // Preserve the existing hidden-target contract for a different verified account.
        $this->refuses(fn () => $grants->finalize($other['principal'], $other['user'], $f['order']['orderId']), 404);
        $this->assertDatabaseCount('paid_tax_order_origins', 0);
        $this->assertFalse(DB::getPdo()->inTransaction());
        $original = $grants->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $this->assertSame($f['order']['orderId'], $original['orderId']);
        $this->assertSame($original, $grants->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']));
        $this->assertDatabaseCount('paid_tax_order_origins', 1);
    }
}
