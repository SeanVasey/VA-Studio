<?php

namespace App\Domain\Grants\Member;

use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Memberships\Production\MembershipReservations;
use App\Models\User;
use WeakMap;

/** Unmounted preparation only. A returned candidate is never an activation or grant. */
final class MemberOriginals
{
    private MemberOriginalSourceCapture $sources;

    private MemberOriginalArtifacts $artifacts;

    private static ?WeakMap $entries = null;

    public function __construct(SealedMemberGrantFacts $facts, MembershipReservations $reservations,
        MemberOriginalRendererProcess $renderer, MemberOriginalFiles $files)
    {
        [$this->sources, $this->artifacts] = MemberOriginalSourceCapture::assembly($facts, $reservations, $renderer, $files);
    }

    public function artifactAuthority(): MemberOriginalArtifacts
    {
        return $this->artifacts;
    }

    public function prepare(string $definitionId, string $redemptionId, ProductionCustomerPrincipal $principal, User $actor): MemberOriginalCandidate
    {
        // The original budget is born here, before any container, source or I/O callback.
        $budget = MemberOriginalBudget::start();
        self::$entries ??= new WeakMap;
        self::$entries[$budget] = [$this->sources, $definitionId, $redemptionId, $principal, $actor, 'capture'];
        try {
            $source = $this->sources->capture($definitionId, $redemptionId, $principal, $actor, $budget);
            $materialized = $this->artifacts->prepare($source, MemberOriginalIoFrame::captureIdle());
            self::$entries[$budget] = [$this->sources, $definitionId, $redemptionId, $principal, $actor, 'complete'];
            $this->sources->complete($materialized, $definitionId, $redemptionId, $principal, $actor, $budget);
            $this->artifacts->verifyCurrentBytes($materialized, MemberOriginalIoFrame::captureIdle());

            return $this->artifacts->candidate($materialized);
        } finally {
            unset(self::$entries[$budget]);
            $this->sources->retireWorkflow($budget);
            $this->artifacts->retireWorkflow($budget);
        }
    }

    /** A shaped/restored/new caller budget never supplies owned entry authority. */
    public static function consumeEntry(MemberOriginalSourceCapture $issuer, string $definitionId, string $redemptionId,
        ProductionCustomerPrincipal $principal, User $actor, MemberOriginalBudget $budget, string $phase): void
    {
        $entry = self::$entries[$budget] ?? null;
        if (self::$entries !== null) {
            unset(self::$entries[$budget]);
        }
        MemberGrantException::require($entry === [$issuer, $definitionId, $redemptionId, $principal, $actor, $phase], 'owned_workflow_required');
        $budget->assertCurrent();
    }
}
