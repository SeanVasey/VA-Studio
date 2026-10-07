<?php

namespace App\Domain\SupportAttachments;

/** Exact precomputed source/policy/record closure for one original committed read and its already-built projection. */
final class AttachmentCommittedProjection
{
    private readonly AttachmentCommittedRows $reads;

    private readonly string $policyHash;

    private readonly array $binding;

    private readonly int $deadline;

    private readonly string $filesystemConfiguration;

    public function __construct(private readonly AttachmentRegistry $registry, private readonly AttachmentCommittedReadReceipt $source,
        AttachmentRows $rows, private readonly AttachmentPolicy $policy, AttachmentSourceProof $proof,
        private readonly array $retained, int $decisionAt, private readonly ?array $range = null)
    {
        $this->reads = new AttachmentCommittedRows($rows);
        $this->binding = $proof->token->binding();
        $this->policyHash = AttachmentRegistry::hash($policy->commitment());
        $deadline = PHP_INT_MAX;
        $at = $decisionAt;
        $this->filesystemConfiguration = AttachmentRemoval::configuration();
        foreach ($retained as $row) {
            if (! in_array($row['state'], ['deleted', 'expired'], true) && (int) $row['expires_at'] > $at) {
                $deadline = min($deadline, (int) $row['expires_at']);
            }
        }
        $this->deadline = $deadline;
    }

    public function proveClosed(): void
    {
        $this->reads->assertIdle();
        // Source-owned provider/Gate/MFA callbacks precede its final raw source/actor/config proof.
        $this->source->proveClosed();
        // Registered attachment policy assertions are callback-free by their concrete interface contract.
        AttachmentException::require($this->registry->policy($this->binding, $this->policyHash) === $this->policy);
        (new AttachmentSchema)->assertOwned();
        foreach ($this->retained as $row) {
            AttachmentException::require($this->reads->one('support_attachments', 'id = ?', [$row['id']]) === $row, 409, 'reload');
        }
        if ($this->range !== null) {
            [$kind, $sourceId, $expected] = $this->range;
            AttachmentException::require($this->reads->rows('support_attachments', 'source_kind = ? AND source_id = ?', [$kind, $sourceId], 11) === $expected, 409, 'reload');
        }
        AttachmentException::require($this->registry->policy($this->binding, $this->policyHash) === $this->policy && $this->filesystemConfiguration === AttachmentRemoval::configuration() && now()->getTimestamp() < $this->deadline);
        $this->reads->assertIdle();
    }

    public function __serialize(): array
    {
        throw new \LogicException('Committed private projection cannot be serialized.');
    }

    public function __debugInfo(): array
    {
        return ['purpose' => 'committed_attachment_projection'];
    }
}
