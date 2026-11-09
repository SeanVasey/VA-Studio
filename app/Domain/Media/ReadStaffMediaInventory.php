<?php

namespace App\Domain\Media;

use App\Domain\Media\Lifecycle\MediaLifecycleInventory;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/** A bounded metadata observation, never a deletion or storage-integrity decision. */
final class ReadStaffMediaInventory
{
    public const MAX_ASSETS = 100;

    public const REFERENCE_WINDOW = 200;

    private const REFERENCES = [
        'media_assets' => ['parent_asset_id'],
        'media_processing_runs' => ['source_asset_id'],
        'stems_recordings' => ['stems_asset_id', 'master_asset_id', 'preview_asset_id', 'recording_source_id'],
        'media_upload_sessions' => ['asset_id'],
        'pending_entitlements' => ['media_asset_id'],
    ];

    /**
     * FP-034 lifecycle counts per state, upload sessions and the bounded dry-run proof of unattached completed families
     * ({@see MediaLifecycleInventory}). Same staff and MFA fence as {@see handle()}; it never deletes or decides deletion.
     */
    public function lifecycle(User $actor, int $olderThanDays = 30): array
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Media inventory requires a standalone transaction.');
        }

        return DB::transaction(function () use ($actor, $olderThanDays): array {
            $this->fence($actor);
            if ($olderThanDays < 1 || $olderThanDays > 3650) {
                throw ValidationException::withMessages(['inventory' => 'Use an age from 1 to 3650 days.']);
            }

            return app(MediaLifecycleInventory::class)->summary($olderThanDays, CarbonImmutable::now());
        });
    }

    private function fence(User $actor): void
    {
        // Reuse the current persisted staff fence; no media query precedes it.
        $current = app(MediaWriterActor::class)->authorize($actor);
        if (! AdminMultiFactor::satisfiedBy($current, lockForUpdate: true)) {
            throw new AuthorizationException('Admin multi-factor authentication is required.');
        }
    }

    public function handle(User $actor, int $afterId = 0, int $limit = 50): array
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Media inventory requires a standalone transaction.');
        }

        return DB::transaction(function () use ($actor, $afterId, $limit): array {
            $this->fence($actor);
            if ($afterId < 0 || $limit < 1 || $limit > self::MAX_ASSETS) {
                throw ValidationException::withMessages(['inventory' => 'Use a nonnegative asset cursor and a page size from 1 to 100.']);
            }

            $page = DB::table('media_assets')->where('id', '>', $afterId)->orderBy('id')->limit($limit + 1)
                ->get(['id', 'role', 'status', 'size_bytes']);
            $hasMore = $page->count() > $limit;
            $page = $page->take($limit);
            $known = array_fill_keys($page->pluck('id')->all(), []);
            $windows = [];
            foreach (self::REFERENCES as $table => $columns) {
                // A primary-key prefix, not WHERE asset_id IN (...) over an unbounded history.
                // This deliberately cannot establish absence, even when this prefix is exhausted.
                $rows = DB::table($table)->orderBy('id')->limit(self::REFERENCE_WINDOW + 1)->get(['id', ...$columns]);
                $windows[$table] = ['rows_observed' => min($rows->count(), self::REFERENCE_WINDOW),
                    'has_more' => $rows->count() > self::REFERENCE_WINDOW];
                foreach ($rows->take(self::REFERENCE_WINDOW) as $row) {
                    foreach ($columns as $column) {
                        $id = $row->{$column};
                        if ($id !== null && array_key_exists($id, $known)) {
                            $known[$id][$table.'.'.$column] = true;
                        }
                    }
                }
            }

            $assets = [];
            $groups = [];
            foreach ($page as $asset) {
                $bytes = filter_var($asset->size_bytes, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                $bytes = $bytes === false ? null : $bytes;
                $references = array_keys($known[$asset->id]);
                sort($references);
                $assets[] = ['id' => (int) $asset->id, 'role' => $asset->role, 'status' => $asset->status,
                    'recorded_bytes' => $bytes, 'reference_state' => $references === [] ? 'unknown' : 'known_reference',
                    'known_reference_kinds' => $references, 'reference_coverage' => 'incomplete'];
                $key = json_encode([$asset->role, $asset->status], JSON_THROW_ON_ERROR);
                $groups[$key] ??= ['role' => $asset->role, 'status' => $asset->status, 'asset_count' => 0, 'recorded_bytes' => 0];
                $group = &$groups[$key];
                $group['asset_count']++;
                $group['recorded_bytes'] = $bytes === null || $group['recorded_bytes'] === null
                    || $bytes > PHP_INT_MAX - $group['recorded_bytes'] ? null : $group['recorded_bytes'] + $bytes;
                unset($group);
            }

            return ['scope' => 'asset_page', 'assets' => $assets, 'bytes_by_role_status' => array_values($groups),
                'has_more' => $hasMore, 'next_after_id' => $hasMore ? (int) $page->last()->id : null,
                'reference_windows' => $windows, 'reference_coverage' => 'incomplete',
                'unknown_reasons' => ['bounded_relational_windows', 'retained_json_and_encrypted_references_not_inspected'],
                'observation' => 'Metadata only; not physical disk usage, authenticated entitlement proof, or a deletion decision.'];
        });
    }
}
