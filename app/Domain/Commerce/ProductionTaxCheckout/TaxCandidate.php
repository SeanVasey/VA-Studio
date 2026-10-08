<?php

namespace App\Domain\Commerce\ProductionTaxCheckout;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\Evidence;
use App\Domain\Commerce\ProductionPolicy\CapabilityHistory;
use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Commerce\ProductionPreparation\PacketEvidence;
use App\Support\CanonicalJson;
use Illuminate\Validation\ValidationException;

/** Current approved machine candidate whose tax strategy is provider-calculated. Mirrors V1 CurrentPolicy. */
final class TaxCandidate
{
    public static function load(CurrentRows $reader, int $candidateId): array
    {
        try {
            $row = $reader->one(CapabilityHistory::CANDIDATES, $candidateId);
            CheckoutException::require($row !== []);
            $history = CapabilityHistory::load($reader, $row['production_track_policy_draft_id']);
            $candidate = CapabilityHistory::find($history['candidates'], $candidateId);
            $source = CapabilityHistory::eligible($history, $candidate);
        } catch (ValidationException) {
            throw new CheckoutException('unsupported');
        }
        $approvals = array_values(array_filter($history['approvals'], fn (array $r): bool => $r['production_track_capability_candidate_id'] === $candidateId));
        CheckoutException::require(count($approvals) === 1);
        $machine = $history['bodies'][$candidateId]['machine'];
        $context = TaxExecutionContext::current($machine);

        return ['raw' => CapabilityHistory::raw($history), 'machine' => $machine, 'context' => $context,
            'binding' => ['source' => $source, 'candidate_id' => $candidateId, 'candidate_public_id' => $candidate['public_id'],
                'candidate_hash' => $candidate['payload_hash'], 'approval_id' => $approvals[0]['id'],
                'approval_hash' => $approvals[0]['approval_hash'], 'machine_hash' => CanonicalJson::hash($machine)]];
    }

    public static function proveCurrent(CurrentRows $reader, array $expected): void
    {
        try {
            Evidence::same($expected['raw'], PacketEvidence::historyRaw($reader, $expected['raw']));
        } catch (ValidationException) {
            throw new CheckoutException;
        }
        Evidence::same($expected['context']->binding(), TaxExecutionContext::current($expected['machine'])->binding());
    }
}
