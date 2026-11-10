<?php

namespace App\Domain\Rights;

use Illuminate\Support\Facades\DB;

/**
 * Read-only completeness of a track's collaborator splits. A split type is complete only when its shares total exactly
 * 10000 basis points; anything else, including no recorded share, is incomplete. Completeness is derived from the rows on
 * every read, so there is no stored flag to go stale.
 *
 * Metadata only: this report is advisory. It feeds no pricing, accounting, contract or payout code, and it does not block
 * publication (whether it should is Sean's open decision).
 */
final class CollaboratorSplitReport
{
    public const COMPLETE_BPS = 10000;

    /** @return array{schema_version: int, complete: bool, types: array<string, array{status: string, total_bps: int, collaborators: int}>} */
    public function forTrack(int $trackId): array
    {
        $rows = DB::table('track_collaborator_splits')->where('track_id', $trackId)
            ->selectRaw('split_type, COALESCE(SUM(share_bps), 0) AS total_bps, COUNT(*) AS collaborators')
            ->groupBy('split_type')->get()->keyBy('split_type');
        $types = [];
        foreach (SplitType::values() as $type) {
            $total = (int) ($rows[$type]->total_bps ?? 0);
            $types[$type] = ['status' => $total === self::COMPLETE_BPS ? 'complete' : 'incomplete',
                'total_bps' => $total, 'collaborators' => (int) ($rows[$type]->collaborators ?? 0)];
        }

        return ['schema_version' => 1,
            'complete' => array_filter($types, fn (array $type): bool => $type['status'] !== 'complete') === [],
            'types' => $types];
    }

    /** @return list<string> one staff-readable line per incomplete split type; empty when every type is complete */
    public function advisories(int $trackId): array
    {
        $lines = [];
        foreach ($this->forTrack($trackId)['types'] as $type => $state) {
            if ($state['status'] !== 'complete') {
                $lines[] = 'Advisory, does not block publication: '.$type.' collaborator splits are incomplete ('
                    .$state['total_bps'].' of '.self::COMPLETE_BPS.' basis points recorded).';
            }
        }

        return $lines;
    }
}
