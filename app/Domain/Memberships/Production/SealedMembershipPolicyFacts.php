<?php

namespace App\Domain\Memberships\Production;

use Carbon\CarbonImmutable;
use WeakMap;

/** Real retained policy producer. Registration and complete commerce admission are separate. */
final class SealedMembershipPolicyFacts implements MembershipPolicyFactsAuthority
{
    private WeakMap $issued;

    public function __construct()
    {
        $this->issued = new WeakMap;
    }

    public function __clone(): void
    {
        throw new MembershipException('clone_refused');
    }

    public function lock(string $planVersion, MembershipRows $rows): MembershipPolicyFactsProof
    {
        MembershipValues::id($planVersion);
        $deadlineNs = hrtime(true) + 60_000_000_000;
        $deadline = CarbonImmutable::now('UTC')->addSeconds(60);
        $policy = new MembershipPolicy;
        $configuration = $policy->facts();
        $row = $rows->one(MembershipRecords::TABLE, 'id = ?', [$planVersion]);
        MembershipException::require($row !== [], 'policy_facts_absent');
        $document = MembershipPolicyVersions::document($row);
        MembershipException::require($document->hash() === $configuration['approved_policy_hash']
            && $document->facts['provenance'] === $configuration['provenance'], 'policy_not_approved');
        $proof = MembershipPolicyFactsToken::value($document->binding($planVersion));
        $this->issued[$proof] = ['rows' => $rows, 'pdo' => $rows->identity(), 'seal' => $row['seal'], 'policy' => $policy,
            'configuration' => $configuration, 'deadline_ns' => $deadlineNs, 'deadline' => $deadline];
        $this->proveCurrent($proof, $rows);

        return $proof;
    }

    public function proveCurrent(MembershipPolicyFactsProof $proof, MembershipRows $rows): void
    {
        MembershipException::require($proof instanceof MembershipPolicyFactsToken && isset($this->issued[$proof]), 'foreign_policy_proof');
        $issued = $this->issued[$proof];
        MembershipException::require($issued['rows'] === $rows && $issued['pdo'] === $rows->identity(), 'foreign_policy_proof');
        $issued['policy']->proveConfiguration($issued['configuration']);
        $this->deadline($issued);
        $row = $rows->one(MembershipRecords::TABLE, 'id = ?', [$proof->binding()->planVersionId]);
        MembershipException::require($row !== [] && $row['seal'] === $issued['seal'], 'changed_policy_facts');
        $document = MembershipPolicyVersions::document($row);
        MembershipException::require($document->hash() === $issued['configuration']['approved_policy_hash']
            && $document->facts['provenance'] === $issued['configuration']['provenance'], 'policy_not_approved');
        $issued['policy']->proveConfiguration($issued['configuration']);
        $rows->assertCurrent();
        $this->deadline($issued);
    }

    private function deadline(array $issued): void
    {
        MembershipException::require(hrtime(true) < $issued['deadline_ns'] && CarbonImmutable::now('UTC')->lessThan($issued['deadline']), 'expired_policy_proof');
    }
}
