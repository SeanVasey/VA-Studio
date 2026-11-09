<?php

namespace App\Domain\Media\Lifecycle;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Lifecycle counts for staff (FP-034, tier 1) and the dry-run list of unattached completed assets (tier 3). Metadata only:
 * no file is opened, nothing is written, and no state here authorizes a deletion. Callers enforce staff authority.
 *
 * Every source asset (an upload, no parent) and its derived assets form one family, counted in exactly one state:
 * in_progress (its latest processing run is queued or processing), failed_processing (its latest run failed and the source
 * was never processed), attached (a structural check of {@see MediaReferenceProof} found a reference), unattached (the full
 * proof found none, with complete coverage; only the bounded proof set can reach it) or unknown (anything else, including
 * every family beyond {@see self::MAX_FAMILIES}). Upload sessions that have not produced an asset are counted separately.
 */
final class MediaLifecycleInventory
{
    public const MAX_FAMILIES = 5000;

    public const STATES = ['in_progress', 'failed_processing', 'attached', 'unattached', 'unknown'];

    public function __construct(private readonly MediaReferenceProof $proof) {}

    public function summary(int $olderThanDays, CarbonImmutable $now): array
    {
        $roots = DB::table('media_assets')->whereNull('parent_asset_id')->orderBy('id')->limit(self::MAX_FAMILIES + 1)
            ->get(['id', 'status', 'created_at']);
        $beyond = $roots->count() > self::MAX_FAMILIES;
        $roots = $roots->take(self::MAX_FAMILIES);
        $latest = $this->latestRuns($roots->pluck('id')->all());
        $states = array_fill_keys(self::STATES, ['families' => 0, 'recorded_bytes' => 0]);
        $classified = [];
        $open = [];
        foreach ($roots->chunk(500) as $chunk) {
            $structural = $this->proof->structural($chunk->pluck('id')->map(fn ($id): int => (int) $id)->all());
            foreach ($chunk as $root) {
                $id = (int) $root->id;
                $family = $structural[$id];
                $run = $latest[$id] ?? null;
                $state = match (true) {
                    in_array($run, ['queued', 'processing'], true) => 'in_progress',
                    $run === 'failed' && $root->status !== 'processed' => 'failed_processing',
                    array_sum($family['checks']) > 0 => 'attached',
                    default => 'unknown',
                };
                $classified[$id] = [$state, $family['recorded_bytes']];
                if ($state === 'unknown' && $root->status === 'processed') {
                    $open[] = $id;
                }
            }
        }
        $candidates = $this->prove($open, $olderThanDays, $now, MediaReferenceProof::MAX_PROOF_FAMILIES);
        foreach ($candidates['families'] as $family) {
            // The full proof can only add references (content) or prove absence; unknown stays unknown.
            $classified[$family['id']][0] = $family['state'];
        }
        foreach ($classified as [$state, $bytes]) {
            $states[$state]['families']++;
            $states[$state]['recorded_bytes'] = $bytes === null || $states[$state]['recorded_bytes'] === null
                || $bytes > PHP_INT_MAX - $states[$state]['recorded_bytes'] ? null : $states[$state]['recorded_bytes'] + $bytes;
        }
        if ($beyond) {
            $states['unknown']['families_beyond_bound'] = true;
        }

        return ['states' => $states, 'sessions' => $this->sessions($now), 'candidates' => $candidates,
            'families_bounded' => $beyond, 'older_than_days' => $olderThanDays,
            'observation' => 'Recorded metadata only: not physical disk usage and not a deletion decision. Unattached is reported only for the bounded proof set.'];
    }

