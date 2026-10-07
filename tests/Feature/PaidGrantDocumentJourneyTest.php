<?php

namespace Tests\Feature;

use App\Domain\Commerce\ProductionCheckout\ApproveExemptionAuthority;
use App\Domain\Commerce\ProductionCheckout\HostedCheckout;
use App\Domain\Commerce\ProductionCheckout\ProductionCheckout;
use App\Domain\Commerce\ProductionCheckout\TaxExemptions;
use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\IdentityNoticeTransport;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use App\Domain\Customers\ProductionIdentity\Notifications\WorkIdentityNotice;
use App\Domain\Delivery\DeliveryException;
use App\Domain\Grants\Paid\PaidGrantCommands;
use App\Domain\Grants\Paid\PaidGrantDocuments;
use App\Domain\Grants\Paid\PaidGrantException;
use App\Domain\Grants\Paid\PaidGrantFiles;
use App\Domain\Grants\Paid\PaidGrantProjectionRead;
use App\Domain\Grants\Paid\PaidGrantRecords;
use App\Domain\Grants\Paid\PaidGrantRendererProcess;
use App\Domain\Grants\Paid\PaidGrantRows;
use App\Domain\Grants\Paid\PaidGrants;
use App\Domain\Grants\Paid\PaidGrantSchema;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\PaidGrantDependencyFixtures;
use Tests\Support\ProductionCheckoutFixtures;
use Tests\Support\ProductionCheckoutGatewayFixture;
use Tests\Support\ProductionCheckoutJourneyFixture;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

