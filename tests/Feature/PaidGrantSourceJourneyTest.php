<?php

namespace Tests\Feature;

use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\IdentityNoticeTransport;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use App\Domain\Customers\ProductionIdentity\Notifications\WorkIdentityNotice;
use App\Domain\Grants\Paid\PaidGrantException;
use App\Domain\Grants\Paid\PaidGrantFiles;
use App\Domain\Grants\Paid\PaidGrantPolicy;
use App\Domain\Grants\Paid\PaidGrantRecords;
use App\Domain\Grants\Paid\PaidGrantRendererProcess;
use App\Domain\Grants\Paid\PaidGrantRenderInput;
use App\Domain\Grants\Paid\PaidGrants;
use App\Domain\Grants\Paid\PaidGrantSchema;
use App\Providers\ProductionCheckoutServiceProvider;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionCheckoutJourneyFixture;
use Tests\TestCase;

/** Actual SMTP-enrolled buyer + frozen synthetic paid source. No live payment/legal facts are certified. */
final class PaidGrantSourceJourneyTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use ProductionCheckoutJourneyFixture;

    private const DEPENDENCY = '/workspace/.va-studio-dependencies/paid/f0a1615';

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertTrue(app()->environment('testing'));
        if (DB::getDriverName() === 'mysql') {
            $this->assertSame('vaseyaudio_paid_grants', DB::getDatabaseName());
        }
        $this->assertFileExists(self::DEPENDENCY.'/source-map.json', 'Exact provisional producer/identity fixture snapshot is required.');
        app('migrator')->path(self::DEPENDENCY.'/database/migrations');
        app()->register(ProductionCheckoutServiceProvider::class);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('p', 32)),
            'production-customer-identity.enabled' => true, 'production-customer-identity.provenance' => IdentityPolicy::REHEARSAL,
            'production-customer-identity.public_origin' => 'http://localhost', 'production-customer-identity.notifications_enabled' => true,
            'production-customer-identity.transport_capability' => LoopbackSmtp::CAPABILITY,
            'production_checkout.fresh_checkout_enabled' => true, 'production_checkout.reconciliation_enabled' => true,
            'paid-grants.rehearsal_enabled' => true,
            'paid-grants.delivery_policy' => ['schema_version' => 1, 'version' => 'explicit-synthetic-delivery-v1', 'purpose' => 'paid-original-delivery',
                'provenance' => 'synthetic_rehearsal', 'max_downloads' => 3, 'authorization_seconds' => 60]]);
        Queue::fake();
    }

    protected function smtp(string $mode, int $noticeId): array
    {
        $capture = tempnam(sys_get_temp_dir(), 'va-paid-synthetic-smtp-');
        $process = new Process(['python3', self::DEPENDENCY.'/tests/Support/production_identity_smtp_sink.py', $mode, $capture], timeout: 20);
        $process->start();
        try {
            $process->waitUntil(fn (): bool => preg_match('/\A[0-9]+\n/', $process->getOutput()) === 1);
            app()->instance(IdentityNoticeTransport::class, new LoopbackSmtp((int) trim($process->getOutput())));
            (new WorkIdentityNotice)->process($noticeId);
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());

            return filesize($capture) > 0 ? json_decode(file_get_contents($capture), true, 8, JSON_THROW_ON_ERROR) : [];
        } finally {
            $process->stop(0);
            unlink($capture);
        }
    }

    public function test_actual_original_assent_and_paid_source_mint_one_distinct_immutable_origin_on_exact_replay(): void
    {
        $f = $this->payable();
        $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $f['gateway']->paid = true;
        $paid = $f['hosted']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $this->assertSame('verified', $paid['paymentStatus']);
        $first = (new PaidGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $this->assertSame('synthetic_rehearsal', $first['provenance']);
        $this->assertFalse($first['fulfilled']);
        $this->assertSame('pending', $first['lines'][0]['documentStatus']);
        $this->assertSame([], $first['lines'][0]['files']);
        $this->assertSame($first, (new PaidGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']));
        $this->assertDatabaseCount('paid_order_origins', 1);
        $this->assertDatabaseCount('paid_grant_origins', 1);
        $this->assertDatabaseCount('paid_document_work', 1);
        $this->assertDatabaseCount('paid_fulfillments', 0);
        foreach (['orders', 'license_grants', 'checkout_intents', 'inventory_reservations'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    private function paid(): array
    {
        $f = $this->payable();
        $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $f['gateway']->paid = true;
        $this->assertSame('verified', $f['hosted']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId'])['paymentStatus']);

        return $f;
    }

    public function test_a_different_actually_verified_customer_cannot_consume_original_buyer_payment(): void
    {
        $f = $this->paid();
        $other = $this->enrollThroughLocalSmtp('other-paid-buyer@example.test');
        try {
            (new PaidGrants)->finalize($other['principal'], $other['user'], $f['order']['orderId']);
            $this->fail('Original buyer payment became another account entitlement.');
        } catch (PaidGrantException $error) {
            $this->assertSame(404, $error->status);
        }
        $this->assertDatabaseCount('paid_order_origins', 0);
        $this->assertDatabaseCount('paid_grant_origins', 0);
    }

    public static function withdrawals(): array
    {
        return [['password'], ['account'], ['policy'], ['same-pdo-lazy'], ['commit-reopen']];
    }

    #[DataProvider('withdrawals')]
    public function test_late_attributed_audit_callbacks_cannot_replace_current_authority_or_transaction(string $kind): void
    {
        $f = $this->paid();
        $armed = true;
        $fired = false;
        $lazyInvoked = false;
        $originalPdo = DB::connection()->getPdo();
        $before = $this->ownedRows();
        Event::listen('eloquent.created: App\\Support\\Audit\\AuditEvent', function () use ($kind, $f, &$armed, &$fired, &$lazyInvoked, $originalPdo): void {
            if (! $armed) {
                return;
            }
            $armed = false;
            $fired = true;
            match ($kind) {
                'password' => DB::table('users')->where('id', $f['buyer']['user']->id)->update(['password' => Hash::make('Withdrawn paid credential')]),
                'account' => DB::table('customer_accounts')->where('id', $f['buyer']['binding']['account_id'])->update(['active' => false, 'access_version' => DB::raw('access_version + 1')]),
                'policy' => config(['paid-grants.rehearsal_enabled' => false]),
                'same-pdo-lazy' => DB::connection()->setPdo(static function () use ($originalPdo, &$lazyInvoked): \PDO {
                    $lazyInvoked = true;

                    return $originalPdo;
                }),
                'commit-reopen' => $this->commitReopen($originalPdo),
            };
        });
        try {
            (new PaidGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
            $this->fail('A callback withdrew authority/frame but published a private projection.');
        } catch (PaidGrantException) {
            $this->assertTrue($fired);
        } finally {
            DB::connection()->setPdo($originalPdo);
        }
        if ($kind !== 'commit-reopen') {
            $this->assertSame($before, $this->ownedRows());
        } else {
            // A hostile direct commit cannot be undone: retained originals stay private and unfulfilled.
            $this->assertDatabaseCount('paid_fulfillments', 0);
        }
        $this->assertFalse($lazyInvoked, 'A refused replacement PDO closure cannot run during proof or cleanup.');
    }

    private function commitReopen(\PDO $pdo): void
    {
        $pdo->commit();
        $pdo->beginTransaction();
    }

    public function test_last_policy_resolution_and_final_commit_withdrawal_refuse_private_return(): void
    {
        $f = $this->paid();
        $resolutions = 0;
        $fired = false;
        app()->afterResolving(PaidGrantPolicy::class, function () use ($f, &$resolutions, &$fired): void {
            if (++$resolutions === 3) {
                $fired = true;
                DB::table('users')->where('id', $f['buyer']['user']->id)->update(['password' => Hash::make('Terminal resolver withdrawal')]);
            }
        });
        try {
            (new PaidGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
            $this->fail('Terminal resolver withdrew credential but returned source.');
        } catch (PaidGrantException) {
            $this->assertTrue($fired);
            $this->assertDatabaseCount('paid_order_origins', 0);
        }
    }

    public function test_transaction_committed_callback_disables_paid_policy_before_private_projection(): void
    {
        $f = $this->paid();
        $fired = false;
        Event::listen(TransactionCommitted::class, function () use (&$fired): void {
            $fired = true;
            config(['paid-grants.rehearsal_enabled' => false]);
        });
        try {
            (new PaidGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
            $this->fail('Commit callback withdrew policy but returned private projection.');
        } catch (PaidGrantException $error) {
            $this->assertSame(403, $error->status);
            $this->assertTrue($fired);
        }
        // A committed immutable original is retained; denial cannot rewrite history.
        $this->assertDatabaseCount('paid_order_origins', 1);
        $this->assertDatabaseCount('paid_fulfillments', 0);
    }

    public function test_actual_paid_input_renders_and_stores_one_private_purpose_bound_original_without_activating_a_line(): void
    {
        $f = $this->paid();
        $origin = (new PaidGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $record = (array) DB::table('paid_grant_origins')->first();
        $body = PaidGrantRecords::decode($record);
        $input = PaidGrantRenderInput::fromOrigin($body);
        $pdf = (new PaidGrantRendererProcess)->render($input, $body['profile']);
        $this->assertStringStartsWith('%PDF-', $pdf->pdfBytes);
        $claim = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
        $artifact = (new PaidGrantFiles)->store($origin['lines'][0]['id'], $claim, 'synthetic_rehearsal', $pdf);
        $this->assertSame($pdf->pdfBytes, (new PaidGrantFiles)->verify($artifact));
        $this->assertStringContainsString('contracts/paid/synthetic_rehearsal/', $artifact['storage_path']);
        // A physical observation alone is not original publication or fulfillment authority.
        $this->assertDatabaseCount('paid_originals', 0);
        $this->assertDatabaseCount('paid_fulfillments', 0);
        $this->expectException(ContractIssuanceException::class);
        (new PaidGrantFiles)->store($origin['lines'][0]['id'], $claim, 'synthetic_rehearsal', $pdf);
    }

    private function ownedRows(): array
    {
        $result = [];
        foreach (array_keys(PaidGrantSchema::specs()) as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        }

        return $result;
    }
}
