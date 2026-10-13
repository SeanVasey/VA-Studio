<?php

namespace Tests\Support;

use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Grants\Member\MemberGrantDefinitionDocument;
use App\Domain\Grants\Member\MemberGrantDefinitions;
use App\Domain\Grants\Member\MemberGrantFactsAuthority;
use App\Domain\Grants\Member\MemberGrantProfileDocument;
use App\Domain\Grants\Member\MemberGrantRenderProfile;
use App\Domain\Grants\Member\MemberGrantSchema;
use App\Domain\Grants\Member\MemberOriginalArtifactAuthority;
use App\Domain\Grants\Member\MemberOriginalCandidate;
use App\Domain\Grants\Member\MemberOriginalFiles;
use App\Domain\Grants\Member\MemberOriginalIoFrame;
use App\Domain\Grants\Member\MemberOriginalRendererProcess;
use App\Domain\Grants\Member\MemberOriginals;
use App\Domain\Grants\Member\SealedMemberGrantFacts;
use App\Domain\Memberships\Production\MemberGrantIntent;
use App\Domain\Memberships\Production\MembershipCatalogEvidence;
use App\Domain\Memberships\Production\MembershipPeriodRecords;
use App\Domain\Memberships\Production\MembershipReservationAuthority;
use App\Domain\Memberships\Production\MembershipReservationRecords;
use App\Domain\Memberships\Production\MembershipReservations;
use App\Domain\Memberships\Production\MembershipRows;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;

/** Real synthetic producers and bytes. The inherited future-grant double deliberately refuses every call. */
trait MemberOriginalFixtures
{
    use MembershipReservationFixtures;

    protected function memberOriginalSetup(): void
    {
        $this->reservationSetup();
        config(['member-grants.enabled' => false, 'member-grants.authoring_enabled' => true,
            'member-grants.provenance' => IdentityPolicy::REHEARSAL]);
    }

    protected function memberOriginalFixture(): array
    {
        // Explicit synthetic honor supports this preparation fixture; it is not a commercial default.
        $fixture = $this->reservationFixture(honorOverrides: ['duration_seconds' => 300]);
        $reservation = $this->reserveFixture($fixture);
        $selected = DB::transaction(function () use ($fixture): array {
            $rows = new MembershipRows;
            $started = hrtime(true);
            $snapshot = MembershipCatalogEvidence::lock($rows, $fixture['selection'], $fixture['expected_revision'],
                CarbonImmutable::now('UTC'), $started + 60_000_000_000, $started);

            // Copy complete admitted values for staff authoring; no proof escapes this frame.
            return $snapshot->selection['selection']['lines'][0]['offer_snapshot'];
        });
        $license = $selected['license'];
        $terms = ['purpose' => 'membership-license-original-terms-v1', 'authored_source' => $license['authored_source'],
            'structured_terms' => $license['structured_terms'], 'renderer_version' => $license['renderer_version'],
            'render_fixture_hash' => $license['render_fixture_hash']];
        $this->assertSame($fixture['license_binding']->licenseManifestHash, CanonicalJson::hash($license));
        $this->assertSame($fixture['license_binding']->assetManifestHash, CanonicalJson::hash($selected['assets']));
        $this->assertSame($fixture['license_binding']->originalTermsHash, CanonicalJson::hash($terms));
        $descriptor = MemberGrantRenderProfile::current(IdentityPolicy::REHEARSAL, MemberOriginalIoFrame::captureIdle());
        $profile = MemberGrantProfileDocument::from(['schema_version' => 'member-grant-profile-document-v1',
            'family' => MemberGrantIntent::FAMILY, 'purpose' => MemberGrantIntent::PURPOSE,
            'provenance' => IdentityPolicy::REHEARSAL, 'render_profile' => $descriptor, 'original_terms' => $terms]);
        $retention = ['schema_version' => 'member-original-retention-facts-v1',
            'reference' => 'synthetic-member-original-integration-20261013',
            'text' => 'SYNTHETIC TEST RETENTION FACTS. No customer terms or production retention approval.'];
        $storage = ['schema_version' => 'member-original-storage-policy-v1', 'backend' => 'private_local',
            'layout' => 'production-member-candidate-v1', 'provenance' => IdentityPolicy::REHEARSAL,
            'ownership_reference' => 'SYNTHETIC isolated integration storage. No host verification.',
            'retention_policy_hash' => CanonicalJson::hash($retention)];
        $definition = MemberGrantDefinitionDocument::from(['schema_version' => 'member-grant-definition-document-v1',
            'family' => MemberGrantIntent::FAMILY, 'purpose' => MemberGrantIntent::PURPOSE, 'version' => 1,
            'provenance' => IdentityPolicy::REHEARSAL, 'profile_hash' => $profile->hash(),
            'plan_version_id' => $fixture['plan']['id'], 'policy_hash' => $fixture['plan']['policy_hash'],
            'license_manifest' => $license, 'asset_manifest' => $selected['assets'],
            'original_terms_hash' => CanonicalJson::hash($terms), 'retention_policy' => $retention, 'storage_policy' => $storage]);
        $authored = (new MemberGrantDefinitions)->author($profile, $definition, $fixture['staff']);
        config(['member-grants.enabled' => true, 'member-grants.approved_definition_hash' => $authored->definitionHash,
            'member-grants.approved_profile_hash' => $authored->profileHash,
            'member-grants.approved_original_terms_hash' => CanonicalJson::hash($terms)]);

        return [...$fixture, 'reservation' => $reservation, 'member_profile' => $profile,
            'member_definition' => $definition, 'member_authored' => $authored];
    }

    protected function memberOriginalOperation(?Closure $processFactory = null): MemberOriginals
    {
        $facts = new SealedMemberGrantFacts;
        $reservations = new MembershipReservations;
        $operation = new MemberOriginals($facts, $reservations, new MemberOriginalRendererProcess($processFactory), new MemberOriginalFiles);
        $this->app->instance(MemberGrantFactsAuthority::class, $facts);
        $this->app->instance(MembershipReservationAuthority::class, $reservations);
        $this->app->instance(MemberOriginalArtifactAuthority::class, $operation->artifactAuthority());

        return $operation;
    }

    protected function prepareMemberOriginal(array $fixture, ?MemberOriginals $operation = null): MemberOriginalCandidate
    {
        $operation ??= $this->memberOriginalOperation();

        return $operation->prepare($fixture['member_authored']->definitionId, $fixture['reservation']['redemption_id'],
            $fixture['buyer']['principal'], $fixture['buyer']['user']);
    }

    /** Exact retained rows, including ciphertext/seals, detect any preparation-side credit or original effect. */
    protected function memberOriginalEffects(): array
    {
        $result = [];
        foreach ([MembershipPeriodRecords::PERIODS, MembershipPeriodRecords::EVENTS,
            MembershipReservationRecords::TABLE, ...MemberGrantSchema::TABLES] as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
        }

        return $result;
    }

    protected function assertMemberOriginalEffectsUnchanged(array $before): void
    {
        $this->assertSame($before, $this->memberOriginalEffects());
        foreach (['production_member_origins', 'production_member_artifacts', 'production_member_activations'] as $table) {
            $this->assertSame([], $before[$table]);
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    protected function memberOriginalCandidatePath(MemberOriginalCandidate $candidate): string
    {
        return config('filesystems.disks.local.root').'/contracts/production-member-v1/'.IdentityPolicy::REHEARSAL
            .'/'.$candidate->originId.'/'.$candidate->renderSourceHash.'/original.pdf';
    }
}
