<?php

namespace App\Domain\Grants\Member;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Customers\ProductionIdentity\IdentityCommittedFrame;
use App\Domain\Memberships\Production\MemberGrantBinding;
use App\Domain\Memberships\Production\MemberGrantIntent;
use App\Domain\Memberships\Production\MemberGrantReceipt;
use App\Domain\Memberships\Production\MembershipActivationCapture;
use App\Domain\Memberships\Production\MembershipActivationTransition;
use App\Domain\Memberships\Production\MembershipPeriodRecords;
use App\Domain\Memberships\Production\MembershipPolicyBinding;
use App\Domain\Memberships\Production\MembershipRecords;
use App\Domain\Memberships\Production\MembershipReservationRecords;
use App\Domain\Memberships\Production\MembershipReservations;
use App\Domain\Memberships\Production\MembershipRows;
use App\Domain\Memberships\Production\MembershipSchema;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/** Exact private activation phases; only the separately registered effect permit selects a write. */
final class MemberActivationInspection
{
    private MembershipActivationCapture $reservation;

    private MemberGrantFactsProof $factsProof;

    private MemberGrantFactsClosure $factsClosure;

    private MemberGrantIntent $intent;

    private MemberOriginalSourceValues $values;

    private bool $retired = false;

    private bool $committed = false;

    private ?MemberOriginalMaterialization $materialized = null;

    private ?MemberLicenseAuthorizationProof $authorizationProof = null;

    private ?MemberLicenseAuthorizationClosure $authorizationClosure = null;

    private ?MemberLicenseAuthorizationClaim $claim = null;

    private MemberLicenseReviewDisplay $display;

    private ?array $authorization = null;

    private array $reservationValues;

    private ?array $effectPlan = null;

    private ?MembershipActivationTransition $transition = null;

    private ?MemberOriginalArtifactReceipt $artifactReceipt = null;

    private ?MemberGrantReceipt $grantReceipt = null;

    private ?MemberGrantBinding $grantBinding = null;

    private ?SealedMemberGrants $grantIssuer = null;

    private bool $grantMinting = false;

    private bool $inserted = false;

    private array $insertStatements = [];

    private ?MemberGrantActivationResult $result = null;

