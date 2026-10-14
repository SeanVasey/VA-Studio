<?php

namespace Tests\Unit;

use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Grants\Member\MemberActivationArtifactPermit;
use App\Domain\Grants\Member\MemberActivationArtifactPlanToken;
use App\Domain\Grants\Member\MemberActivationCompletionPermit;
use App\Domain\Grants\Member\MemberActivationEffectPermit;
use App\Domain\Grants\Member\MemberActivationEntryPermit;
use App\Domain\Grants\Member\MemberActivationInspection;
use App\Domain\Grants\Member\MemberGrantActivations;
use App\Domain\Grants\Member\MemberGrantException;
use App\Domain\Grants\Member\MemberGrantFactsToken;
use App\Domain\Grants\Member\MemberLicenseAuthorization;
use App\Domain\Grants\Member\MemberLicenseAuthorizationClaim;
use App\Domain\Grants\Member\MemberLicenseAuthorizations;
use App\Domain\Grants\Member\MemberLicenseReviewEntryPermit;
use App\Domain\Grants\Member\MemberOriginalArtifactManifest;
use App\Domain\Grants\Member\MemberOriginalArtifacts;
use App\Domain\Grants\Member\MemberOriginalBudget;
use App\Domain\Grants\Member\MemberOriginalFiles;
use App\Domain\Grants\Member\MemberOriginalMaterialization;
use App\Domain\Grants\Member\MemberOriginalRendererProcess;
use App\Domain\Grants\Member\MemberOriginalSourceCapture;
use App\Domain\Grants\Member\MemberOriginalSourceValues;
use App\Domain\Grants\Member\SealedMemberGrantFacts;
use App\Domain\Grants\Member\SealedMemberGrants;
use App\Domain\Memberships\Production\MemberGrantIntent;
use App\Domain\Memberships\Production\MembershipRows;
use App\Models\User;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/** Pure refusal cases: uninitialized dependencies intentionally cannot perform source or database work. */
final class MemberActivationOwnershipTest extends TestCase
{
    public function test_shaped_ticket_cannot_enter_the_real_issuer_registry(): void
    {
        $owner = $this->uninitialized(MemberGrantActivations::class);
        $sources = $this->uninitialized(MemberOriginalSourceCapture::class);
        $issuer = new MemberLicenseAuthorizations($owner, $sources);
        $this->refuses('foreign_authorization', fn () => $issuer->claim(MemberLicenseAuthorization::value(),
            MemberActivationEntryPermit::value(), $this->uninitialized(ProductionCustomerPrincipal::class), new User));
    }

    public function test_review_entry_cannot_be_fabricated_by_calling_the_opaque_value_factory(): void
    {
        $owner = $this->uninitialized(MemberGrantActivations::class);
        $sources = $this->uninitialized(MemberOriginalSourceCapture::class);
        $issuer = new MemberLicenseAuthorizations($owner, $sources);
        $this->refuses('owned_confirmation_required', fn () => MemberLicenseAuthorizations::consumeEntry(
            MemberLicenseReviewEntryPermit::value(), $issuer, $sources, 'definition', 'redemption', null,
            $this->uninitialized(ProductionCustomerPrincipal::class), new User, MemberOriginalBudget::start()));
    }

    public function test_shaped_activation_entry_refuses_before_source_capture_or_clock_refresh(): void
    {
        $this->refuses('foreign_activation_entry', fn () => MemberGrantActivations::consumeSourceEntry(
            MemberActivationEntryPermit::value(), $this->uninitialized(MemberGrantActivations::class),
            $this->uninitialized(MemberOriginalSourceCapture::class), 'definition', 'redemption',
            $this->uninitialized(ProductionCustomerPrincipal::class), new User, MemberLicenseAuthorizationClaim::value(), MemberOriginalBudget::start()));
    }

