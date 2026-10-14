<?php

namespace Tests\Feature\ProductionMemberGrants;

use App\Domain\Grants\Member\MemberGrantException;
use App\Domain\Memberships\Production\MembershipPeriodRecords;
use App\Domain\Memberships\Production\MembershipReservationRecords;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use LogicException;
use Mockery;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MemberActivationFixtures;
use Tests\TestCase;

/** Actual synthetic producers, original files and normal transaction callbacks; no replacement issuer. */
class MemberGrantActivationRetentionTest extends TestCase
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

    public function test_historical_read_after_honor_delisting_and_removed_current_approvals_preserves_the_original(): void
    {
        $fixture = $this->memberActivationFixture();
        $first = $this->activateMemberFixture($fixture);
        $retained = $this->memberOriginalEffects();
        $paths = $this->memberActivationFiles($first->redemptionId);
        $this->assertCount(1, $paths);
        $bytes = file_get_contents($paths[0]);
        $inode = fileinode($paths[0]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse($retained[MembershipReservationRecords::TABLE][0]['honor_deadline'], 'UTC')->addSecond());
        $this->assertSame(1, DB::table('tracks')->where('id', $fixture['catalog']['track']->id)->update(['status' => 'draft']));
        config(['production-memberships.approved_policy_hash' => null, 'member-grants.approved_definition_hash' => null,
            'member-grants.approved_profile_hash' => null, 'member-grants.approved_original_terms_hash' => null]);
        $renders = 0;
        $operation = $this->memberActivationOperation(function () use (&$renders): void {
            $renders++;
            throw new LogicException('Historical original reads must not render.');
        });

        $this->assertEquals($first, $this->readMemberActivation($fixture, $operation));
        $this->assertSame(0, $renders);
        $this->assertSame($retained, $this->memberOriginalEffects());
        clearstatcache(true, $paths[0]);
        $this->assertSame($inode, fileinode($paths[0]));
        $this->assertSame($bytes, file_get_contents($paths[0]));
        $this->assertIdle();
    }

    public function test_missing_retained_original_refuses_without_rendering_repairing_or_changing_effects(): void
    {
        $fixture = $this->memberActivationFixture();
        $first = $this->activateMemberFixture($fixture);
        $retained = $this->memberOriginalEffects();
        $paths = $this->memberActivationFiles($first->redemptionId);
        $this->assertCount(1, $paths);
        $this->assertTrue(unlink($paths[0]));
        $renders = 0;
        $operation = $this->memberActivationOperation(function () use (&$renders): void {
            $renders++;
            throw new LogicException('A missing retained original must not be repaired by rendering.');
        });

        try {
            $this->readMemberActivation($fixture, $operation);
            $this->fail('A missing retained original must refuse.');
        } catch (MemberGrantException $error) {
            $this->assertSame('original_unavailable', $error->reason);
        }
        $this->assertSame(0, $renders);
        $this->assertSame([], $this->memberActivationFiles($first->redemptionId));
        $this->assertFileDoesNotExist($paths[0]);
        $this->assertSame($retained, $this->memberOriginalEffects());
        $this->assertIdle();
    }

    public function test_postcommit_flag_withdrawal_refuses_receipt_and_later_explicit_read_recovers_the_single_graph(): void
    {
        $fixture = $this->memberActivationFixture();
        $authorization = $this->confirmMemberActivation($fixture);
        $armed = true;
        $callbacks = 0;
        DB::connection()->getEventDispatcher()->listen(TransactionCommitting::class, function () use (&$armed, &$callbacks): void {
            if (! $armed || ! DB::table('production_member_activations')->exists()) {
                return;
            }
            $armed = false;
            DB::afterCommit(function () use (&$callbacks): void {
                $callbacks++;
                config(['member-grants.enabled' => false]);
            });
        });
        try {
            $this->activateMemberFixture($fixture, $authorization);
            $this->fail('Withdrawal after the durable effect must refuse its immediate receipt.');
        } catch (MemberGrantException $error) {
            $this->assertSame('committed_activation_receipt_refused', $error->reason);
        }
        $this->assertSame(1, $callbacks);
        $retained = $this->memberOriginalEffects();
        foreach (['production_member_origins', 'production_member_artifacts', 'production_member_activations'] as $table) {
            $this->assertCount(1, $retained[$table]);
        }
        $consumes = array_values(array_filter($retained[MembershipPeriodRecords::EVENTS], static fn (array $row): bool => $row['kind'] === 'consume'));
        $this->assertCount(1, $consumes);
        $paths = $this->memberActivationFiles($fixture['reservation']['redemption_id']);
        $this->assertCount(1, $paths);
        $bytes = file_get_contents($paths[0]);
        $inode = fileinode($paths[0]);
        $this->assertIdle();

        // A separate explicit read after restoring the feature is recovery, not a second effect.
        config(['member-grants.enabled' => true]);
        $renders = 0;
        $operation = $this->memberActivationOperation(function () use (&$renders): void {
            $renders++;
            throw new LogicException('Recovery must read the committed original.');
        });
        $recovered = $this->readMemberActivation($fixture, $operation);
        $this->assertSame($retained['production_member_origins'][0]['id'], $recovered->originId);
        $this->assertSame($retained['production_member_activations'][0]['id'], $recovered->activationId);
        $this->assertSame($consumes[0]['id'], $recovered->consumeEventId);
        $this->assertSame(0, $renders);
        $this->assertSame($retained, $this->memberOriginalEffects());
        clearstatcache(true, $paths[0]);
        $this->assertSame($inode, fileinode($paths[0]));
        $this->assertSame($bytes, file_get_contents($paths[0]));
        $this->assertIdle();
    }

    public function test_real_final_committing_callback_source_withdrawal_rolls_back_every_member_effect(): void
    {
        $fixture = $this->memberActivationFixture();
        $authorization = $this->confirmMemberActivation($fixture);
        $before = $this->memberOriginalEffects();
        $trackStatus = DB::table('tracks')->where('id', $fixture['catalog']['track']->id)->value('status');
        $calls = 0;
        $observations = [];
        DB::connection()->getEventDispatcher()->listen(TransactionCommitting::class, function () use ($fixture, &$calls, &$observations): void {
            if ($calls !== 0 || ! DB::table('production_member_activations')->exists()) {
                return;
            }
            $calls++;
            $observations['transaction_level'] = DB::transactionLevel();
            $observations['pdo_in_transaction'] = DB::connection()->getPdo()->inTransaction();
            $observations['track_updates'] = DB::table('tracks')->where('id', $fixture['catalog']['track']->id)->update(['status' => 'draft']);
        });
        try {
            $this->activateMemberFixture($fixture, $authorization);
            $this->fail('A source withdrawn by the real final commit callback must refuse.');
        } catch (MemberGrantException $error) {
            $this->assertSame('source_refused', $error->reason);
        }
        $this->assertSame(1, $calls);
        $this->assertSame(['transaction_level' => 1, 'pdo_in_transaction' => true, 'track_updates' => 1], $observations);
        $this->assertMemberOriginalEffectsUnchanged($before);
        $this->assertSame($trackStatus, DB::table('tracks')->where('id', $fixture['catalog']['track']->id)->value('status'));
    }

    public function test_catalog_withdrawal_during_actual_origin_encryption_is_refused_before_any_member_insert(): void
    {
        $fixture = $this->memberActivationFixture();
        $before = $this->memberOriginalEffects();
        $trackStatus = DB::table('tracks')->where('id', $fixture['catalog']['track']->id)->value('status');
        $real = Crypt::getFacadeRoot();
        $calls = 0;
        $observations = [];
        $observed = Mockery::mock();
        $observed->shouldReceive('decryptString')->andReturnUsing($real->decryptString(...));
        $observed->shouldReceive('encryptString')->andReturnUsing(function ($plain) use ($real, $fixture, &$calls, &$observations): string {
            $document = json_decode($plain, true, 48, JSON_THROW_ON_ERROR);
            if ($calls === 0 && ($document['schema_version'] ?? null) === 'member-origin-record-v1') {
                $calls++;
                $observations['transaction_level'] = DB::transactionLevel();
                $observations['pdo_in_transaction'] = DB::connection()->getPdo()->inTransaction();
                $observations['origin_count'] = DB::table('production_member_origins')->count();
                $observations['track_updates'] = DB::table('tracks')->where('id', $fixture['catalog']['track']->id)->update(['status' => 'draft']);
            }

            // The observation hook never replaces encrypted bytes or a successful source proof.
            return $real->encryptString($plain);
        });
        Crypt::swap($observed);
        try {
            $authorization = $this->confirmMemberActivation($fixture);
            try {
                $this->activateMemberFixture($fixture, $authorization);
                $this->fail('Record encryption cannot make a withdrawn catalog source admissible.');
            } catch (MemberGrantException $error) {
                $this->assertSame('source_refused', $error->reason);
            }
            $this->assertSame(1, $calls, 'The actual origin builder must reach the source withdrawal hook.');
            $this->assertSame(['transaction_level' => 1, 'pdo_in_transaction' => true,
                'origin_count' => 0, 'track_updates' => 1], $observations);
            $this->assertMemberOriginalEffectsUnchanged($before);
            $this->assertSame($trackStatus, DB::table('tracks')->where('id', $fixture['catalog']['track']->id)->value('status'));
        } finally {
            Crypt::swap($real);
        }
    }

    private function assertIdle(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }
}
