<?php

namespace Tests\Feature\ProductionMemberGrants;

use App\Domain\Grants\Member\MemberGrantActivationResult;
use App\Domain\Grants\Member\MemberGrantException;
use App\Domain\Memberships\Production\MembershipPeriodRecords;
use App\Domain\Memberships\Production\MembershipReservationRecords;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MemberActivationFixtures;
use Tests\TestCase;

/** Real synthetic confirmation, child/files and one coupled member effect; no mounted commerce. */
class MemberGrantActivationIntegrationTest extends TestCase
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

    public function test_actual_confirmation_creates_one_conserved_graph_and_readback_preserves_exact_rows_and_bytes(): void
    {
        $fixture = $this->memberActivationFixture();
        $before = $this->memberOriginalEffects();
        $beforeAudit = $this->ledgerSnapshot($fixture);
        $authorization = $this->confirmMemberActivation($fixture);
        $this->assertMemberOriginalEffectsUnchanged($before);

        $result = $this->activateMemberFixture($fixture, $authorization);
        $this->assertInstanceOf(MemberGrantActivationResult::class, $result);
        $expectedKeys = ['originId', 'redemptionId', 'definitionId', 'consumeEventId', 'activationId',
            'artifactManifestHash', 'activationHash', 'status'];
        $actualKeys = array_keys(get_object_vars($result));
        sort($expectedKeys, SORT_STRING);
        sort($actualKeys, SORT_STRING);
        $this->assertSame($expectedKeys, $actualKeys);
        $this->assertSame('activated', $result->status);
        $this->assertSame($fixture['reservation']['redemption_id'], $result->originId);
        $this->assertSame($result->originId, $result->redemptionId);
        $this->assertSame($fixture['member_authored']->definitionId, $result->definitionId);

        $after = $this->memberOriginalEffects();
        foreach (['production_member_origins', 'production_member_artifacts', 'production_member_activations'] as $table) {
            $this->assertCount(1, $after[$table]);
        }
        foreach ([MembershipPeriodRecords::PERIODS, MembershipReservationRecords::TABLE,
            'production_member_profiles', 'production_member_definitions'] as $table) {
            $this->assertSame($before[$table], $after[$table]);
        }
        $this->assertCount(count($before[MembershipPeriodRecords::EVENTS]) + 1, $after[MembershipPeriodRecords::EVENTS]);
        $origin = $after['production_member_origins'][0];
        $artifact = $after['production_member_artifacts'][0];
        $activation = $after['production_member_activations'][0];
        $consume = DB::table(MembershipPeriodRecords::EVENTS)->where('id', $result->consumeEventId)->first();
        $this->assertNotNull($consume);
        $this->assertSame('consume', $consume->kind);
        $this->assertSame($result->originId, $origin['id']);
        $this->assertSame($result->redemptionId, $origin['redemption_id']);
        $this->assertSame($result->originId, $artifact['origin_id']);
        $this->assertSame($result->activationId, $activation['id']);
        $this->assertSame($result->originId, $activation['origin_id']);
        $this->assertSame($result->originId, $consume->grant_origin_id);
        $this->assertSame($result->redemptionId, $consume->redemption_id);
        $this->assertSame($activation['readiness_receipt_hash'], $consume->grant_receipt_hash);
        $this->assertSame($result->artifactManifestHash, $origin['artifact_manifest_hash']);
        $this->assertSame($origin['artifact_manifest_hash'], $activation['artifact_manifest_hash']);

        // This helper uses the real history reader and pure full-ledger audit; ordinary writer calls
        // over consumed history are exercised separately in MemberGrantOrdinaryLifecycleTest.
        $audit = $this->ledgerSnapshot($fixture);
        $amount = $fixture['reservation']['credit_amount'];
        $this->assertSame(['available' => $beforeAudit['balances']['available'],
            'reserved' => $beforeAudit['balances']['reserved'] - $amount,
            'consumed' => $beforeAudit['balances']['consumed'] + $amount,
            'expired' => $beforeAudit['balances']['expired']], $audit['balances']);
        $this->assertSame(array_sum($beforeAudit['balances']), array_sum($audit['balances']));
        $paths = $this->memberActivationFiles($result->redemptionId);
        $this->assertCount(1, $paths);
        $bytes = file_get_contents($paths[0]);
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertSame($artifact['sha256'], hash('sha256', $bytes));
        $this->assertSame((int) $artifact['bytes'], strlen($bytes));
        $inode = fileinode($paths[0]);

        $this->assertEquals($result, $this->readMemberActivation($fixture));
        clearstatcache(true, $paths[0]);
        $this->assertSame($inode, fileinode($paths[0]));
        $this->assertSame($bytes, file_get_contents($paths[0]));
        $this->assertSame($after, $this->memberOriginalEffects());
        $this->assertSame($audit, $this->ledgerSnapshot($fixture));
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    public function test_independent_confirmations_of_the_same_intent_return_the_first_retained_effect(): void
    {
        $fixture = $this->memberActivationFixture();
        $firstAuthorization = $this->confirmMemberActivation($fixture);
        $secondAuthorization = $this->confirmMemberActivation($fixture);
        $this->assertNotSame($firstAuthorization, $secondAuthorization);
        $first = $this->activateMemberFixture($fixture, $firstAuthorization);
        $retained = $this->memberOriginalEffects();
        $audit = $this->ledgerSnapshot($fixture);
        $repeat = $this->activateMemberFixture($fixture, $secondAuthorization);

        $this->assertEquals($first, $repeat);
        $this->assertSame($retained, $this->memberOriginalEffects());
        $this->assertSame($audit, $this->ledgerSnapshot($fixture));
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    public function test_current_source_withdrawal_during_real_rendering_leaves_no_coupled_effect(): void
    {
        $fixture = $this->memberActivationFixture();
        $before = $this->memberOriginalEffects();
        $calls = 0;
        $observations = [];
        $operation = $this->memberActivationOperation(function () use ($fixture, &$calls, &$observations): void {
            $calls++;
            $observations['transaction_level'] = DB::transactionLevel();
            $observations['pdo_in_transaction'] = DB::connection()->getPdo()->inTransaction();
            $observations['track_updates'] = DB::table('tracks')->where('id', $fixture['catalog']['track']->id)->update(['status' => 'draft']);
        });
        $authorization = $this->confirmMemberActivation($fixture, $operation);
        try {
            $this->activateMemberFixture($fixture, $authorization, $operation);
            $this->fail('A source withdrawn during actual rendering must not produce an activation.');
        } catch (MemberGrantException $error) {
            $this->assertSame('source_refused', $error->reason);
        }
        $this->assertSame(1, $calls);
        $this->assertSame(['transaction_level' => 0, 'pdo_in_transaction' => false, 'track_updates' => 1], $observations);
        $this->assertCount(1, $this->memberActivationFiles($fixture['reservation']['redemption_id']));
        $this->assertMemberOriginalEffectsUnchanged($before);
    }
}
