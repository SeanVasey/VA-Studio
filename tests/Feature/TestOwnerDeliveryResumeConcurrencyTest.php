<?php

namespace Tests\Feature;

use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Contracts\ContractRenderer;
use App\Domain\Contracts\Models\GrantContract;
use App\Domain\Delivery\DeliveryByteRange;
use App\Domain\Delivery\IssueTestDelivery;
use App\Domain\Delivery\Models\TestDeliveryAdmission;
use App\Domain\Delivery\Models\TestDeliveryAuthorization;
use App\Domain\Delivery\Models\TestDeliveryCoverage;
use App\Domain\Delivery\Models\TestDeliveryRedemption;
use App\Domain\Delivery\TransferTestDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ContractFixtures;
use Tests\Support\CustomerFixtures;
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

    /**
     * #258 P2-2: a signed-in customer's admission fixes its REPEATABLE READ snapshot with plain reads (customer access) before
     * it waits for the authorization lock that an independent record() holds. Once that coverage commits, the admission must
     * read admissions and coverage with locking reads only, so it is admitted rather than refused as changed.
     */
    public function test_a_signed_in_admission_waiting_behind_a_coverage_write_reads_the_committed_state(): void
    {
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        Queue::fake();
        $account = CustomerFixtures::account();
        $paid = CustomerFixtures::ready($account['user']);
        F::configureV2();
        $principal = $paid['principal'];
        $issued = app(IssueTestDelivery::class)->handle($paid['order']->public_id, $principal->ownerKey, $paid['grant']->public_id, 'contract',
            (string) Str::uuid(), $account['user'], $principal, F::SESSION_SECRET);
        $original = GrantContract::where('license_grant_id', $paid['grant']->id)->sole();
        $bytes = Storage::disk('local')->get($original->storage_path);
        // A first response was admitted and its worker died: an admission without coverage, waiting to be recorded.
        app(TransferTestDelivery::class)->handle($paid['order']->public_id, $principal->ownerKey, $issued->authorizationId, F::SESSION_SECRET,
            DeliveryByteRange::evaluate('bytes=0-9', null, strlen($bytes), $original->pdf_hash), $account['user'], $principal)->close();
        $this->assertSame(1, TestDeliveryAdmission::count());
        $this->assertSame(0, TestDeliveryCoverage::count());

        $results = DeliveryRace::run($this, ['mode' => 'admit_record', 'order_id' => $paid['order']->id, 'order_public_id' => $paid['order']->public_id,
            'authorization_public_id' => $issued->authorizationId, 'authorization_id' => TestDeliveryAuthorization::sole()->id,
            'admission_id' => TestDeliveryAdmission::sole()->id, 'user_id' => $account['user']->id, 'session_secret' => F::SESSION_SECRET,
            'size_bytes' => strlen($bytes), 'sha256' => $original->pdf_hash, 'now' => now()->toIso8601ZuluString()]);
        $this->assertSame(['recorded', 'transferred'], array_column($results, 'outcome'), json_encode($results));
        $this->assertSame(hash('sha256', substr($bytes, 10)), $results[1]['content_hash']);
        $rows = TestDeliveryCoverage::orderBy('sequence')->get();
        $this->assertSame([[0, 9, 10, null], [10, strlen($bytes) - 1, strlen($bytes), TestDeliveryAuthorization::sole()->id]],
            $rows->map(fn ($row) => [$row->first_byte, $row->last_byte, $row->covered_bytes, $row->completed_authorization_id])->all());
        $this->assertSame(2, TestDeliveryAdmission::count());
        $this->assertSame([$account['user']->id], DB::table('audit_events')->where('action', 'commerce.delivery.resumed')->pluck('actor_id')->all());
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