    public function __construct(private readonly MemberOriginalSourceCapture $issuer,
        private readonly MembershipRows $rows, private readonly MembershipReservations $reservations,
        private readonly SealedMemberGrantFacts $facts, private readonly MemberOriginalArtifacts $artifacts, private readonly MemberLicenseAuthorizations $authorizations,
        private readonly MemberActivationConfiguration $configuration, private readonly MemberOriginalFinishBoundary $finish,
        private readonly MemberOriginalBudget $budget, private readonly int $deadline, string $definitionId,
        string $redemptionId, private readonly ProductionCustomerPrincipal $principal, private readonly User $actor,
        private readonly MemberLicenseReviewEntryPermit|MemberLicenseConfirmationEntryPermit|MemberActivationEntryPermit|MemberActivationCompletionPermit $entry,
        ?MemberLicenseConfirmationInput $confirmation)
    {
        $permit = MemberOriginalSourceCapture::consumeActivationInspection($this, $issuer, $rows, $reservations, $facts, $artifacts,
            $configuration, $finish, $budget, $deadline, $definitionId, $redemptionId, $principal, $actor, $authorizations, $entry, $confirmation);
        try {
            $this->reservation = $reservations->beginActivationCapture($permit, $redemptionId, $principal, $actor, $rows);
            $budget->shortenTo($reservations->activationHonorDeadline($this->reservation));
            $budget->assertCurrent();
            $this->intent = $reservations->activationIntent($this->reservation, $rows);
            [$this->factsProof, $snapshot] = $facts->capture($definitionId, $principal, $actor, $rows);
            $this->factsClosure = $facts->closure($this->factsProof, $principal, $actor, $rows);
            $parts = $reservations->activationSourceValues($this->reservation, $rows);
            $this->values = MemberOriginalSourceValues::from(['schema_version' => 'member-original-source-v1',
                'family' => MemberGrantIntent::FAMILY, 'purpose' => MemberGrantIntent::PURPOSE,
                'provenance' => $snapshot->factsBinding->provenance, 'origin_id' => $redemptionId] + $parts + [
                    'definition' => ['id' => $snapshot->factsBinding->definitionId, 'seal' => $snapshot->definitionSeal,
                        'document' => $snapshot->definitionDocument->value()],
                    'profile' => ['id' => $snapshot->profileBinding->id, 'seal' => $snapshot->profileSeal,
                        'document' => $snapshot->profileDocument->value()]]);
            $this->reservationValues = $reservations->activationValues($this->reservation, $rows);
            $this->display = MemberLicenseReviewDisplay::from(MemberLicenseAuthorizationRecords::display($this->values, $this->reservationValues['policy']));
            if ($entry instanceof MemberLicenseConfirmationEntryPermit) {
                MemberGrantException::require($confirmation !== null && $confirmation->displayHash === $this->display->hash(), 'changed_confirmation_display');
                $this->authorization = MemberLicenseAuthorizationRecords::create($this->values, $this->reservationValues['policy'],
                    $this->reservationValues['actor'], (string) Str::uuid(), CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'));
            }
            // These detached values stay inside the registered inspection. Readiness and the
            // committing observer each require a fresh complete combined proof before authority
            // can leave this frame; repeating that proof here needlessly consumes its original cap.
        } catch (\Throwable $error) {
            $this->retire();
            throw $error;
        }
    }

    public function values(): MemberOriginalSourceValues
    {
        return $this->values;
    }

    public function display(): MemberLicenseReviewDisplay
    {
        return $this->display;
    }

    public function confirmation(): array
    {
        MemberGrantException::require($this->entry instanceof MemberLicenseConfirmationEntryPermit && $this->authorization !== null, 'foreign_confirmation');

        return $this->authorization;
    }

    public function authorize(MemberLicenseAuthorizationClaim $claim): void
    {
        MemberGrantException::require(($this->entry instanceof MemberActivationEntryPermit || $this->entry instanceof MemberActivationCompletionPermit)
            && $this->claim === null && ! $this->retired && ! $this->committed, 'foreign_authorization');
        $this->claim = $claim;
        $this->authorizationProof = $this->authorizations->lock($claim, $this->values, $this->principal, $this->actor, $this->rows);
        $this->authorizationClosure = $this->authorizations->closure($this->authorizationProof, $this->principal, $this->actor, $this->rows);
    }

    public function admitAuthorization(MemberLicenseAuthorizationClaim $claim, MemberOriginalSourceValues $source,
        ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): MembershipPolicyBinding
    {
        MemberGrantException::require(! $this->retired && ! $this->committed && $this->claim === $claim && $this->values === $source
            && $this->principal === $principal && $this->actor === $actor && $this->rows === $rows, 'foreign_authorization');
        $this->admit();

        return $this->reservationValues['policy'];
    }

    public function reader(): CurrentRows
    {
        return new CurrentRows($this->rows->identity(), $this->rows->driver());
    }

    /** Values remain inside this exact completion; they do not authorize readiness or writes. */
    public function artifactPlanSources(MemberOriginalSourceCapture $issuer): array
    {
        MemberGrantException::require($issuer === $this->issuer && $this->entry instanceof MemberActivationCompletionPermit
            && $this->authorizationProof !== null && $this->claim !== null && $this->materialized === null
            && ! $this->committed && ! $this->retired, 'foreign_artifact_plan');
        $this->admit();

        return [$this->intent, $this->factsProof, $this->values];
    }

    public function ready(MemberActivationArtifactPermit $permit, MemberOriginalMaterialization $materialized): void
    {
        MemberGrantException::require($this->entry instanceof MemberActivationCompletionPermit && $this->authorizationProof !== null
            && $this->materialized === null && ! $this->committed && ! $this->retired, 'foreign_inspection');
        $this->artifactReceipt = $this->artifacts->admitActivationPlan($permit, $this, $materialized, $this->intent,
            $this->factsProof, $this->values, $this->principal, $this->actor, $this->rows, $this->budget);
        $this->materialized = $materialized;
    }

    public function planActivation(MemberActivationEffectPermit $permit, array $authorization, SealedMemberGrants $grants): void
    {
        MemberOriginalSourceCapture::consumeInspectionEffect($permit, $this->issuer, $this, $grants, $this->rows);
        MemberGrantException::require($this->entry instanceof MemberActivationCompletionPermit && $this->materialized !== null
            && $this->artifactReceipt !== null && $this->effectPlan === null && $this->claim !== null, 'foreign_activation_plan');
        $p = $this->reservationValues['policy'];
        $actor = $this->reservationValues['actor'];
        $at = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
        $manifest = $this->artifactReceipt->binding();
        $r = MemberActivationReadiness::forSource($this->values, $authorization, $p, $manifest)->value();
        $origin = MemberActivationRecords::origin($this->values, $authorization, $p, $manifest,
            $this->reservationValues['identity_origin_id'], $actor, $at);
        $artifact = MemberActivationRecords::artifact($origin, $p, (string) Str::uuid(), $at);
        $consume = MembershipReservationRecords::consumeEvent(
            $this->reservationValues['audit'], $this->values->document()['redemption']['id'], $p, $r, $actor, $at);
        $activation = MemberActivationRecords::activation($origin, $artifact, $consume, $p, (string) Str::uuid(), $actor, $at);
        $sets = $this->reservationValues['history']->rows();
        $source = $this->values->document();
        $support = [];
        foreach (['profiles' => [MemberGrantRecords::PROFILES, $source['profile']['id']],
            'definitions' => [MemberGrantRecords::DEFINITIONS, $source['definition']['id']],
            'plans' => [MembershipRecords::TABLE, $p->planVersionId]] as $set => [$table, $id]) {
            $physical = $set === 'plans' ? (new MembershipSchema)->table($table) : (new MemberGrantSchema)->table($table);
            $query = $this->rows->identity()->prepare('SELECT * FROM '.$physical.' WHERE id = ? LIMIT 2'.($this->rows->driver() === 'mysql' ? ' FOR UPDATE' : ''));
            $query->execute([$id]);
            $found = $query->fetchAll(\PDO::FETCH_ASSOC);
            MemberGrantException::require(count($found) === 1, 'changed_retained_effect');
            $support[$set] = $found;
            $old = array_values(array_filter($sets[$set], static fn (array $row): bool => $row['id'] === $id));
            if ($old === []) {
                $sets[$set][] = $found[0];
            } else {
                MemberGrantException::require(MemberGrantRecords::strings($old[0]) === MemberGrantRecords::strings($found[0]), 'changed_retained_effect');
            }
        }
        $sets['origins'][] = $origin;
        $sets['artifacts'][] = $artifact;
        $sets['activations'][] = $activation;
        $history = MemberActivationHistory::fromRows($sets['origins'], $sets['artifacts'], $sets['activations'],
            $sets['profiles'], $sets['definitions'], $sets['plans'], count($this->reservationValues['audit']['reservations']));
        $this->effectPlan = compact('origin', 'artifact', 'consume', 'activation', 'history', 'support');
        $this->grantBinding = MemberActivationRecords::binding($origin, $artifact, $consume, $activation, $p);
        $this->result = new MemberGrantActivationResult($origin['id'], $origin['redemption_id'], $origin['definition_id'],
            $consume['id'], $activation['id'], $this->grantBinding->originalArtifactHash, $this->grantBinding->activationHash);
        $this->issuer->registerReservationEffect($permit, $this, $this->reservations, $this->reservation, $this->rows, $this->effectPlan);
        $this->transition = $this->reservations->planActivation($this->reservation, $this->rows, $permit, $this->effectPlan);
        foreach ([MemberActivationRecords::ORIGINS => $origin, MemberActivationRecords::ARTIFACTS => $artifact,
            MembershipPeriodRecords::EVENTS => $consume, MemberActivationRecords::ACTIVATIONS => $activation] as $table => $row) {
            $physical = $table === MembershipPeriodRecords::EVENTS
                ? (new MembershipSchema)->table($table) : (new MemberGrantSchema)->table($table);
            $sql = 'INSERT INTO '.$physical.' ('.implode(', ', array_keys($row)).') VALUES ('.implode(', ', array_fill(0, count($row), '?')).')';
            $this->insertStatements[] = [$this->rows->identity()->prepare($sql), array_values($row)];
        }
        $this->grantIssuer = $grants;
        $this->grantMinting = true;
        try {
            $this->grantReceipt = $grants->prepare($this->intent, $this->principal, $this->actor, $this->rows);
        } finally {
            $this->grantMinting = false;
        }
    }

    public function consumeGrantPlan(SealedMemberGrants $grants, MemberGrantIntent $intent, ProductionCustomerPrincipal $principal,
        User $actor, MembershipRows $rows): MemberGrantBinding
    {
        MemberGrantException::require($this->grantMinting && $this->grantIssuer === $grants && $this->grantBinding !== null
            && $this->intent === $intent && $this->principal === $principal && $this->actor === $actor && $this->rows === $rows
            && ! $this->inserted && ! $this->committed && ! $this->retired, 'foreign_grant_plan');
        $this->grantMinting = false;
        // This receipt stays provisional inside the exact owned plan. The full combined
        // proof follows all record/audit callbacks immediately before native insertion.
        $this->admit();
        $this->finish->assertFrame($this->rows->identity(), false);
        $this->rows->assertCurrent();
        $this->admit();

        return $this->grantBinding;
    }

    public function proveGrantPlan(SealedMemberGrants $grants, MemberGrantBinding $binding,
        MemberGrantIntent $intent, ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): void
    {
        MemberGrantException::require($grants === $this->grantIssuer && $binding === $this->grantBinding && $intent === $this->intent
            && $principal === $this->principal && $actor === $this->actor && $rows === $this->rows
            && ! $this->inserted && ! $this->committed && ! $this->retired, 'foreign_grant_plan');
        $this->proveCurrent();
    }

    public function activationResult(): MemberGrantActivationResult
    {
        MemberGrantException::require($this->result !== null, 'foreign_activation_plan');

        return $this->result;
    }

    public function insertActivation(): void
    {
        MemberGrantException::require($this->effectPlan !== null && $this->transition !== null && $this->grantReceipt !== null
            && $this->grantIssuer !== null && ! $this->inserted && ! $this->retired && ! $this->committed, 'foreign_activation_plan');
        $this->grantIssuer->proveReadyCurrent($this->grantReceipt, $this->intent, $this->principal, $this->actor, $this->rows);
        // The previous combined proof is the final callback phase. Native prepared inserts have no user hooks.
        $this->reservations->beginActivationInsert($this->reservation, $this->transition, $this->rows);
        foreach ($this->insertStatements as [$statement, $values]) {
            $statement->execute($values);
        }
        $this->reservations->proveInsertedActivation($this->reservation, $this->transition, $this->rows);
        $this->inserted = true;
        // Exact fresh graph/audit precedes this admission. The normal committing observer
        // closes every source after all remaining delegates, before the physical commit.
        $this->admit();
        $this->finish->assertFrame($this->rows->identity(), false);
        $this->rows->assertCurrent();
        $this->admit();
    }

    public function matches(MemberGrantIntent $intent, MemberGrantFactsProof $facts, ProductionCustomerPrincipal $principal,
        User $actor, MembershipRows $rows): void
    {
        MemberGrantException::require($this->entry instanceof MemberActivationCompletionPermit && ! $this->retired && ! $this->committed && $this->intent === $intent
            && $this->factsProof === $facts && $this->principal === $principal && $this->actor === $actor && $this->rows === $rows,
            'foreign_readiness');
        $this->admit();
    }

    public function proveCurrent(): void
    {
        MemberGrantException::require(! $this->retired && ! $this->committed, 'foreign_inspection');
        if ($this->authorizationProof !== null) {
            $this->authorizations->currentCallbacks($this->authorizationProof, $this->rows);
        }
        $this->facts->currentCallbacks($this->factsClosure, $this->rows);
        $this->reservations->activationCurrentCallbacks($this->reservation, $this->rows);
        $this->budget->shortenTo($this->reservations->activationHonorDeadline($this->reservation));
        // No callback-capable sibling phase may follow either raw source closure.
        $this->finish->assertFrame($this->rows->identity(), false);
        if ($this->authorizationProof !== null) {
            $this->authorizations->currentRaw($this->authorizationProof, $this->rows);
        }
        $this->facts->currentRaw($this->factsClosure, $this->rows);
        $this->reservations->activationCurrentRaw($this->reservation, $this->rows);
        $this->admit();
        $this->rows->assertCurrent();
        $this->finish->assertFrame($this->rows->identity(), false);
        $this->admit();
    }

    /** Only the distinct owned committed proof may transition this inspection. */
    public function proveCommitted(MemberActivationCommittedProof $proof, IdentityCommittedFrame $frame): void
    {
        MemberActivationCommittedProof::consume($proof, $this, $frame);
        MemberGrantException::require(! $this->retired && ! $this->committed, 'foreign_inspection');
        $this->committed = true;
        if ($this->authorizationClosure !== null) {
            $this->authorizations->committedCallbacks($this->authorizationClosure, $frame);
        }
        $this->facts->committedCallbacks($this->factsClosure, $frame);
        $this->reservations->activationCommittedCallbacks($this->reservation, $frame);
        $this->budget->shortenTo($this->reservations->activationHonorDeadline($this->reservation));
        $this->finish->assertFrame($this->rows->identity(), true);
        if ($this->authorizationClosure !== null) {
            $this->authorizations->committedRaw($this->authorizationClosure, $frame);
        }
        $this->facts->committedRaw($this->factsClosure, $frame);
        $this->reservations->activationCommittedRaw($this->reservation, $frame);
        $this->admit();
        $this->finish->assertFrame($this->rows->identity(), true);
        $frame->finish();
        $this->finish->assertCurrent();
        $this->reservations->activationPostFinish($this->reservation, $frame);
        if ($this->authorizationClosure !== null) {
            $this->authorizations->postFinish($this->authorizationClosure, $frame);
        }
        $this->admit();
    }

    private function admit(): void
    {
        $this->configuration->admit();
        $this->budget->assertCurrent();
        MemberGrantException::require(\hrtime(true) < $this->deadline, 'source_deadline');
        if ($this->materialized !== null) {
            $this->artifacts->assertActivationPlan($this->materialized, $this->issuer, $this, $this->values,
                $this->budget, $this->artifactReceipt);
        }
    }

    public function retire(): void
    {
        if ($this->retired) {
            return;
        }
        $this->retired = true;
        $this->grantIssuer?->retireInspection($this);
        if (isset($this->factsClosure)) {
            $this->facts->retire($this->factsClosure);
        }
        if (isset($this->reservation)) {
            $this->reservations->retireActivationCapture($this->reservation);
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
