<?php

namespace App\Domain\Commerce\ProductionCheckout;

use App\Domain\Commerce\ProductionPolicy\CapabilityHistory;
use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Commerce\ProductionPreparation\PacketEvidence;
use App\Support\CanonicalJson;

/** Buyer-side current approved source proof; staff preparation flags retain their historical meaning. */
final class CurrentPolicy
{
    public static function load(CurrentRows $reader, int $candidateId): array
    {
        $row = $reader->one(CapabilityHistory::CANDIDATES, $candidateId);
        CheckoutException::require($row !== []);
        $history = CapabilityHistory::load($reader, $row['production_track_policy_draft_id']);
        $candidate = CapabilityHistory::find($history['candidates'], $candidateId);
        $source = CapabilityHistory::eligible($history, $candidate);
        $approvals = array_values(array_filter($history['approvals'], fn (array $r): bool => $r['production_track_capability_candidate_id'] === $candidateId));
        CheckoutException::require(count($approvals) === 1);
        $machine = $history['bodies'][$candidateId]['machine'];
        $context = ExecutionContextV1::current($machine);

        return ['raw' => CapabilityHistory::raw($history), 'machine' => $machine, 'context' => $context,
            'binding' => ['source' => $source, 'candidate_id' => $candidateId, 'candidate_public_id' => $candidate['public_id'],
                'candidate_hash' => $candidate['payload_hash'], 'approval_id' => $approvals[0]['id'],
                'approval_hash' => $approvals[0]['approval_hash'], 'machine_hash' => CanonicalJson::hash($machine)]];
    }

    public static function proveCurrent(CurrentRows $reader, array $expected): void
    {
        Evidence::same($expected['raw'], PacketEvidence::historyRaw($reader, $expected['raw']));
        Evidence::same($expected['context']->binding(), ExecutionContextV1::current($expected['machine'])->binding());
    }

    public static function historical(CurrentRows $reader, array $binding, array $machine): array
    {
        $history = CapabilityHistory::load($reader, $binding['source']['draft_id']);
        $candidate = CapabilityHistory::find($history['candidates'], $binding['candidate_id']);
        $approval = CapabilityHistory::find($history['approvals'], $binding['approval_id']);
        CheckoutException::require($candidate['public_id'] === $binding['candidate_public_id'] && $candidate['payload_hash'] === $binding['candidate_hash']
            && $approval['approval_hash'] === $binding['approval_hash'] && $approval['production_track_capability_candidate_id'] === $candidate['id']
            && CanonicalJson::hash($machine) === $binding['machine_hash']);
        Evidence::same($history['bodies'][$candidate['id']]['machine'], $machine);
        Evidence::same($history['bodies'][$candidate['id']]['source'], $binding['source']);

        return CapabilityHistory::raw($history);
    }

    public static function proveHistorical(CurrentRows $reader, array $expected): void
    {
        Evidence::same($expected, PacketEvidence::historyRaw($reader, $expected));
    }
}
