<?php

namespace App\Domain\Grants\Member;

use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Memberships\Production\MemberGrantIntent;
use App\Domain\Memberships\Production\MembershipRows;
use App\Models\User;
use App\Support\CanonicalJson;
use WeakMap;

/** Exact source-owned composer. Adapter observations and public manifest values never issue authority. */
final class MemberOriginalArtifacts implements MemberOriginalArtifactAuthority
{
    private WeakMap $materializations;

    private WeakMap $ready;

    public function __construct(private readonly MemberOriginalSourceCapture $sources, private readonly SealedMemberGrantFacts $facts,
        private readonly MemberOriginalRendererProcess $renderer, private readonly MemberOriginalFiles $files)
    {
        $this->materializations = new WeakMap;
        $this->ready = new WeakMap;
    }

    public function prepare(MemberOriginalSource $source, MemberOriginalIoFrame $idle): MemberOriginalMaterialization
    {
        $claim = $this->sources->claimForMaterialization($source, $this, $idle);
        $values = $claim->values();
        $document = $values->document();
        $budget = $claim->budget();
        $profile = $document['profile']['document']['render_profile'];
        MemberGrantRenderProfile::verifyRuntime($profile, $idle);
        $this->sources->proveClaimCurrent($claim, $this, $idle);
        $rendered = $this->renderer->render($values->renderInput(), $profile, $idle, $budget);
        $this->sources->proveClaimCurrent($claim, $this, $idle);
        $observation = $this->files->storeOrVerify($values, $rendered, $idle, $budget);
        // Start of the first actual observation, never token-return or a later verification time.
        $budget->shortenTo($observation->observationStartedNs + 60_000_000_000);
        $this->sources->proveClaimCurrent($claim, $this, $idle);
        MemberGrantException::require($observation->renderSourceHash === $values->hash()
            && $observation->profileHash === CanonicalJson::hash($profile)
            && $observation->storagePolicyHash === CanonicalJson::hash($document['definition']['document']['storage_policy'])
            && $observation->sha256 === $rendered->sha256 && $observation->sizeBytes === $rendered->sizeBytes
            && $observation->pageCount === $rendered->pageCount, 'changed_artifact');
        $manifest = self::manifest($values, $observation);
        $materialized = MemberOriginalMaterialization::value();
        $this->materializations[$materialized] = ['claim' => $claim, 'values' => $values, 'budget' => $budget,
            'observation' => $observation, 'manifest' => $manifest, 'inspection' => null,
            'candidate' => new MemberOriginalCandidate($document['origin_id'], $document['redemption']['id'],
                $document['definition']['id'], CanonicalJson::hash($document['definition']['document']),
                CanonicalJson::hash($document['profile']['document']), $values->hash(), $manifest->manifestHash, $manifest)];

        return $materialized;
    }

    public function verifyCurrentBytes(MemberOriginalMaterialization $materialized, MemberOriginalIoFrame $idle): void
    {
        $entry = $this->entry($materialized);
        $this->sources->proveClaimCurrent($entry['claim'], $this, $idle);
        $this->files->verify($entry['values'], $entry['observation'], $idle, $entry['budget']);
        $this->sources->proveClaimCurrent($entry['claim'], $this, $idle);
        $this->entry($materialized);
    }

    public function admitReady(MemberOriginalMaterialization $materialized, MemberGrantIntent $intent, MemberGrantFactsProof $facts,
        ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): MemberOriginalArtifactReceipt
    {
        $entry = $this->entry($materialized);
        $inspection = $this->sources->readyInspection($this, $intent, $facts, $principal, $actor, $rows);
        MemberGrantException::require($inspection->values()->hash() === $entry['values']->hash()
            && $inspection->values()->document() === $entry['values']->document(), 'changed_render_source');
        $entry['inspection'] = $inspection;
        $this->materializations[$materialized] = $entry;
        $this->assertInspectionCurrent($materialized, $this->sources, $inspection, $inspection->values(), $entry['budget']);
        $receipt = MemberOriginalReadyToken::value($entry['manifest']);
        $this->ready[$receipt] = [$materialized, $inspection, $intent, $facts, $principal, $actor, $rows, $rows->identity()];

        return $receipt;
    }

