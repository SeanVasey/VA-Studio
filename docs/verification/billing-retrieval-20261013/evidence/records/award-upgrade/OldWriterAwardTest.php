<?php

namespace Runtime\AwardUpgrade;

require_once __DIR__.'/AwardUpgradeRuntime.php';

use App\Domain\Memberships\Billing\BillingValues;
use App\Domain\Memberships\Production\MembershipAwards;
use App\Domain\Memberships\Production\MembershipPeriodRecords;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\BillingStripeFixtures as F;
use Tests\Support\MembershipAwardFixtures;
use Tests\TestCase;

/** Real old-source writer; intentionally no automatic migration teardown. */
final class OldWriterAwardTest extends TestCase
{
    use AwardUpgradeRuntime;
    use MembershipAwardFixtures;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_old_writer_retains_an_actual_public_award_for_forward_upgrade(): void
    {
        $this->requirePrivateDatabase('/workspace/VA-Studio-billing-c3-baseline');
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->assertSame(0, DB::table('migrations')->where('migration', 'like', '%263000%')->count());
        $this->awardSetup();
        $fixture = $this->awardFixture();
        $this->awardTestCapabilities();
        $receipt = (new MembershipAwards)->award($fixture['invoice']['id'], $fixture['buyer']['principal'], $fixture['buyer']['user']);
        $this->assertSame(3, $receipt['allowance']);
        $this->assertSame('synthetic_rehearsal', $receipt['provenance']);
        $this->assertCount(1, $this->rows(MembershipPeriodRecords::PERIODS));
        $this->assertCount(1, $this->rows(MembershipPeriodRecords::EVENTS));
        $this->assertSame('award', $this->rows(MembershipPeriodRecords::EVENTS)[0]['kind']);
        $this->assertSame(2, BillingValues::decrypt($fixture['observation']['payload_ciphertext'])['schema_version']);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());

        $this->writeReceipt('old-receipt', $receipt);
        $this->writeReceipt('old-periods', $this->rows(MembershipPeriodRecords::PERIODS));
        $this->writeReceipt('old-events', $this->rows(MembershipPeriodRecords::EVENTS));
        $this->writeReceipt('old-billing', $this->billingRows());
        $this->writeReceipt('old-identity', $this->identityRows());
        $this->writeReceipt('old-plans', $this->rows('production_membership_plan_versions'));
        $this->writeReceipt('old-context', ['source' => '45d1299406821627f4ba02af186e324a70c6ade0',
            'invoice_id' => $fixture['invoice']['id'], 'subscription_binding_id' => $fixture['approval']['row']['id'],
            'plan_id' => $fixture['plan']['id'], 'plan_policy_hash' => $fixture['plan']['policy_hash'],
            'buyer_user_id' => $fixture['buyer']['user']->getKey(), 'buyer_account_id' => $fixture['buyer']['principal']->accountId,
            'observation_id' => $fixture['observation']['id'], 'configuration_hashes' => $this->configurationHashes(),
            'synthetic_provider_graph_sha256' => CanonicalJson::hash(F::graph()),
            'old_payload_facts_sha256' => CanonicalJson::hash(BillingValues::decrypt($fixture['observation']['payload_ciphertext'])['facts'])]);
        $this->assertOriginalAwardRows();
        $this->assertOriginalIdentityRows();
    }
}
