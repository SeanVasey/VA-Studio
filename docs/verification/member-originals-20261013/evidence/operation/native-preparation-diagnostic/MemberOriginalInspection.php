<?php

namespace App\Domain\Grants\Member;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Customers\ProductionIdentity\IdentityCommittedFrame;
use App\Domain\Memberships\Production\MemberGrantIntent;
use App\Domain\Memberships\Production\MembershipMemberCapture;
use App\Domain\Memberships\Production\MembershipReservations;
use App\Domain\Memberships\Production\MembershipRows;
use App\Models\User;

/** Private registered read-only composition; no entry here can select a writer. */
final class MemberOriginalInspection
{
    private MembershipMemberCapture $reservation;

    private MemberGrantFactsProof $factsProof;

    private MemberGrantFactsClosure $factsClosure;

    private MemberGrantIntent $intent;

    private MemberOriginalSourceValues $values;

    private bool $retired = false;

    private bool $committed = false;

    private ?MemberOriginalMaterialization $materialized = null;

    public function __construct(private readonly MemberOriginalSourceCapture $issuer,
        private readonly MembershipRows $rows, private readonly MembershipReservations $reservations,
        private readonly SealedMemberGrantFacts $facts, private readonly MemberOriginalArtifacts $artifacts,
        private readonly MemberOriginalConfiguration $configuration, private readonly MemberOriginalFinishBoundary $finish,
        private readonly MemberOriginalBudget $budget, private readonly int $deadline, string $definitionId,
        string $redemptionId, private readonly ProductionCustomerPrincipal $principal, private readonly User $actor)
    {
        $permit = MemberOriginalSourceCapture::consumeInspection($this, $issuer, $rows, $reservations, $facts, $artifacts,
            $configuration, $finish, $budget, $deadline, $definitionId, $redemptionId, $principal, $actor);
        try {
            \Tests\Support\MemberOriginalNativeDiagnostic::emit('before.initial.reservation', $this->deadline);
        $this->reservation = $reservations->beginMemberCapture($permit, $redemptionId, $principal, $actor, $rows);
        \Tests\Support\MemberOriginalNativeDiagnostic::emit('after.initial.reservation', $this->deadline);
            $budget->shortenTo($reservations->memberHonorDeadline($this->reservation));
            $budget->assertCurrent();
            $this->intent = $reservations->memberIntent($this->reservation, $rows);
            \Tests\Support\MemberOriginalNativeDiagnostic::emit('before.initial.facts', $this->deadline);
        $this->factsProof = $facts->lock($definitionId, $principal, $actor, $rows);
        \Tests\Support\MemberOriginalNativeDiagnostic::emit('after.initial.facts', $this->deadline);
            $snapshot = $facts->snapshot($this->factsProof, $principal, $actor, $rows);
            $this->factsClosure = $facts->closure($this->factsProof, $principal, $actor, $rows);
            $parts = $reservations->memberSourceValues($this->reservation, $rows);
            $this->values = MemberOriginalSourceValues::from(['schema_version' => 'member-original-source-v1',
                'family' => MemberGrantIntent::FAMILY, 'purpose' => MemberGrantIntent::PURPOSE,
                'provenance' => $snapshot->factsBinding->provenance, 'origin_id' => $redemptionId] + $parts + [
                    'definition' => ['id' => $snapshot->factsBinding->definitionId, 'seal' => $snapshot->definitionSeal,
                        'document' => $snapshot->definitionDocument->value()],
                    'profile' => ['id' => $snapshot->profileBinding->id, 'seal' => $snapshot->profileSeal,
                        'document' => $snapshot->profileDocument->value()]]);
            $this->proveCurrent();
        } catch (\Throwable $error) {
            $this->retire();
            throw $error;
        }
    }

    public function values(): MemberOriginalSourceValues
    {
        return $this->values;
    }

    public function reader(): CurrentRows
    {
        return new CurrentRows($this->rows->identity(), $this->rows->driver());
    }

    public function ready(MemberOriginalMaterialization $materialized): void
    {
        MemberGrantException::require($this->materialized === null && ! $this->committed && ! $this->retired, 'foreign_inspection');
        $this->artifacts->admitReady($materialized, $this->intent, $this->factsProof, $this->principal, $this->actor, $this->rows);
        $this->materialized = $materialized;
    }

    public function matches(MemberGrantIntent $intent, MemberGrantFactsProof $facts, ProductionCustomerPrincipal $principal,
        User $actor, MembershipRows $rows): void
    {
        MemberGrantException::require(! $this->retired && ! $this->committed && $this->intent === $intent
            && $this->factsProof === $facts && $this->principal === $principal && $this->actor === $actor && $this->rows === $rows,
            'foreign_readiness');
        $this->admit();
    }