    public function proveReadyCurrent(MemberOriginalArtifactReceipt $receipt, MemberGrantIntent $intent, MemberGrantFactsProof $facts,
        ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): void
    {
        $entry = $this->ready[$receipt] ?? null;
        MemberGrantException::require($receipt instanceof MemberOriginalReadyToken && is_array($entry)
            && $entry[6] === $rows
            && array_slice($entry, 2) === [$intent, $facts, $principal, $actor, $rows, $rows->identity()], 'foreign_readiness');
        $inspection = $this->sources->readyInspection($this, $intent, $facts, $principal, $actor, $rows);
        MemberGrantException::require($inspection === $entry[1], 'foreign_readiness');
        $materialized = $this->entry($entry[0]);
        MemberGrantException::require($receipt->binding() === $materialized['manifest'], 'changed_manifest');
        $this->assertInspectionCurrent($entry[0], $this->sources, $inspection, $inspection->values(), $materialized['budget']);
    }

    public function assertOwned(MemberOriginalMaterialization $materialized, MemberOriginalSourceCapture $sources,
        MemberOriginalBudget $budget): void
    {
        $entry = $this->entry($materialized);
        MemberGrantException::require($sources === $this->sources && $budget === $entry['budget'], 'foreign_materialization');
    }

    /** Terminal registry/value closure only. Never refreshes a producer or reads a file. */
    public function assertInspectionCurrent(MemberOriginalMaterialization $materialized, MemberOriginalSourceCapture $sources,
        MemberOriginalInspection|MemberActivationInspection $inspection, MemberOriginalSourceValues $values, MemberOriginalBudget $budget): void
    {
        $entry = $this->entry($materialized);
        MemberGrantException::require($sources === $this->sources && $entry['inspection'] === $inspection
            && $budget === $entry['budget'] && $values->hash() === $entry['values']->hash()
            && $values->document() === $entry['values']->document(), 'foreign_readiness');
    }

    public function candidate(MemberOriginalMaterialization $materialized): MemberOriginalCandidate
    {
        $entry = $this->entry($materialized);

        return $entry['candidate'];
    }

    /** Retiring a frame can only remove authority; detached values retain no Rows or producers. */
    public function retireInspection(MemberOriginalInspection|MemberActivationInspection $inspection): void
    {
        foreach ($this->ready as $receipt => $entry) {
            if ($entry[1] === $inspection) {
                unset($this->ready[$receipt]);
            }
        }
        foreach ($this->materializations as $materialized => $entry) {
            if ($entry['inspection'] === $inspection) {
                $entry['inspection'] = null;
                $this->materializations[$materialized] = $entry;
            }
        }
    }

    private function entry(MemberOriginalMaterialization $materialized): array
    {
        $entry = $this->materializations[$materialized] ?? null;
        MemberGrantException::require(is_array($entry), 'foreign_materialization');
        $this->sources->assertClaim($entry['claim'], $this);
        $entry['budget']->assertCurrent();
        MemberGrantException::require(\hrtime(true) < $entry['observation']->observationStartedNs + 60_000_000_000,
            'physical_observation_deadline');
        $manifest = self::manifest($entry['values'], $entry['observation']);
        MemberGrantException::require($manifest == $entry['manifest'], 'changed_manifest');

        return $entry;
    }

    private static function manifest(MemberOriginalSourceValues $values, MemberOriginalFileObservation $observation): MemberOriginalArtifactManifest
    {
        $source = $values->document();
        $originals = [['role' => 'license_pdf', 'sha256' => $observation->sha256, 'bytes' => $observation->sizeBytes,
            'storage_policy_hash' => CanonicalJson::hash($source['definition']['document']['storage_policy'])]];
        $hash = CanonicalJson::hash(['schema_version' => 'member-original-artifact-manifest-v1',
            'family' => MemberGrantIntent::FAMILY, 'purpose' => MemberGrantIntent::PURPOSE,
            'origin_id' => $source['origin_id'], 'originals' => $originals]);

        return new MemberOriginalArtifactManifest($source['origin_id'], $hash, $originals);
    }

    public function retireWorkflow(MemberOriginalBudget $budget): void
    {
        foreach ($this->ready as $receipt => $entry) {
            $materialized = $this->materializations[$entry[0]] ?? null;
            if (is_array($materialized) && $materialized['budget'] === $budget) {
                unset($this->ready[$receipt]);
            }
        }
        foreach ($this->materializations as $materialized => $entry) {
            if ($entry['budget'] === $budget) {
                unset($this->materializations[$materialized]);
            }
        }
    }

    public function __clone(): never
    {
        throw new MemberGrantException('private_artifact_issuer');
    }

    public function __serialize(): never
    {
        throw new MemberGrantException('private_artifact_issuer');
    }

    public function __unserialize(array $data): void
    {
        throw new MemberGrantException('private_artifact_issuer');
    }
}
