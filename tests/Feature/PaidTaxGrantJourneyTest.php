<?php

namespace Tests\Feature;

use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxCheckout;
use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionIdentity\IdentityMigrationOwnership;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use App\Domain\Delivery\DeliveryException;
use App\Domain\Grants\PaidTax\PaidTaxDocuments;
use App\Domain\Grants\PaidTax\PaidTaxFiles;
use App\Domain\Grants\PaidTax\PaidTaxGrants;
use App\Domain\Grants\PaidTax\PaidTaxProjectionRead;
use App\Domain\Grants\PaidTax\PaidTaxRecords;
use App\Domain\Grants\PaidTax\PaidTaxRendererProcess;
use App\Domain\Grants\PaidTax\PaidTaxRenderInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionTaxCheckoutFixtures;
use Tests\Support\QuoteFixtures;
use Tests\Support\RecordingTaxCheckoutTransport;
use Tests\TestCase;

/** Actual local SMTP enrollment and retained synthetic Tax payment; no host/provider acceptance claim. */
class PaidTaxGrantJourneyTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use ProductionTaxCheckoutFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'production-customer-identity.enabled' => true, 'production-customer-identity.provenance' => IdentityPolicy::REHEARSAL,
            'production-customer-identity.public_origin' => 'http://localhost', 'production-customer-identity.notifications_enabled' => true,
            'production-customer-identity.transport_capability' => LoopbackSmtp::CAPABILITY,
            'production-customer-identity.historical_receipts_enabled' => true,
            'production-customer-identity.historical_receipts_version' => 'identity-historical-committed-receipt-v1',
            'production-tax-checkout.committed_read_receipts_enabled' => true,
            'paid-tax-grants.rehearsal_enabled' => true,
            'paid-tax-grants.delivery_policy' => ['schema_version' => 2, 'version' => 'explicit-synthetic-tax-delivery-v2',
                'purpose' => 'paid-tax-original-delivery', 'provenance' => 'synthetic_rehearsal', 'max_downloads' => 3, 'authorization_seconds' => 60]]);
        Queue::fake();
    }

    protected function paid(string $behavior = 'exclusive', int $tax = 437, bool $twoLines = false): array
    {
        if ($twoLines) {
            $catalog = $this->taxCatalog($behavior);
            $second = QuoteFixtures::selection(3499);
            $items = [...$catalog['items'], ...$second['items']];
            $buyer = $this->enrollThroughLocalSmtp();
            $access = new ProductionCustomerAccess;
            $transport = new RecordingTaxCheckoutTransport;
            $checkout = new ProductionTaxCheckout($access, $transport);
            $preview = $checkout->preview($buyer['principal'], $buyer['user'], $catalog['candidate']->id, $items);
            $order = $checkout->order($buyer['principal'], $buyer['user'], $catalog['candidate']->id, $items, $preview['previewHash'], true,
                ['legalName' => 'Declared synthetic buyer'], 'synthetic-tax-two-lines');
            $f = compact('catalog', 'second', 'buyer', 'access', 'transport', 'checkout', 'preview', 'order');
        } else {
            $f = $this->taxOrder($behavior);
        }
        self::configureTax(self::SYNTHETIC_PROVIDER);
        $f['checkout']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $f['transport']->lineTax = $tax;
        $f['transport']->paid = true;
        $paid = $f['checkout']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $this->assertSame('verified', $paid['paymentStatus']);

        return $f;
    }

    public static function amounts(): array
    {
        return ['exclusive' => ['exclusive', 437, 5436], 'inclusive' => ['inclusive', 437, 4999], 'zero tax' => ['exclusive', 0, 4999]];
    }

    #[DataProvider('amounts')]
    public function test_authenticated_tax_origin_retains_original_nested_amounts_and_replays_once(string $behavior, int $tax, int $total): void
    {
        $f = $this->paid($behavior, $tax);
        $projectionRead = PaidTaxProjectionRead::begin();
        $first = (new PaidTaxGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId'], $projectionRead);
        $projectionRead->proveBeforeBytes();
        $this->assertSame([2, 'paid-tax-license-grant', false, $f['order']['orderId']],
            [$first['schemaVersion'], $first['purpose'], $first['fulfilled'], $first['orderId']]);
        $this->assertSame([4999, $tax, $total, $behavior], [$first['lines'][0]['tax']['line_subtotal_minor'],
            $first['lines'][0]['tax']['line_tax_minor'], $first['lines'][0]['tax']['line_total_minor'], $first['lines'][0]['tax']['tax_behavior']]);
        $this->assertSame([], $first['lines'][0]['files']);
        $this->assertSame($first, (new PaidTaxGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']));
        foreach (['paid_tax_order_origins', 'paid_tax_grant_origins', 'paid_tax_document_work'] as $table) {
            $this->assertDatabaseCount($table, 1);
        }
        foreach (['paid_order_origins', 'paid_grant_origins', 'paid_originals', 'paid_tax_originals', 'paid_tax_fulfillments'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $source = PaidTaxRecords::decode((array) DB::table('paid_tax_grant_origins')->sole())['source'];
        $this->assertSame($first['orderId'], $source['order_id']);
        $this->assertSame($first['lines'][0]['tax'], $source['tax']);
        foreach (['payment_id', 'payment_hash', 'amounts', 'line_amount_minor', 'line_tax_minor'] as $v1Field) {
            $this->assertArrayNotHasKey($v1Field, $source);
        }
        // Actual original inspector compatibility, including the new ordinary user/account FK consumers.
        $ownership = (new IdentityMigrationOwnership)->inspect(DB::getPdo(), DB::getDriverName());
        $this->assertNotEmpty($ownership);
    }

    public function test_actual_tax_original_is_private_deterministic_idempotent_and_restore_only(): void
    {
        $f = $this->paid();
        $retained = (new PaidTaxGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $documents = new PaidTaxDocuments;
        $projectionRead = PaidTaxProjectionRead::begin();
        $complete = $documents->prepare($retained['id'], $f['buyer']['principal'], $f['buyer']['user'], $projectionRead);
        $projectionRead->proveBeforeBytes();
        $this->assertTrue($complete['fulfilled']);
        $this->assertSame(['complete', 1], [$complete['lines'][0]['documentStatus'], $complete['lines'][0]['attempts']]);
        $this->assertCount(2, $complete['lines'][0]['files']);
        $manifestRow = (array) DB::table('paid_tax_originals')->sole();
        $manifest = PaidTaxRecords::decode($manifestRow);
        $path = Storage::disk('local')->path($manifest['artifact']['storage_path']);
        $this->assertStringStartsWith('contracts/paid-tax-v2/synthetic_rehearsal/', $manifest['artifact']['storage_path']);
        $this->assertSame(0400, fileperms($path) & 0777);
        $bytes = (new PaidTaxFiles)->verify($manifest['artifact']);
        $this->assertSame(hash('sha256', $bytes), $complete['lines'][0]['files'][0]['sha256']);
        $body = PaidTaxRecords::decode((array) DB::table('paid_tax_grant_origins')->sole());
        $rendered = app(PaidTaxRendererProcess::class)->render(PaidTaxRenderInput::fromOrigin($body), $body['profile']);
        $this->assertSame($bytes, $rendered->pdfBytes, 'The genuine isolated renderer changed the same retained original.');
        $extract = new Process(['pdftotext', '-enc', 'UTF-8', $path, '-'], timeout: 30);
        $extract->mustRun();
        $text = $extract->getOutput();
        foreach (['source.tax.line_subtotal_minor' => '4999', 'source.tax.line_tax_minor' => '437',
            'source.tax.line_total_minor' => '5436', 'source.tax.tax_behavior' => 'exclusive',
            'source.tax.calculator' => 'stripe_checkout_automatic_tax'] as $label => $value) {
            $this->assertMatchesRegularExpression('/'.preg_quote($label, '/').'\s+'.preg_quote($value, '/').'/', $text);
        }
        $this->assertSame($complete, $documents->prepare($retained['id'], $f['buyer']['principal'], $f['buyer']['user']));
        $this->assertSame($manifestRow, (array) DB::table('paid_tax_originals')->sole());
        rename($path, $path.'.retained-away');
        try {
            // Historical fulfillment is a past observation; this batch adds no delivery endpoint.
            $this->assertSame($complete, $documents->prepare($retained['id'], $f['buyer']['principal'], $f['buyer']['user']));
            $this->assertSame($manifestRow, (array) DB::table('paid_tax_originals')->sole());
            $this->assertSame(1, (int) DB::table('paid_tax_document_work')->sole()->attempts);
        } finally {
            rename($path.'.retained-away', $path);
        }
        $this->assertSame($bytes, (new PaidTaxFiles)->verify($manifest['artifact']));
        $this->assertDatabaseCount('paid_tax_fulfillments', 1);
        $this->assertDatabaseCount('paid_originals', 0);
        $this->assertDatabaseCount('license_grants', 0);
    }

    public function test_failed_second_tax_line_retains_first_original_until_exact_physical_retry(): void
    {
        $f = $this->paid(twoLines: true);
        $retained = (new PaidTaxGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $this->assertCount(2, $retained['lines']);
        $secondPath = Storage::disk('local')->path($f['second']['media']['master_wav']->storage_path);
        $renders = 0;
        $moved = false;
        app()->afterResolving(PaidTaxRendererProcess::class, static function () use ($secondPath, &$renders, &$moved): void {
            if (++$renders === 2) {
                rename($secondPath, $secondPath.'.retained-away');
                $moved = true;
            }
        });
        $failure = null;
        try {
            try {
                (new PaidTaxDocuments)->prepare($retained['id'], $f['buyer']['principal'], $f['buyer']['user']);
            } catch (DeliveryException $error) {
                $failure = $error;
            }
        } finally {
            if ($moved) {
                rename($secondPath.'.retained-away', $secondPath);
            }
        }
        $this->assertTrue($moved);
        $this->assertInstanceOf(DeliveryException::class, $failure);
        $this->assertSame('asset_unavailable', $failure->reason);
        $this->assertSame(['complete', 'failed'], DB::table('paid_tax_document_work')->orderBy('id')->pluck('state')->all());
        $this->assertDatabaseCount('paid_tax_originals', 1);
        $this->assertDatabaseCount('paid_tax_fulfillments', 0);
        $first = (array) DB::table('paid_tax_originals')->sole();
        $artifact = PaidTaxRecords::decode($first)['artifact'];
        $firstBytes = (new PaidTaxFiles)->verify($artifact);
        $complete = (new PaidTaxDocuments)->prepare($retained['id'], $f['buyer']['principal'], $f['buyer']['user']);
        $this->assertTrue($complete['fulfilled']);
        $this->assertSame([1, 2], array_column($complete['lines'], 'attempts'));
        $this->assertSame($first, (array) DB::table('paid_tax_originals')->where('id', $first['id'])->sole());
        $this->assertSame($firstBytes, (new PaidTaxFiles)->verify($artifact));
        $this->assertSame(3, $renders, 'Retry regenerated an already completed original.');
        $this->assertDatabaseCount('paid_tax_originals', 2);
        $this->assertDatabaseCount('paid_tax_fulfillments', 1);
        $this->assertDatabaseCount('license_grants', 0);
    }
}
