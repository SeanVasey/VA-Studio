<?php

namespace Tests\Feature\CustomerComms;

use App\Domain\Customers\DataRights\ProductionAccountClosure;
use App\Domain\Customers\Models\CustomerAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\DeliveryFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\InventoryFixtures;
use Tests\Support\LicenseFixtures;
use Tests\Support\RefundDisputeFixtures as R;
use Tests\TestCase;

/**
 * BL-44: the refund and dispute guard of a production account closure, read against REAL observed refund and dispute records of an
 * order. The order belongs to the shared fixture owner key; the account the guard reads is built around that key, so only the
 * refund-and-dispute branch (which needs no production origin) is exercised. The unpaid-session branch is in ProductionDataRightsTest.
 */
final class ProductionAccountClosureHoldsTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private object $payments;

    private object $financial;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        R::configure();
        Queue::fake();
        [$this->payments, $this->financial] = R::bind($this->app);
    }

    private function holds(): array
    {
        $account = (new CustomerAccount)->forceFill(['id' => 987654, 'owner_key' => InventoryFixtures::OWNER]);

        return (new ProductionAccountClosure)->holds($account);
    }

    public function test_an_unsettled_refund_holds_the_closure_and_a_settled_one_does_not(): void
    {
        $f = DeliveryFixtures::ready($this->payments);
        $staff = LicenseFixtures::admin();
        $this->assertSame([], $this->holds(), 'No refund or dispute has been observed.');

        R::provider($this->financial, [['re_ONE', 100, 'pending']]);
        R::observe($f['order']->public_id, $staff);
        $this->assertSame(['refund_or_dispute_open'], $this->holds());
        R::provider($this->financial, [['re_ONE', 100, 'requires_action']]);
        R::observe($f['order']->public_id, $staff);
        $this->assertSame(['refund_or_dispute_open'], $this->holds());
        R::provider($this->financial, [['re_ONE', 100, 'succeeded']]);
        R::observe($f['order']->public_id, $staff);
        $this->assertSame(0, DB::table('refund_dispute_holds')->count());
        $this->assertSame([], $this->holds(), 'The latest observed status of the only refund is final.');
    }

    public function test_an_open_dispute_and_an_unreleased_download_hold_keep_the_closure_open_until_resolved(): void
    {
        $f = DeliveryFixtures::ready($this->payments);
        $staff = LicenseFixtures::admin();
        $paid = $f['payment']->amount_minor;

        foreach (['warning_needs_response', 'needs_response', 'under_review'] as $open) {
            R::provider($this->financial, [], [['du_ONE', $paid, $open]]);
            R::observe($f['order']->public_id, $staff);
            $this->assertSame(['refund_or_dispute_open'], $this->holds(), $open);
        }
        // The lifecycle holds the order while a dispute reconciles, and that hold stays until staff release it: a lost dispute's
        // latest status is final, yet the unreleased hold alone keeps the closure open.
        R::provider($this->financial, [], [['du_ONE', $paid, 'lost']]);
        R::observe($f['order']->public_id, $staff);
        $this->assertSame('lost', DB::table('refund_dispute_statuses')->orderByDesc('id')->value('status'));
        $this->assertSame(1, DB::table('refund_dispute_holds')->whereNull('released_at')->count());
        $this->assertSame(['refund_or_dispute_open'], $this->holds());
    }
}
