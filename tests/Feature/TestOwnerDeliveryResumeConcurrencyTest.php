<?php

namespace Tests\Feature;

use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Contracts\ContractRenderer;
use App\Domain\Contracts\Models\GrantContract;
use App\Domain\Delivery\DeliveryByteRange;
use App\Domain\Delivery\IssueTestDelivery;
use App\Domain\Delivery\Models\TestDeliveryCoverage;
use App\Domain\Delivery\Models\TestDeliveryRedemption;
use App\Domain\Delivery\TransferTestDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ContractFixtures;
use Tests\Support\DeliveryFixtures as F;
use Tests\Support\DeliveryRace;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\InventoryFixtures;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

/** FP-077: independent MySQL processes serve ranges of one started v2 transfer and record coverage at the same time. */
class TestOwnerDeliveryResumeConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Coverage races require independent MySQL processes.');
        }
    }

    public function test_two_simultaneous_ranges_that_each_complete_the_file_count_it_once(): void
    {
        [$input, $bytes] = $this->started();
        // Both serve the whole remaining tail: either alone would complete coverage.
        $results = DeliveryRace::run($this, $input + ['ranges' => ['bytes=10-', 'bytes=10-']]);
        $this->assertSame(['transferred', 'transferred'], array_column($results, 'outcome'));
        $this->assertSame([hash('sha256', substr($bytes, 10)), hash('sha256', substr($bytes, 10))], array_column($results, 'content_hash'));
        $this->assertCoverage(strlen($bytes), [10, strlen($bytes), strlen($bytes)]);
    }

    public function test_two_simultaneous_complementary_ranges_lose_no_coverage_and_count_once(): void
    {
        [$input, $bytes] = $this->started();
        $half = intdiv(strlen($bytes), 2);
        $results = DeliveryRace::run($this, $input + ['ranges' => ['bytes=10-'.($half - 1), 'bytes='.$half.'-']]);
        $this->assertSame(['transferred', 'transferred'], array_column($results, 'outcome'));
        $this->assertSame([hash('sha256', substr($bytes, 10, $half - 10)), hash('sha256', substr($bytes, $half))], array_column($results, 'content_hash'));
        // Whichever recorded second saw the first's committed coverage and completed the file.
        $this->assertCoverage(strlen($bytes), [10, null, strlen($bytes)]);
    }

    private function assertCoverage(int $size, array $covered): void
    {
        $rows = TestDeliveryCoverage::orderBy('sequence')->get();
        $this->assertSame([1, 2, 3], $rows->pluck('sequence')->all());
        $this->assertSame($covered[0], $rows[0]->covered_bytes);
        if ($covered[1] !== null) {
            $this->assertSame($covered[1], $rows[1]->covered_bytes);
        }
        $this->assertSame($covered[2], $rows[2]->covered_bytes);
        $this->assertSame('[[0,'.($size - 1).']]', $rows[2]->intervals);
        $this->assertSame(1, TestDeliveryCoverage::whereNotNull('completed_authorization_id')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'commerce.delivery.completed')->count());
        $this->assertSame(2, DB::table('audit_events')->where('action', 'commerce.delivery.resumed')->count());
        $this->assertSame(1, TestDeliveryRedemption::count());
    }

    /** One v2 authorization whose transfer started by serving bytes 0..9 in this process. */
    private function started(): array
    {
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        F::configureV2();
        Queue::fake();
        $gateway = PaymentFixtures::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $gateway);
        $this->app->instance(StripePaymentGateway::class, $gateway);
        $this->app->instance(ContractRenderer::class, ContractFixtures::renderer());
        $f = F::ready($gateway);
        $issued = app(IssueTestDelivery::class)->handle($f['order']->public_id, InventoryFixtures::OWNER, $f['grants'][0]->public_id, 'contract',
            (string) Str::uuid(), sessionSecret: F::SESSION_SECRET);
        $original = GrantContract::where('license_grant_id', $f['grants'][0]->id)->sole();
        $bytes = Storage::disk('local')->get($original->storage_path);
        $this->assertGreaterThan(40, strlen($bytes));
        app(TransferTestDelivery::class)->handle($f['order']->public_id, InventoryFixtures::OWNER, $issued->authorizationId, F::SESSION_SECRET,
            DeliveryByteRange::evaluate('bytes=0-9', null, strlen($bytes), $original->pdf_hash))->writeTo(fn () => null);
        $this->assertSame(10, TestDeliveryCoverage::sole()->covered_bytes);

        return [['mode' => 'transfer', 'order_id' => $f['order']->id, 'order_public_id' => $f['order']->public_id,
            'owner_key' => InventoryFixtures::OWNER, 'authorization_public_id' => $issued->authorizationId, 'session_secret' => F::SESSION_SECRET,
            'size_bytes' => strlen($bytes), 'sha256' => $original->pdf_hash, 'now' => now()->toIso8601ZuluString()], $bytes];
    }
}