    /**
     * Completed families (processed source) created at least $olderThanDays ago with no structural reference, proven in
     * batches; at most $limit families are proven.
     */
    public function candidates(int $olderThanDays, CarbonImmutable $now, int $limit): array
    {
        $cutoff = $now->subDays($olderThanDays);
        $open = [];
        $afterId = 0;
        do {
            $roots = DB::table('media_assets')->whereNull('parent_asset_id')->where('status', 'processed')
                ->where('created_at', '<=', $cutoff)->where('id', '>', $afterId)->orderBy('id')->limit(500)->pluck('id')
                ->map(fn ($id): int => (int) $id)->all();
            if ($roots === []) {
                break;
            }
            $afterId = end($roots);
            $latest = $this->latestRuns($roots);
            foreach ($this->proof->structural($roots) as $id => $family) {
                if (! in_array($latest[$id] ?? null, ['queued', 'processing'], true) && array_sum($family['checks']) === 0) {
                    $open[] = $id;
                }
            }
        } while (count($open) < $limit);

        return $this->prove($open, $olderThanDays, $now, $limit);
    }

    private function prove(array $open, int $olderThanDays, CarbonImmutable $now, int $limit): array
    {
        $cutoff = $now->subDays($olderThanDays);
        $eligible = $open === [] ? [] : DB::table('media_assets')->whereIn('id', array_slice($open, 0, max($limit, 0) * 4 + 50))
            ->where('created_at', '<=', $cutoff)->orderBy('id')->limit(max($limit, 0))->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $families = [];
        $blockers = [];
        $coverage = 'complete';
        foreach (array_chunk($eligible, MediaReferenceProof::MAX_PROOF_FAMILIES) as $batch) {
            $result = $this->proof->prove($batch);
            $blockers = array_values(array_unique([...$blockers, ...$result['blockers']]));
            $coverage = $result['coverage'] === 'complete' && $coverage === 'complete' ? 'complete' : 'incomplete';
            foreach ($result['families'] as $id => $family) {
                $roles = DB::table('media_assets')->whereIn('id', $family['members'])->orderBy('id')->pluck('role')->all();
                $families[] = ['id' => $id, 'state' => $family['state'], 'track_id' => $family['track_id'], 'members' => $family['members'],
                    'roles' => $roles, 'recorded_bytes' => $family['recorded_bytes'],
                    'zero_checks' => array_keys(array_filter($family['checks'], fn (int $count): bool => $count === 0)),
                    'referencing_checks' => array_filter($family['checks'], fn (int $count): bool => $count > 0)];
            }
        }
        if ($eligible === []) {
            $blockers = $this->proof->blockers();
            $coverage = $blockers === [] ? 'complete' : 'incomplete';
        }
        sort($blockers);

        return ['older_than_days' => $olderThanDays, 'coverage' => $coverage, 'blockers' => $blockers, 'families' => $families,
            'proven_bound' => count($open) > count($eligible) || count($eligible) >= $limit];
    }

    /** @return array<int, string> source id => status of its latest run */
    private function latestRuns(array $roots): array
    {
        $latest = [];
        foreach (array_chunk(array_map('intval', $roots), 500) as $chunk) {
            foreach (DB::table('media_processing_runs')->whereIn('source_asset_id', $chunk)->orderBy('id')->get(['source_asset_id', 'status']) as $run) {
                $latest[(int) $run->source_asset_id] = $run->status;
            }
        }

        return $latest;
    }

    private function sessions(CarbonImmutable $now): array
    {
        $result = [];
        foreach (['media' => 'media_upload_sessions', 'kit' => 'sound_kit_upload_sessions'] as $kind => $table) {
            $states = ['active' => DB::table($table)->where('status', 'uploading')->where('expires_at', '>', $now),
                'expired' => DB::table($table)->where('status', 'uploading')->where('expires_at', '<=', $now),
                'cleanup_pending' => DB::table($table)->whereIn('status', ['cancelled', 'completed'])->whereNull('cleaned_at')];
            foreach ($states as $state => $query) {
                $row = $query->selectRaw('COUNT(*) AS sessions, COALESCE(SUM(received_bytes), 0) AS received_bytes')->first();
                $result[$kind][$state] = ['sessions' => (int) $row->sessions, 'received_bytes' => (int) $row->received_bytes];
            }
        }

        return $result;
    }
}
