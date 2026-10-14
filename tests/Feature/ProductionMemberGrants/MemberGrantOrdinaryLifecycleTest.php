<?php

namespace Tests\Feature\ProductionMemberGrants;

use App\Domain\Grants\Member\MemberGrantException;
use App\Domain\Grants\Member\MemberGrantSchema;
use App\Domain\Memberships\Production\MembershipAwards;
use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipPeriodRecords;
use App\Domain\Memberships\Production\MembershipReservationOperations;
use App\Domain\Memberships\Production\MembershipReservationRecords;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MemberActivationFixtures;
use Tests\TestCase;

/** Real ordinary operations over a previously consumed synthetic member grant. */
class MemberGrantOrdinaryLifecycleTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use MemberActivationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->memberActivationSetup();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_actual_ordinary_award_reserve_expire_and_release_preserve_consumed_original_history(): void
    {
        $fixture = $this->memberActivationFixture();
        $activation = $this->activateMemberFixture($fixture);
        $retained = $this->memberOriginalEffects();
        $member = array_intersect_key($retained, array_flip(MemberGrantSchema::TABLES));
        $consume = (array) DB::table(MembershipPeriodRecords::EVENTS)->where('id', $activation->consumeEventId)->first();
        $paths = $this->memberActivationFiles($activation->redemptionId);
        $this->assertCount(1, $paths);
        $file = ['path' => $paths[0], 'hash' => hash_file('sha256', $paths[0]), 'inode' => fileinode($paths[0])];
        $this->assertSame(['available' => 2, 'reserved' => 0, 'consumed' => 1, 'expired' => 0], $this->ledgerSnapshot($fixture)['balances']);

        // Every refresh is a separate real synthetic billing observation before a fresh operation.
        // No captured frame, source deadline or already-issued proof is extended.
        $this->refreshReservationObservation($fixture);
        $award = (new MembershipAwards)->award($fixture['invoice']['id'], $fixture['buyer']['principal'], $fixture['buyer']['user']);
        $this->assertSame($fixture['award'], $award);
        $this->assertSame($retained, $this->memberOriginalEffects());
        $this->assertConsumedOriginalUnchanged($member, $consume, $file);

        $this->refreshReservationObservation($fixture);
        $second = $this->reserveFixture($fixture, 'SYNTHETIC distinct reservation after consumed original');
        $this->assertNotSame($activation->redemptionId, $second['redemption_id']);
        $this->assertSame(['available' => 1, 'reserved' => 1, 'consumed' => 1, 'expired' => 0], $this->ledgerSnapshot($fixture)['balances']);
        $this->assertConsumedOriginalUnchanged($member, $consume, $file);

        CarbonImmutable::setTestNow(CarbonImmutable::parse($fixture['award']['credit_expires_at'], 'UTC'));
        $this->assertLessThanOrEqual(CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'), $second['honor_deadline']);
        $this->refreshReservationObservation($fixture);
        $operations = new MembershipReservationOperations;
        $expired = $operations->expire($fixture['invoice']['id'], $fixture['buyer']['principal'], $fixture['buyer']['user']);
        $this->assertSame('available_credit_expired', $expired['status']);
        $this->assertSame(['available' => 0, 'reserved' => 1, 'consumed' => 1, 'expired' => 1], $this->ledgerSnapshot($fixture)['balances']);
        $this->assertConsumedOriginalUnchanged($member, $consume, $file);

        $this->refreshReservationObservation($fixture);
        $released = $operations->release($fixture['invoice']['id'], $second['redemption_id'], $fixture['buyer']['principal'], $fixture['buyer']['user']);
        $this->assertSame('released', $released['status']);
        $audit = $this->ledgerSnapshot($fixture);
        $this->assertSame(['available' => 0, 'reserved' => 0, 'consumed' => 1, 'expired' => 2], $audit['balances']);
        $this->assertSame(3, array_sum($audit['balances']));
        $this->assertSame($consume['id'], $audit['reservations'][$activation->redemptionId]['terminal']['id']);
        $this->assertSame($released['release_event_id'], $audit['reservations'][$second['redemption_id']]['terminal']['id']);
        $this->assertSame(['award', 'reserve', 'consume', 'reserve', 'expire', 'release'],
            DB::table(MembershipPeriodRecords::EVENTS)->orderBy('sequence')->pluck('kind')->all());
        $this->assertSame(2, DB::table(MembershipReservationRecords::TABLE)->count());
        $this->assertConsumedOriginalUnchanged($member, $consume, $file);

        $afterCleanup = $this->memberOriginalEffects();
        $this->refreshReservationObservation($fixture);
        // Replay retains the original expiry identity and reports the now-current balances.
        $this->assertSame([...$expired, 'balances' => $audit['balances']],
            $operations->expire($fixture['invoice']['id'], $fixture['buyer']['principal'], $fixture['buyer']['user']));
        $this->assertSame($released, $operations->release($fixture['invoice']['id'], $second['redemption_id'], $fixture['buyer']['principal'], $fixture['buyer']['user']));
        $this->assertSame($award, (new MembershipAwards)->award($fixture['invoice']['id'], $fixture['buyer']['principal'], $fixture['buyer']['user']));
        $this->assertEquals($activation, $this->readMemberActivation($fixture));
        $this->assertSame($afterCleanup, $this->memberOriginalEffects());
        $this->assertSame($audit, $this->ledgerSnapshot($fixture));
        $this->assertConsumedOriginalUnchanged($member, $consume, $file);
    }

    public function test_genuine_pre_honor_confirmation_cannot_activate_at_exact_honor_without_a_prior_terminal(): void
    {
        $fixture = $this->memberActivationFixture();
        $authorization = $this->confirmMemberActivation($fixture);
        $before = $this->memberOriginalEffects();
        $this->assertSame(0, DB::table(MembershipPeriodRecords::EVENTS)->whereIn('kind', ['consume', 'release'])->count());

        // Only synthetic business time moves. The genuine ticket and its original monotonic
        // workflow/source caps are unchanged; equality itself must refuse new consumption.
        CarbonImmutable::setTestNow(CarbonImmutable::parse($fixture['reservation']['honor_deadline'], 'UTC'));
        $this->refreshReservationObservation($fixture);
        try {
            $this->activateMemberFixture($fixture, $authorization);
            $this->fail('A genuine pre-honor confirmation must not consume at exact honor.');
        } catch (MemberGrantException $error) {
            $this->assertSame('source_refused', $error->reason);
            $this->assertInstanceOf(MembershipException::class, $error->getPrevious());
            $this->assertSame('reservation_terminal', $error->getPrevious()->reason);
        }

        $this->assertMemberOriginalEffectsUnchanged($before);
        $this->assertSame([], $this->memberActivationFiles($fixture['reservation']['redemption_id']));
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    private function assertConsumedOriginalUnchanged(array $member, array $consume, array $file): void
    {
        $this->assertSame($member, array_intersect_key($this->memberOriginalEffects(), array_flip(MemberGrantSchema::TABLES)));
        $this->assertSame($consume, (array) DB::table(MembershipPeriodRecords::EVENTS)->where('id', $consume['id'])->first());
        clearstatcache(true, $file['path']);
        $this->assertSame($file['inode'], fileinode($file['path']));
        $this->assertSame($file['hash'], hash_file('sha256', $file['path']));
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }
}