    public function test_shaped_completion_cannot_authorize_a_materialized_candidate(): void
    {
        $this->refuses('foreign_completion', fn () => MemberGrantActivations::consumeCompletion(
            MemberActivationCompletionPermit::value(), $this->uninitialized(MemberGrantActivations::class),
            $this->uninitialized(MemberOriginalSourceCapture::class), MemberOriginalMaterialization::value(), 'definition', 'redemption',
            $this->uninitialized(ProductionCustomerPrincipal::class), new User, MemberLicenseAuthorizationClaim::value(), MemberOriginalBudget::start()));
    }

    public function test_shaped_effect_permit_cannot_start_planning_even_with_a_real_type_tuple(): void
    {
        $sources = $this->uninitialized(MemberOriginalSourceCapture::class);
        $this->refuses('foreign_effect_permit', fn () => MemberOriginalSourceCapture::consumeInspectionEffect(
            MemberActivationEffectPermit::value(), $sources, $this->uninitialized(MemberActivationInspection::class),
            new SealedMemberGrants($sources), $this->uninitialized(MembershipRows::class)));
    }

    public function test_ticket_clone_and_restoration_are_explicitly_refused(): void
    {
        $ticket = MemberLicenseAuthorization::value();
        $this->refuses('private_activation_evidence', fn () => clone $ticket);
        $this->refuses('private_activation_evidence', fn () => serialize($ticket));
        $class = MemberLicenseAuthorization::class;
        $this->refuses('private_activation_evidence', fn () => unserialize('O:'.strlen($class).':"'.$class.'":0:{}'));
    }

    public function test_shaped_artifact_plan_permit_refuses_before_uninitialized_sources_or_rows_are_used(): void
    {
        $sources = $this->uninitialized(MemberOriginalSourceCapture::class);
        $this->refuses('foreign_artifact_plan', fn () => $sources->consumeArtifactPlanPermit(
            MemberActivationArtifactPermit::value(), $this->uninitialized(MemberOriginalArtifacts::class),
            $this->uninitialized(MemberActivationInspection::class), MemberOriginalMaterialization::value(),
            $this->uninitialized(MemberGrantIntent::class), $this->uninitialized(MemberGrantFactsToken::class),
            $this->uninitialized(MemberOriginalSourceValues::class), $this->uninitialized(ProductionCustomerPrincipal::class),
            new User, $this->uninitialized(MembershipRows::class), MemberOriginalBudget::start()));
    }

    public function test_provisional_artifact_plan_token_is_not_public_original_readiness(): void
    {
        $sources = $this->uninitialized(MemberOriginalSourceCapture::class);
        $artifacts = new MemberOriginalArtifacts($sources, new SealedMemberGrantFacts, new MemberOriginalRendererProcess, new MemberOriginalFiles);
        $token = MemberActivationArtifactPlanToken::value(new MemberOriginalArtifactManifest(
            '00000000-0000-4000-8000-000000000001', str_repeat('a', 64), [
                ['role' => 'license_pdf', 'sha256' => str_repeat('b', 64), 'bytes' => 1, 'storage_policy_hash' => str_repeat('c', 64)],
            ]));
        $this->refuses('foreign_readiness', fn () => $artifacts->proveReadyCurrent($token,
            $this->uninitialized(MemberGrantIntent::class), $this->uninitialized(MemberGrantFactsToken::class),
            $this->uninitialized(ProductionCustomerPrincipal::class), new User, $this->uninitialized(MembershipRows::class)));
    }

    public function test_exception_keeps_the_actual_database_failure_as_its_cause(): void
    {
        $native = new \PDOException('synthetic INSERT refusal', 1142);
        $error = new MemberGrantException('source_refused', previous: $native);
        self::assertSame($native, $error->getPrevious());
        self::assertSame('source_refused', $error->reason);
        self::assertSame('Member original unavailable.', $error->getMessage());
    }

    private function uninitialized(string $class): object
    {
        return (new ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    private function refuses(string $reason, callable $call): void
    {
        try {
            $call();
            self::fail('Unregistered authority was admitted.');
        } catch (MemberGrantException $error) {
            self::assertSame($reason, $error->reason);
        }
    }
}