    public function proveCurrent(): void
    {
        MemberGrantException::require(! $this->retired && ! $this->committed, 'foreign_inspection');
        \Tests\Support\MemberOriginalNativeDiagnostic::emit('before.current.facts-callbacks', $this->deadline);
        $this->facts->currentCallbacks($this->factsClosure, $this->rows);
        \Tests\Support\MemberOriginalNativeDiagnostic::emit('after.current.facts-callbacks', $this->deadline);
        \Tests\Support\MemberOriginalNativeDiagnostic::emit('before.current.reservation-callbacks', $this->deadline);
        $this->reservations->memberCurrentCallbacks($this->reservation, $this->rows);
        \Tests\Support\MemberOriginalNativeDiagnostic::emit('after.current.reservation-callbacks', $this->deadline);
        $this->budget->shortenTo($this->reservations->memberHonorDeadline($this->reservation));
        // No callback-capable sibling phase may follow either raw source closure.
        $this->finish->assertFrame($this->rows->identity(), false);
        \Tests\Support\MemberOriginalNativeDiagnostic::emit('before.current.facts-raw', $this->deadline);
        $this->facts->currentRaw($this->factsClosure, $this->rows);
        \Tests\Support\MemberOriginalNativeDiagnostic::emit('after.current.facts-raw', $this->deadline);
        \Tests\Support\MemberOriginalNativeDiagnostic::emit('before.current.reservation-raw', $this->deadline);
        $this->reservations->memberCurrentRaw($this->reservation, $this->rows);
        \Tests\Support\MemberOriginalNativeDiagnostic::emit('after.current.reservation-raw', $this->deadline);
        $this->admit();
        $this->rows->assertCurrent();
        $this->finish->assertFrame($this->rows->identity(), false);
        $this->admit();
    }

    /** Only the distinct owned committed proof may transition this inspection. */
    public function proveCommitted(MemberOriginalCommittedProof $proof, IdentityCommittedFrame $frame): void
    {
        MemberOriginalCommittedProof::consume($proof, $this, $frame);
        MemberGrantException::require(! $this->retired && ! $this->committed, 'foreign_inspection');
        $this->committed = true;
        \Tests\Support\MemberOriginalNativeDiagnostic::emit('before.committed.facts-callbacks', $this->deadline);
        $this->facts->committedCallbacks($this->factsClosure, $frame);
        \Tests\Support\MemberOriginalNativeDiagnostic::emit('after.committed.facts-callbacks', $this->deadline);
        \Tests\Support\MemberOriginalNativeDiagnostic::emit('before.committed.reservation-callbacks', $this->deadline);
        $this->reservations->memberCommittedCallbacks($this->reservation, $frame);
        \Tests\Support\MemberOriginalNativeDiagnostic::emit('after.committed.reservation-callbacks', $this->deadline);
        $this->budget->shortenTo($this->reservations->memberHonorDeadline($this->reservation));
        $this->finish->assertFrame($this->rows->identity(), true);
        \Tests\Support\MemberOriginalNativeDiagnostic::emit('before.committed.facts-raw', $this->deadline);
        $this->facts->committedRaw($this->factsClosure, $frame);
        \Tests\Support\MemberOriginalNativeDiagnostic::emit('after.committed.facts-raw', $this->deadline);
        \Tests\Support\MemberOriginalNativeDiagnostic::emit('before.committed.reservation-raw', $this->deadline);
        $this->reservations->memberCommittedRaw($this->reservation, $frame);
        \Tests\Support\MemberOriginalNativeDiagnostic::emit('after.committed.reservation-raw', $this->deadline);
        $this->admit();
        $this->finish->assertFrame($this->rows->identity(), true);
        \Tests\Support\MemberOriginalNativeDiagnostic::emit('before.committed.finish', $this->deadline);
        $frame->finish();
        \Tests\Support\MemberOriginalNativeDiagnostic::emit('after.committed.finish', $this->deadline);
        $this->finish->assertCurrent();
        \Tests\Support\MemberOriginalNativeDiagnostic::emit('before.committed.post-finish', $this->deadline);
        $this->reservations->memberPostFinish($this->reservation, $frame);
        \Tests\Support\MemberOriginalNativeDiagnostic::emit('after.committed.post-finish', $this->deadline);
        $this->admit();
    }

    private function admit(): void
    {
        $this->configuration->admit();
        $this->budget->assertCurrent();
        MemberGrantException::require(\hrtime(true) < $this->deadline, 'source_deadline');
        if ($this->materialized !== null) {
            $this->artifacts->assertInspectionCurrent($this->materialized, $this->issuer, $this, $this->values, $this->budget);
        }
    }

    public function retire(): void
    {
        if ($this->retired) {
            return;
        }
        $this->retired = true;
        if (isset($this->factsClosure)) {
            $this->facts->retire($this->factsClosure);
        }
        if (isset($this->reservation)) {
            $this->reservations->retireMemberCapture($this->reservation);
        }
    }

    public function __serialize(): never
    {
        throw new MemberGrantException('private_inspection');
    }

    public function __unserialize(array $data): void
    {
        throw new MemberGrantException('private_inspection');
    }

    public function __clone(): never
    {
        throw new MemberGrantException('private_inspection');
    }

    public function __debugInfo(): array
    {
        return ['member_original_inspection' => true];
    }
}
