<?php

namespace Tests\Feature\Reporting;

use App\Domain\Delivery\PrepareTestDeliveryStream;
use App\Domain\Reporting\CustomerSegments;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\DeliveryFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\InventoryFixtures;
use Tests\Support\LicenseFixtures;
use Tests\Support\RefundDisputeFixtures as R;
use Tests\Support\RefundResolutionFixtures;
use Tests\TestCase;

/**
 * Segment edges: a paid exception is not a purchase, refunds reach the refunded segment and the net column (and a reversed refund
 * stops counting), and accounts created by the production identity path never appear under the TEST label.
 */
class CustomerSegmentsEdgeTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private object $payments;

    private object $financial;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        R::configure();
        Queue::fake();
        [$this->payments, $this->financial] = R::bind($this->app);
        $this->app->instance(PrepareTestDeliveryStream::class, DeliveryFixtures::observingStreams());
        config(['reporting.customer_segments.enabled' => true, 'reporting.customer_segments.export_enabled' => true]);
        $this->admin = LicenseFixtures::admin();
        $this->admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->actingAs($this->admin);
    }

    /** A customer account that owns the fixtures' guest order key, so its synthetic order belongs to a segmentable account. */
    private function ownerOfFixtureOrders(): string
    {
        $user = User::factory()->create(['is_admin' => false, 'email_verified_at' => now()]);
        $public = (string) Str::uuid();
        $at = now()->utc()->format('Y-m-d H:i:s');
        DB::table('customer_accounts')->insert(['public_id' => $public, 'user_id' => $user->id, 'owner_key' => InventoryFixtures::OWNER, 'active' => 1,
            'access_version' => 1, 'created_at' => $at, 'updated_at' => $at]);

        return $public;
    }

    private function counts(): array
    {
        return array_map(fn (array $segment): ?int => $segment['count'], app(CustomerSegments::class)->overview($this->admin)['segments']);
    }

    private function export(string $segment): array
    {
        $export = app(CustomerSegments::class)->export($this->admin, $segment);

        return $export + ['table' => array_map('str_getcsv', explode("\n", trim($export['csv'])))];
    }

    public function test_a_paid_exception_is_not_a_purchase(): void
    {
        $exception = RefundResolutionFixtures::exception($this->payments);
        $account = $this->ownerOfFixtureOrders();
        $this->assertSame('paid_exception', $exception['record']->outcome);
        $this->assertSame(['purchasers' => 0, 'repeat_purchasers' => 0, 'refunded_purchasers' => 0, 'no_purchase' => 1, 'marketing_email_eligible' => null], $this->counts());
        $this->assertSame([$account], array_column(array_slice($this->export('no_purchase')['table'], 1), 0));
        $this->assertSame(0, $this->export('purchasers')['customers']);
    }

    public function test_refunds_reach_the_refunded_segment_and_net_and_a_reversed_refund_stops_counting_and_production_accounts_are_excluded(): void
    {
        $f = DeliveryFixtures::ready($this->payments);
        $paid = $f['payment']->amount_minor;
        $account = $this->ownerOfFixtureOrders();

        // An account created the way the production identity path creates one, with its origin row.
        $production = User::factory()->create(['is_admin' => false, 'email_verified_at' => now()]);
        $owner = bin2hex(random_bytes(32));
        $productionPublic = (string) Str::uuid();
        $at = now()->utc()->format('Y-m-d H:i:s');
        DB::table('quote_owners')->insert(['owner_key' => $owner]);
        $productionId = DB::table('customer_accounts')->insertGetId(['public_id' => $productionPublic, 'user_id' => $production->id, 'owner_key' => $owner,
            'active' => 1, 'access_version' => 1, 'created_at' => $at, 'updated_at' => $at]);
        // The origin insert guard needs a full mailbox-proof enrolment graph; its own suites cover it. This disposable fixture only
        // needs the row that CompleteIdentity leaves, so drop that one guard here.
        DB::unprepared('DROP TRIGGER pi_origins_insert');
        Schema::disableForeignKeyConstraints();
        DB::table('production_identity_origins')->insert(['public_id' => (string) Str::uuid(), 'provenance' => 'SYNTHETIC', 'identity_policy_version' => 'SYNTHETIC',
            'identity_policy_hash' => str_repeat('a', 64), 'account_id' => $productionId, 'user_id' => $production->id, 'address_id' => 1, 'initial_challenge_id' => 1,
            'owner_digest' => str_repeat('b', 64), 'recipient_hmac' => str_repeat('c', 64), 'created_at' => $at, 'origin_hash' => str_repeat('d', 64)]);
        Schema::enableForeignKeyConstraints();

        $overview = app(CustomerSegments::class)->overview($this->admin);
        $this->assertSame(1, $overview['accounts'], 'The production identity account is not part of the TEST population.');
        $this->assertSame(array_sum($overview['consent']), 1);
        foreach (['no_purchase', 'purchasers', 'refunded_purchasers'] as $segment) {
            $this->assertStringNotContainsString($productionPublic, $this->export($segment)['csv']);
        }
        $this->assertSame(['purchasers' => 1, 'repeat_purchasers' => 0, 'refunded_purchasers' => 0, 'no_purchase' => 0, 'marketing_email_eligible' => null], $this->counts());

        // A succeeded full refund: the account is a refunded purchaser and its net is zero.
        R::provider($this->financial, [['re_SEGMENT', $paid, 'succeeded']]);
        R::observe($f['order']->public_id, $this->admin);
        $this->assertSame(1, $this->counts()['refunded_purchasers']);
        $row = $this->export('refunded_purchasers')['table'][1];
        $this->assertSame([$account, '1', 'USD', (string) $paid, (string) $paid, '0'], [$row[0], $row[5], $row[8], $row[9], $row[10], $row[11]]);

        // The provider later reports the refund as failed: it no longer counts anywhere.
        R::provider($this->financial, [['re_SEGMENT', $paid, 'failed']]);
        R::observe($f['order']->public_id, $this->admin);
        $this->assertSame(0, $this->counts()['refunded_purchasers']);
        $row = $this->export('purchasers')['table'][1];
        $this->assertSame([$account, (string) $paid, '0', (string) $paid], [$row[0], $row[9], $row[10], $row[11]]);
    }
}
