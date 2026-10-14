<?php

namespace Tests\Support;

use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Grants\Member\MemberGrantActivationResult;
use App\Domain\Grants\Member\MemberGrantActivations;
use App\Domain\Grants\Member\MemberGrantDefinitionDocument;
use App\Domain\Grants\Member\MemberGrantDefinitions;
use App\Domain\Grants\Member\MemberGrantFactsAuthority;
use App\Domain\Grants\Member\MemberGrantProfileDocument;
use App\Domain\Grants\Member\MemberGrantRenderProfile;
use App\Domain\Grants\Member\MemberLicenseAuthorization;
use App\Domain\Grants\Member\MemberLicenseAuthorizations;
use App\Domain\Grants\Member\MemberLicenseConfirmationInput;
use App\Domain\Grants\Member\MemberOriginalArtifactAuthority;
use App\Domain\Grants\Member\MemberOriginalFiles;
use App\Domain\Grants\Member\MemberOriginalIoFrame;
use App\Domain\Grants\Member\MemberOriginalRendererProcess;
use App\Domain\Grants\Member\SealedMemberGrantFacts;
use App\Domain\Memberships\Production\CurrentEligibleLicenses;
use App\Domain\Memberships\Production\MemberGrantAuthority;
use App\Domain\Memberships\Production\MemberGrantIntent;
use App\Domain\Memberships\Production\MembershipCatalogEvidence;
use App\Domain\Memberships\Production\MembershipEligibleLicenseAuthority;
use App\Domain\Memberships\Production\MembershipPaidInvoiceAuthority;
use App\Domain\Memberships\Production\MembershipPolicyDocument;
use App\Domain\Memberships\Production\MembershipPolicyFactsAuthority;
use App\Domain\Memberships\Production\MembershipReservationAuthority;
use App\Domain\Memberships\Production\MembershipReservations;
use App\Domain\Memberships\Production\MembershipRows;
use App\Domain\Memberships\Production\SealedMembershipPaidInvoice;
use App\Domain\Memberships\Production\SealedMembershipPolicyFacts;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;

/** Synthetic authored facts, real producers and actual child/files. No replacement grant authority. */
trait MemberActivationFixtures
{
    use MemberOriginalFixtures;

    private MemberGrantActivations $activationOperation;

    protected function memberActivationSetup(): void
    {
        $this->memberOriginalSetup();
    }

    protected function memberActivationFixture(array $policyOverrides = [], array $honorOverrides = []): array
    {
        $consumption = $this->consumptionTermsInput();
        $retention = ['schema_version' => 'member-original-retention-facts-v1',
            'reference' => 'synthetic-member-activation-integration-20261013',
            'text' => 'SYNTHETIC TEST RETENTION FACTS. No customer terms or production retention approval.'];
        $consumption['retention_policy'] = $retention;

        // The shared producer derives every rule from its actual selected catalog. Only explicit
        // synthetic v3 terms are overlaid; the pure fixture's placeholder rule IDs are never used.
        $fixture = $this->reservationFixture(policyOverrides: [
            'schema_version' => MembershipPolicyDocument::CONSUMPTION_VERSION,
            'scope' => 'paid_period_credit_activation', 'consumption_terms' => $consumption, ...$policyOverrides,
        ], honorOverrides: ['duration_seconds' => 300, ...$honorOverrides]);
        $reservation = $this->reserveFixture($fixture);
        $selected = DB::transaction(function () use ($fixture): array {
            $rows = new MembershipRows;
            $started = hrtime(true);
            $snapshot = MembershipCatalogEvidence::lock($rows, $fixture['selection'], $fixture['expected_revision'],
                CarbonImmutable::now('UTC'), $started + 60_000_000_000, $started);

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
        $storage = ['schema_version' => 'member-original-storage-policy-v1', 'backend' => 'private_local',
            'layout' => 'production-member-candidate-v1', 'provenance' => IdentityPolicy::REHEARSAL,
            'ownership_reference' => 'SYNTHETIC isolated activation storage. No host verification.',
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
            'member_definition' => $definition, 'member_authored' => $authored, 'operation' => $this->activationOperation];
    }

    /** Override the inherited setup before award/reserve: every registered capability is genuine. */
    protected function reservationTestCapabilities(): void
    {
        $this->app->instance(MembershipPaidInvoiceAuthority::class, new SealedMembershipPaidInvoice);
        $this->app->instance(MembershipPolicyFactsAuthority::class, new SealedMembershipPolicyFacts);
        $this->app->instance(MembershipEligibleLicenseAuthority::class, new CurrentEligibleLicenses);
        $this->activationOperation = $this->memberActivationOperation();
    }

    protected function memberActivationOperation(?Closure $beforeStart = null): MemberGrantActivations
    {
        $facts = new SealedMemberGrantFacts;
        $reservations = new MembershipReservations;
        $operation = new MemberGrantActivations($facts, $reservations,
            new MemberOriginalRendererProcess($beforeStart), new MemberOriginalFiles);
        $this->app->instance(MemberGrantFactsAuthority::class, $facts);
        $this->app->instance(MembershipReservationAuthority::class, $reservations);
        $this->app->instance(MemberOriginalArtifactAuthority::class, $operation->artifactAuthority());
        $this->app->instance(MemberGrantAuthority::class, $operation->grantAuthority());
        $this->app->instance(MemberLicenseAuthorizations::class, $operation->authorizations());

        return $operation;
    }

    protected function confirmMemberActivation(array $fixture, ?MemberGrantActivations $operation = null): MemberLicenseAuthorization
    {
        $operation ??= $fixture['operation'];
        $issuer = $operation->authorizations();
        $display = $issuer->review($fixture['member_authored']->definitionId, $fixture['reservation']['redemption_id'],
            $fixture['buyer']['principal'], $fixture['buyer']['user']);

        return $issuer->confirm($fixture['member_authored']->definitionId, $fixture['reservation']['redemption_id'],
            MemberLicenseConfirmationInput::from(['display_hash' => $display->hash(), 'affirmed' => true]),
            $fixture['buyer']['principal'], $fixture['buyer']['user']);
    }

    protected function activateMemberFixture(array $fixture, ?MemberLicenseAuthorization $authorization = null,
        ?MemberGrantActivations $operation = null): MemberGrantActivationResult
    {
        $operation ??= $fixture['operation'];
        $authorization ??= $this->confirmMemberActivation($fixture, $operation);

        return $operation->activate($fixture['member_authored']->definitionId, $fixture['reservation']['redemption_id'],
            $authorization, $fixture['buyer']['principal'], $fixture['buyer']['user']);
    }

    protected function readMemberActivation(array $fixture, ?MemberGrantActivations $operation = null): MemberGrantActivationResult
    {
        $operation ??= $fixture['operation'];

        return $operation->readActivated($fixture['member_authored']->definitionId, $fixture['reservation']['redemption_id'],
            $fixture['buyer']['principal'], $fixture['buyer']['user']);
    }

    protected function memberActivationFiles(string $redemptionId): array
    {
        return glob(config('filesystems.disks.local.root').'/contracts/production-member-v1/'.IdentityPolicy::REHEARSAL
            .'/'.$redemptionId.'/*/original.pdf');
    }
}
