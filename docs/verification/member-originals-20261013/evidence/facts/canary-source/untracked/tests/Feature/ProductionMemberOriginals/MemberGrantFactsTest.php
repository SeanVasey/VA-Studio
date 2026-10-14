<?php

namespace Tests\Feature\ProductionMemberOriginals;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Customers\ProductionIdentity\IdentityCommittedFrame;
use App\Domain\Grants\Member\MemberGrantDefinitionDocument;
use App\Domain\Grants\Member\MemberGrantDefinitions;
use App\Domain\Grants\Member\MemberGrantException;
use App\Domain\Grants\Member\MemberGrantFactsAuthority;
use App\Domain\Grants\Member\MemberGrantFactsClosure;
use App\Domain\Grants\Member\MemberGrantFactsToken;
use App\Domain\Grants\Member\MemberGrantProfileDocument;
use App\Domain\Grants\Member\MemberOriginalArtifactAuthority;
use App\Domain\Grants\Member\SealedMemberGrantFacts;
use App\Domain\Memberships\Production\MembershipReservationAuthority;
use App\Domain\Memberships\Production\MembershipRows;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MemberGrantDocumentFixtures;
use Tests\Support\MembershipAwardFixtures;
use Tests\TestCase;

/** Real identity/plan/MFA authors; catalog documents here are explicit synthetic retained formats. */
final class MemberGrantFactsTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use MembershipAwardFixtures;

    private function fixture(): array
    {
        $this->awardSetup();
        $staff = $this->policyStaff();
        $plan = $this->createPolicy($staff);
        $buyer = $this->enrollThroughLocalSmtp();
        config(['member-grants.authoring_enabled' => true, 'member-grants.provenance' => 'synthetic_rehearsal']);
        $profile = MemberGrantProfileDocument::from(MemberGrantDocumentFixtures::profile());
        $input = MemberGrantDocumentFixtures::definition();
        $input['plan_version_id'] = $plan['id'];
        $input['policy_hash'] = $plan['policy_hash'];
        $definition = MemberGrantDefinitionDocument::from($input);
        $authored = (new MemberGrantDefinitions)->author($profile, $definition, $staff);
        $issuer = new SealedMemberGrantFacts;
        $this->app->instance(MemberGrantFactsAuthority::class, $issuer);
        // Presence-only gates in this isolated facts test. Neither capability is invoked or grants readiness.
        $this->app->instance(MemberOriginalArtifactAuthority::class, Mockery::mock(MemberOriginalArtifactAuthority::class));
        $this->app->instance(MembershipReservationAuthority::class, Mockery::mock(MembershipReservationAuthority::class));
        config(['member-grants.enabled' => true, 'member-grants.approved_profile_hash' => $profile->hash(),
            'member-grants.approved_definition_hash' => $definition->hash(), 'member-grants.approved_original_terms_hash' => $input['original_terms_hash']]);

        return compact('issuer', 'buyer', 'authored');
    }

    public function test_real_authored_facts_yield_detached_snapshot_and_one_combined_committed_frame(): void
    {
        ['issuer' => $issuer, 'buyer' => $buyer, 'authored' => $authored] = $this->fixture();
        DB::beginTransaction();
        $rows = new MembershipRows;
        $proof = $issuer->lock($authored->definitionId, $buyer['principal'], $buyer['user'], $rows);
        $snapshot = $issuer->snapshot($proof, $buyer['principal'], $buyer['user'], $rows);
        $closure = $issuer->closure($proof, $buyer['principal'], $buyer['user'], $rows);
        $issuer->currentCallbacks($closure, $rows);
        $issuer->currentRaw($closure, $rows);
        self::assertSame($authored->definitionHash, $snapshot->definitionDocument->hash());
        self::assertSame($authored->profileId, $snapshot->factsBinding->profileId);
        DB::commit();
        $pdo = DB::connection()->getPdo();
        $frame = IdentityCommittedFrame::begin(new CurrentRows($pdo, DB::getDriverName()), hrtime(true) + 60_000_000_000);
        try {
            $issuer->committedCallbacks($closure, $frame);
            $issuer->committedRaw($closure, $frame);
            self::assertTrue($pdo->inTransaction());
            $frame->finish();
            self::assertFalse($pdo->inTransaction());
        } finally {
            $frame->close();
        }
    }

    public function test_raw_closure_detects_owner_change_after_callback_phase(): void
    {
        ['issuer' => $issuer, 'buyer' => $buyer, 'authored' => $authored] = $this->fixture();
        DB::beginTransaction();
        try {
            $rows = new MembershipRows;
            $proof = $issuer->lock($authored->definitionId, $buyer['principal'], $buyer['user'], $rows);
            $closure = $issuer->closure($proof, $buyer['principal'], $buyer['user'], $rows);
            $issuer->currentCallbacks($closure, $rows);
            $statement = $rows->identity()->prepare('UPDATE users SET email_verified_at = NULL WHERE id = ?');
            $statement->execute([$buyer['user']->id]);
            $this->refuses(fn () => $issuer->currentRaw($closure, $rows));
        } finally {
            DB::rollBack();
        }
    }

    public function test_public_token_values_foreign_issuers_and_unregistered_closures_have_no_admission(): void
    {
        ['issuer' => $issuer, 'buyer' => $buyer, 'authored' => $authored] = $this->fixture();
        DB::beginTransaction();
        try {
            $rows = new MembershipRows;
            $proof = $issuer->lock($authored->definitionId, $buyer['principal'], $buyer['user'], $rows);
            $this->refuses(fn () => $issuer->proveCurrent(new MemberGrantFactsToken($proof->binding()), $buyer['principal'], $buyer['user'], $rows));
            $this->refuses(fn () => (new SealedMemberGrantFacts)->proveCurrent($proof, $buyer['principal'], $buyer['user'], $rows));
            $this->refuses(fn () => $issuer->currentRaw(new MemberGrantFactsClosure, $rows));
            $this->refuses(fn () => clone $proof);
            $this->refuses(fn () => serialize($proof));
            $this->refuses(fn () => json_encode($proof, JSON_THROW_ON_ERROR));
        } finally {
            DB::rollBack();
        }
    }

    public function test_required_full_customer_gate_is_not_replaced_by_authoring_flag(): void
    {
        ['issuer' => $issuer, 'buyer' => $buyer, 'authored' => $authored] = $this->fixture();
        config(['member-grants.enabled' => false]);
        DB::beginTransaction();
        try {
            $rows = new MembershipRows;
            $this->refuses(fn () => $issuer->lock($authored->definitionId, $buyer['principal'], $buyer['user'], $rows));
        } finally {
            DB::rollBack();
        }
    }

    private function refuses(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected facts refusal.');
        } catch (MemberGrantException) {
            $this->addToAssertionCount(1);
        }
    }
}
