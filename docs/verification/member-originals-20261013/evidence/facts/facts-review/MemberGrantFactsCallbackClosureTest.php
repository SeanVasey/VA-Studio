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

use Illuminate\Support\Facades\Crypt;
use PHPUnit\Framework\Attributes\DataProvider;

final class MemberGrantFactsCallbackClosureTest extends TestCase
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


    public static function lateChanges(): iterable
    {
        foreach (['current', 'committed'] as $phase) {
            foreach (['identity_configuration', 'actor_id_reference', 'actor_exists'] as $change) {
                yield $phase.' '.$change => [$phase, $change];
            }
        }
    }

    #[DataProvider('lateChanges')]
    public function test_late_record_decrypt_callback_cannot_change_admitted_owner_inputs(string $phase, string $change): void
    {
        ['issuer' => $issuer, 'buyer' => $buyer, 'authored' => $authored] = $this->fixture();
        $actor = $buyer['user'];
        $id = (int) $actor->id;
        if ($change === 'actor_id_reference') {
            $attributes = $actor->getAttributes();
            $attributes['id'] =& $id;
            $actor->setRawAttributes($attributes, true);
        }
        $target = DB::table('production_member_profiles')->where('id', $authored->profileId)->value('payload_ciphertext');
        DB::beginTransaction();
        $rows = new MembershipRows;
        $proof = $issuer->lock($authored->definitionId, $buyer['principal'], $actor, $rows);
        $closure = $issuer->closure($proof, $buyer['principal'], $actor, $rows);
        $frame = null;
        if ($phase === 'committed') {
            DB::commit();
            $frame = IdentityCommittedFrame::begin(new CurrentRows($rows->identity(), $rows->driver()), hrtime(true) + 60_000_000_000);
        }
        $real = Crypt::getFacadeRoot();
        $calls = 0;
        $fake = Mockery::mock();
        $fake->shouldReceive('decryptString')->andReturnUsing(function ($ciphertext) use ($real, $target, $change, $actor, &$id, &$calls) {
            $plain = $real->decryptString($ciphertext);
            if ($ciphertext === $target && $calls++ === 0) {
                match ($change) {
                    'identity_configuration' => config(['production-customer-identity.enabled' => false]),
                    'actor_id_reference' => $id++,
                    'actor_exists' => $actor->exists = false,
                };
            }

            return $plain;
        });
        Crypt::swap($fake);
        $refused = false;
        try {
            try {
                if ($phase === 'current') {
                    $issuer->currentCallbacks($closure, $rows);
                    $issuer->currentRaw($closure, $rows);
                } else {
                    $issuer->committedCallbacks($closure, $frame);
                    $issuer->committedRaw($closure, $frame);
                }
            } catch (MemberGrantException) {
                $refused = true;
            }
            self::assertGreaterThan(0, $calls, 'The actual retained record decrypt seam must run.');
            self::assertTrue($refused, 'Changed owner input was admitted after the record decrypt callback.');
        } finally {
            Crypt::swap($real);
            $issuer->retire($closure);
            $frame?->close();
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
    }
}
