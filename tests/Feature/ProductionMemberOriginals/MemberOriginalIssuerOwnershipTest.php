<?php

namespace Tests\Feature\ProductionMemberOriginals;

use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Grants\Member\MemberGrantException;
use App\Domain\Grants\Member\MemberOriginalBudget;
use App\Domain\Grants\Member\MemberOriginalCapturePermit;
use App\Domain\Grants\Member\MemberOriginalFiles;
use App\Domain\Grants\Member\MemberOriginalIoFrame;
use App\Domain\Grants\Member\MemberOriginalMaterialization;
use App\Domain\Grants\Member\MemberOriginalRendererProcess;
use App\Domain\Grants\Member\MemberOriginalSource;
use App\Domain\Grants\Member\MemberOriginalSourceCapture;
use App\Domain\Grants\Member\MemberOriginalSourceClaim;
use App\Domain\Grants\Member\MemberOriginalSourceValues;
use App\Domain\Grants\Member\SealedMemberGrantFacts;
use App\Domain\Memberships\Billing\BillingPolicy;
use App\Domain\Memberships\Production\MembershipAwardConfiguration;
use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipMemberCapture;
use App\Domain\Memberships\Production\MembershipPolicy;
use App\Domain\Memberships\Production\MembershipReservationContext;
use App\Domain\Memberships\Production\MembershipReservations;
use App\Domain\Memberships\Production\MembershipRows;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use ReflectionProperty;
use Tests\Support\MemberOriginalRendererFixtures;
use Tests\TestCase;

/** Issuance refusal only. Uninitialized value shapes never stand in for successful native producers. */
class MemberOriginalIssuerOwnershipTest extends TestCase
{
    public function test_public_budget_and_real_service_assembly_cannot_mint_an_owned_source_workflow(): void
    {
        [$source] = $this->assembly();
        $principal = (new ReflectionClass(ProductionCustomerPrincipal::class))->newInstanceWithoutConstructor();
        $this->refused(fn () => $source->capture('unused', 'unused', $principal, new User, MemberOriginalBudget::start()), 'owned_workflow_required');
        $this->assertSame([], DB::getConnections());
    }

    public function test_shaped_source_claim_and_materialization_are_refused_by_the_actual_issuer(): void
    {
        DB::connection()->getPdo();
        $idle = MemberOriginalIoFrame::captureIdle();
        [$source, $artifacts] = $this->assembly();
        $this->refused(fn () => $artifacts->prepare(MemberOriginalSource::value(), $idle), 'foreign_source');
        $claim = MemberOriginalSourceClaim::value(MemberOriginalSourceValues::from(MemberOriginalRendererFixtures::sourceDocument()), MemberOriginalBudget::start());
        $this->refused(fn () => $source->proveClaimCurrent($claim, $artifacts, $idle), 'foreign_source_claim');
        $this->refused(fn () => $artifacts->verifyCurrentBytes(MemberOriginalMaterialization::value(), $idle), 'foreign_materialization');
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    public function test_unregistered_capture_permit_and_context_shape_never_start_an_inspection(): void
    {
        $issuer = new MembershipReservations;
        $rows = (new ReflectionClass(MembershipRows::class))->newInstanceWithoutConstructor();
        $principal = (new ReflectionClass(ProductionCustomerPrincipal::class))->newInstanceWithoutConstructor();
        $this->refused(fn () => $issuer->beginMemberCapture(MemberOriginalCapturePermit::value(), 'unused', $principal, new User, $rows), 'foreign_capture_permit');
        try {
            $issuer->memberIntent(MembershipMemberCapture::value(), $rows);
            $this->fail('A publicly shaped capture must not be registered.');
        } catch (MembershipException $error) {
            $this->assertSame('foreign_member_capture', $error->reason);
        }
        $this->assertSame([], DB::getConnections());
    }

    public function test_a_caller_cannot_select_pending_member_initialization_for_any_context_operation(): void
    {
        $pdo = DB::connection()->getPdo();
        $rows = (new ReflectionClass(MembershipRows::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty($rows, 'primary'))->setValue($rows, $pdo);
        $configuration = (new ReflectionClass(MembershipAwardConfiguration::class))->newInstanceWithoutConstructor();
        $principal = (new ReflectionClass(ProductionCustomerPrincipal::class))->newInstanceWithoutConstructor();
        foreach (['inspect', 'reserve', 'release', 'expire'] as $operation) {
            try {
                new MembershipReservationContext($rows, $configuration, new MembershipPolicy, [],
                    new BillingPolicy, [], $principal, new User, hrtime(true) + 60_000_000_000,
                    $operation, 'unused', [], MembershipMemberCapture::value());
                $this->fail('A shaped capture cannot authorize deferred initialization.');
            } catch (MembershipException $error) {
                $this->assertSame('owned_invocation_required', $error->reason);
            }
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse($pdo->inTransaction());
    }

    public function test_opaque_value_shapes_refuse_restoration_without_calling_a_constructor(): void
    {
        foreach ([MemberOriginalSource::class, MemberOriginalCapturePermit::class, MemberOriginalMaterialization::class,
            MemberOriginalSourceClaim::class, MembershipMemberCapture::class] as $class) {
            try {
                unserialize('O:'.strlen($class).':"'.$class.'":0:{}');
                $this->fail('Private proof shapes must refuse restoration.');
            } catch (MemberGrantException|MembershipException $error) {
                $this->assertSame('private_token', $error->reason);
            }
        }
    }

    private function assembly(): array
    {
        return MemberOriginalSourceCapture::assembly(new SealedMemberGrantFacts, new MembershipReservations,
            new MemberOriginalRendererProcess, new MemberOriginalFiles);
    }

    private function refused(callable $work, string $reason): void
    {
        try {
            $work();
            $this->fail('Expected an issuer-owned refusal.');
        } catch (MemberGrantException $error) {
            $this->assertSame($reason, $error->reason);
        }
    }
}