/** Actual SMTP-enrolled buyer + frozen synthetic paid source. No live payment/legal facts are certified. */
final class PaidGrantDocumentJourneyTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use PaidGrantDependencyFixtures;
    use ProductionCheckoutJourneyFixture;

    protected function beforeRefreshingDatabase(): void
    {
        $this->preparePaidDependencies();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('p', 32)),
            'production-customer-identity.enabled' => true, 'production-customer-identity.provenance' => IdentityPolicy::REHEARSAL,
            'production-customer-identity.public_origin' => 'http://localhost', 'production-customer-identity.notifications_enabled' => true,
            'production-customer-identity.transport_capability' => LoopbackSmtp::CAPABILITY,
            'production_checkout.committed_read_receipts_enabled' => true,
            'production_checkout.committed_read_receipt_version' => 'production-checkout-committed-read-v1',
            'production-customer-identity.historical_receipts_enabled' => true,
            'production-customer-identity.historical_receipts_version' => 'identity-historical-committed-receipt-v1',
            'production_checkout.fresh_checkout_enabled' => true, 'production_checkout.reconciliation_enabled' => true,
            'paid-grants.rehearsal_enabled' => true,
            'paid-grants.delivery_policy' => ['schema_version' => 1, 'version' => 'explicit-synthetic-delivery-v1', 'purpose' => 'paid-original-delivery',
                'provenance' => 'synthetic_rehearsal', 'max_downloads' => 3, 'authorization_seconds' => 60]]);
        Queue::fake();
    }

    protected function smtp(string $mode, int $noticeId): array
    {
        $capture = tempnam(sys_get_temp_dir(), 'va-paid-synthetic-smtp-');
        $process = new Process(['python3', $this->paidDependencyPath('tests/Support/production_identity_smtp_sink.py'), $mode, $capture], timeout: 20);
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

    private function retained(bool $twoLines = false): array
    {
        if (! $twoLines) {
            $f = $this->payable();
        } else {
            $catalog = ProductionCheckoutFixtures::catalog();
            $second = QuoteFixtures::selection(3499);
            $items = [...$catalog['items'], ...$second['items']];
            $buyer = $this->enrollThroughLocalSmtp();
            $access = new ProductionCustomerAccess;
            $authority = app(ApproveExemptionAuthority::class)->approve($catalog['candidate']->id, ProductionCheckoutFixtures::exemptionPolicy($catalog), 'synthetic-two-lines-policy', $catalog['actor']);
            $basis = (new TaxExemptions($access))->qualify($buyer['principal'], $buyer['user'], $authority['public_id'], $items,
                ['qualified_exemption_confirmed' => true, 'reference' => 'synthetic:two-lines-buyer', 'source_sha256' => hash('sha256', 'NONBINDING TWO-LINE QUALIFICATION'),
                    'effective_from' => CarbonImmutable::now('UTC')->subDay()->format('Y-m-d\TH:i:s\Z'),
                    'effective_until' => CarbonImmutable::now('UTC')->addDays(30)->format('Y-m-d\TH:i:s\Z')], 'synthetic-two-lines-qualification', $catalog['actor']);
            $checkout = new ProductionCheckout($access);
            $review = $checkout->review($buyer['principal'], $buyer['user'], $catalog['candidate']->id, $items, $basis['public_id'], ['legalName' => 'Declared synthetic buyer'], 'synthetic-two-lines-review');
            $order = $checkout->accept($buyer['principal'], $buyer['user'], $review['reviewId'], $review['reviewHash'], true, 'synthetic-two-lines-accept');
            $gateway = new ProductionCheckoutGatewayFixture;
            $hosted = new HostedCheckout($access, $gateway);
            $f = compact('catalog', 'buyer', 'order', 'gateway', 'hosted', 'second');
        }
        $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $f['gateway']->paid = true;
        $this->assertSame('verified', $f['hosted']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId'])['paymentStatus']);
        $f['batch'] = (new PaidGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);

        return $f;
    }

    public function test_actual_claim_render_original_and_complete_order_are_idempotent_and_missing_original_is_restore_only(): void
    {
        $f = $this->retained();
        $documents = new PaidGrantDocuments;
        $complete = $documents->prepare($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user']);
        $this->assertTrue($complete['fulfilled']);
        $this->assertSame('complete', $complete['lines'][0]['documentStatus']);
        $this->assertSame(1, $complete['lines'][0]['attempts']);
        $this->assertCount(2, $complete['lines'][0]['files']);
        $before = $this->ownedRows();
        $this->assertSame($complete, $documents->prepare($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user']));
        $this->assertSame($before, $this->ownedRows());
        $manifest = PaidGrantRecords::decode((array) DB::table('paid_originals')->sole());
        $path = Storage::disk('local')->path($manifest['artifact']['storage_path']);
        $bytes = file_get_contents($path);
        rename($path, $path.'.retained-away');
        try {
            $documents->prepare($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user']);
            $this->fail('A missing original must never cause a replacement claim or be reported available.');
        } catch (ContractIssuanceException $error) {
            $this->assertSame('original_unavailable', $error->reason);
            $this->assertSame($before, $this->ownedRows());
        } finally {
            rename($path.'.retained-away', $path);
        }
        $this->assertSame($bytes, file_get_contents($path));
        $this->assertSame($complete, $documents->prepare($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user']));
        $this->assertSame($before, $this->ownedRows());
        $this->assertDatabaseCount('license_grants', 0);
    }

    public function test_failed_second_line_retains_first_original_without_any_activation_until_exact_fresh_retry(): void
    {
        $f = $this->retained(true);
        $second = Storage::disk('local')->path($f['second']['media']['master_wav']->storage_path);
        $renders = 0;
        $moved = false;
        app()->afterResolving(PaidGrantRendererProcess::class, function () use ($second, &$renders, &$moved): void {
            if (++$renders === 2) {
                rename($second, $second.'.retained-away');
                $moved = true;
            }
        });
        try {
            (new PaidGrantDocuments)->prepare($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user']);
            $this->fail('Incomplete physical deliverables cannot activate any line of a complete paid order.');
        } catch (DeliveryException $error) {
            $this->assertTrue($moved, $error->getMessage());
            $this->assertSame('asset_unavailable', $error->reason);
            $this->assertSame(['complete', 'failed'], DB::table('paid_document_work')->orderBy('id')->pluck('state')->all());
            $this->assertDatabaseCount('paid_originals', 1);
            $this->assertDatabaseCount('paid_fulfillments', 0);
        } finally {
            if ($moved) {
                rename($second.'.retained-away', $second);
            }
        }
        $first = (array) DB::table('paid_originals')->sole();
        $firstBytes = (new PaidGrantFiles)->verify(PaidGrantRecords::decode($first)['artifact']);
        $complete = (new PaidGrantDocuments)->prepare($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user']);
        $this->assertTrue($complete['fulfilled']);
        $this->assertCount(2, $complete['lines']);
        $this->assertSame([1, 2], array_column($complete['lines'], 'attempts'));
        $this->assertSame($first, (array) DB::table('paid_originals')->where('id', $first['id'])->sole());
        $this->assertSame($firstBytes, (new PaidGrantFiles)->verify(PaidGrantRecords::decode($first)['artifact']));
        $this->assertDatabaseCount('paid_originals', 2);
        $this->assertDatabaseCount('paid_fulfillments', 1);
    }

    public function test_busy_original_claim_returns_only_a_fresh_one_use_body_receipt_without_new_claim_or_activation(): void
    {
        $f = $this->retained();
        $expires = CarbonImmutable::now('UTC')->addSeconds(PaidGrantDocuments::LEASE_SECONDS)->format('Y-m-d H:i:s');
        (new PaidGrantCommands)->run($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user'],
            function (array $graph, PaidGrantRows $rows) use ($expires): array {
                $rows->execute('UPDATE '.$rows->table('paid_document_work')." SET state = 'claimed', attempts = 1, claim_id = ?, expires_at = ? WHERE id = ?",
                    [(string) Str::uuid(), $expires, $graph['lines'][0]['work']['id']]);

                return [];
            });
        $before = $this->ownedRows();
        $read = PaidGrantProjectionRead::begin();
        $waiting = (new PaidGrantDocuments)->prepare($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user'], $read);
        $this->assertFalse($waiting['fulfilled']);
        $this->assertSame('claimed', $waiting['lines'][0]['documentStatus']);
        $this->assertSame(1, $waiting['lines'][0]['attempts']);
        $this->assertSame([], $waiting['lines'][0]['files']);
        $this->assertSame($before, $this->ownedRows());
        $read->proveBeforeBytes();
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getRawPdo()->inTransaction());
        try {
            $read->proveBeforeBytes();
            $this->fail('The captured original body receipt cannot be reused.');
        } catch (PaidGrantException) {
            $this->assertSame($before, $this->ownedRows());
        }
    }

    public function test_credential_withdrawal_during_real_render_cannot_publish_original_or_extend_claim_authority(): void
    {
        $f = $this->retained();
        $fired = false;
        app()->afterResolving(PaidGrantRendererProcess::class, function () use ($f, &$fired): void {
            $fired = true;
            DB::table('users')->where('id', $f['buyer']['user']->id)->update(['password' => Hash::make('Withdrawn while paid original renders')]);
        });
        try {
            (new PaidGrantDocuments)->prepare($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user']);
            $this->fail('Original rendering does not renew the withdrawn buyer session.');
        } catch (PaidGrantException $error) {
            $this->assertSame(403, $error->status);
            $this->assertTrue($fired);
        }
        $work = (array) DB::table('paid_document_work')->sole();
        $this->assertSame('claimed', $work['state']);
        $this->assertSame(1, (int) $work['attempts']);
        $this->assertDatabaseCount('paid_originals', 0);
        $this->assertDatabaseCount('paid_fulfillments', 0);
        $this->assertDatabaseCount('paid_grant_origins', 1);
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
