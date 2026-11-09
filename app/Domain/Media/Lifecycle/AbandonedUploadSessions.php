<?php

namespace App\Domain\Media\Lifecycle;

use App\Domain\Catalog\Models\Track;
use App\Domain\Media\Models\MediaUploadSession;
use App\Domain\Media\PrivateUploadParts;
use App\Domain\SoundKits\Models\SoundKitDraft;
use App\Domain\SoundKits\Models\SoundKitUploadSession;
use App\Support\Audit\AuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Cleans the transport chunks of abandoned resumable uploads (FP-034, tier 2): track-media and sound-kit sessions that
 * expired while still `uploading`, or that were cancelled without their cleanup finishing. Never a completed session,
 * never a media asset, quarantine original or kit revision: only the flat chunk directory `resumable/<session uuid>` that
 * {@see PrivateUploadParts} writes on the private disk.
 *
 * The session row is retained, never deleted: upload sessions are retained evidence (the kit table refuses DELETE by
 * trigger, the media table's rollback refuses populated rows, and audit events name them). The crash-safe order is
 * "mark, then remove": one committed transaction moves an expired `uploading` row to the existing terminal `cancelled`
 * state (with a system audit event), so no append or completion can use it again; a second transaction, holding the
 * same parent and session row locks the upload paths take, removes the directory and only then sets `cleaned_at`.
 * A crash between the two leaves `cancelled` with `cleaned_at` null, which the next run (or the owner's own cancel
 * replay) finishes. Deleting files first would leave an `uploading` row whose recorded parts were gone.
 *
 * A directory is removed only when every check passes; otherwise it is left in place and counted: a lowercase UUID name
 * from the retained row, `lstat` (never followed) shows a directory of mode 0700 owned by the sweeping user, it is not
 * reached through a link, and every entry is a regular, singly linked file owned by that user with a transport name.
 */
final class AbandonedUploadSessions
{
    /** Never shorter than the upload lifetime (24 h): a session must be past expiry and idle at least this long. */
    public const MIN_AGE_SECONDS = 86400;

    public const DEFAULT_AGE_SECONDS = 172800;

    public const UUID = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D';

    public const TRANSPORT_FILE = '/\A(?:part-[0-9]+\.(?:part|pending)|complete\.assembled|assembly\.pending)\z/D';

    /** @var array{0:int,1:int} device and inode of the directory {@see inspect()} last accepted */
    private array $identity = [0, 0];

    private const KINDS = [
        'media' => [MediaUploadSession::class, 'track_id', Track::class, 'media.upload.session_abandoned'],
        'kit' => [SoundKitUploadSession::class, 'sound_kit_draft_id', SoundKitDraft::class, 'sound_kit.upload.abandoned'],
    ];

    /**
     * @return array{sessions_expired:int, sessions_cancelled_pending:int, sessions_marked:int, directories_removed:int,
     *     files_removed:int, directories_absent:int, directories_left:int, completed_cleanup_pending:int, foreign_entries:int}
     */
    public function sweep(bool $execute, int $minAgeSeconds, int $euid, ?CarbonImmutable $now = null): array
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('The upload session sweep needs its own root transactions.');
        }
        if ($minAgeSeconds < self::MIN_AGE_SECONDS) {
            throw new UploadSweepRefused('age_too_short');
        }
        if ($euid === 0) {
            throw new UploadSweepRefused('root_user');
        }
        // Every refusal happens before any row or file is touched.
        $temp = (new UploadTempRoot)->resolve($euid);
        $now ??= CarbonImmutable::now();
        $cutoff = $now->subSeconds($minAgeSeconds);
        $counts = ['sessions_expired' => 0, 'sessions_cancelled_pending' => 0, 'sessions_marked' => 0, 'directories_removed' => 0,
            'files_removed' => 0, 'directories_absent' => 0, 'directories_left' => 0, 'completed_cleanup_pending' => 0, 'foreign_entries' => 0];

        foreach (self::KINDS as $kind => [$model]) {
            $counts['completed_cleanup_pending'] += $model::query()->where('status', 'completed')->whereNull('cleaned_at')->count();
            $expired = $model::query()->where('status', 'uploading')->where('expires_at', '<=', $now)
                ->where('updated_at', '<=', $cutoff)->where('created_at', '<=', $cutoff)->orderBy('id')->pluck('id')->all();
            // Cancelled rows cannot be written again; their own cancel cleans at once, so only an old interrupted one is left.
            $pending = $model::query()->where('status', 'cancelled')->whereNull('cleaned_at')
                ->where('created_at', '<=', $cutoff)->orderBy('id')->pluck('id')->all();
            $counts['sessions_expired'] += count($expired);
            $counts['sessions_cancelled_pending'] += count($pending);
            foreach ([...$expired, ...$pending] as $id) {
                $this->one($kind, (int) $id, $execute, $temp, $euid, $now, $cutoff, $minAgeSeconds, $counts);
            }
        }
        $counts['foreign_entries'] = $temp === null ? 0 : $this->foreignEntries($temp, $euid);

        return $counts;
    }

    private function one(string $kind, int $id, bool $execute, ?string $temp, int $euid, CarbonImmutable $now,
        CarbonImmutable $cutoff, int $minAgeSeconds, array &$counts): void
    {
        [$model, $parentColumn, $parentModel, $action] = self::KINDS[$kind];
        if (! $execute) {
            $session = $model::query()->find($id);
            if ($session === null || ! $this->eligible($session, $now, $cutoff)) {
                return;
            }
            $plan = $this->inspect($temp, $session->public_id, $euid);
            $counts[$plan === null ? 'directories_absent' : ($plan === false ? 'directories_left' : 'directories_removed')]++;
            $counts['files_removed'] += is_array($plan) ? count($plan) : 0;

            return;
        }
        // 1. Mark: a committed terminal state before any file is removed.
        DB::transaction(function () use ($model, $parentColumn, $parentModel, $action, $id, $now, $cutoff, $minAgeSeconds, &$counts): void {
            $session = $this->lock($model, $parentColumn, $parentModel, $id);
            if ($session === null || ! $this->eligible($session, $now, $cutoff) || $session->status !== 'uploading') {
                return;
            }
            $session->forceFill(['status' => 'cancelled'])->save();
            AuditEvent::recordAttributed($action, $session, ['reason' => 'expired_unattended',
                'received_bytes' => $session->received_bytes, 'min_age_seconds' => $minAgeSeconds], null);
            $counts['sessions_marked']++;
        });
        // 2. Remove under the same locks the upload and cancel paths take, then record the cleanup.
        DB::transaction(function () use ($model, $parentColumn, $parentModel, $id, $temp, $euid, &$counts): void {
            $session = $this->lock($model, $parentColumn, $parentModel, $id);
            if ($session === null || $session->status !== 'cancelled' || $session->cleaned_at !== null) {
                return;
            }
            $plan = $this->inspect($temp, $session->public_id, $euid);
            if ($plan === false) {
                $counts['directories_left']++;

                return;
            }
            if ($plan === null) {
                $counts['directories_absent']++;
            } else {
                try {
                    $this->remove($temp.'/'.$session->public_id, $plan, $euid, $this->identity);
                } catch (UploadSweepRefused) {
                    // Whatever was removed was transport bytes of a terminal session; the rest stays for inspection.
                    $counts['directories_left']++;

                    return;
                }
                $counts['directories_removed']++;
                $counts['files_removed'] += count($plan);
            }
            $session->forceFill(['cleaned_at' => now()])->save();
        });
    }

    private function eligible(Model $session, CarbonImmutable $now, CarbonImmutable $cutoff): bool
    {
        if ($session->cleaned_at !== null || $session->created_at === null || $session->created_at->gt($cutoff)) {
            return false;
        }
        if ($session->status === 'cancelled') {
            return true;
        }

        return $session->status === 'uploading' && $session->expires_at->lte($now)
            && $session->updated_at !== null && $session->updated_at->lte($cutoff);
    }

    private function lock(string $model, string $parentColumn, string $parentModel, int $id): ?Model
    {
        // The upload paths lock the parent (track or kit draft) before the session row; take them in the same order.
        $parentId = $model::query()->whereKey($id)->value($parentColumn);
        if ($parentId === null || $parentModel::query()->lockForUpdate()->find($parentId) === null) {
            return null;
        }
        $session = $model::query()->lockForUpdate()->find($id);

        return $session !== null && (int) $session->{$parentColumn} === (int) $parentId ? $session : null;
    }

    /**
     * @return list<string>|null|false the transport file names to remove, null when there is no directory, false when the
     *                                 directory (or anything in it) fails a check and must be left in place
     */
    private function inspect(?string $temp, string $publicId, int $euid): array|null|false
    {
        if (preg_match(self::UUID, $publicId) !== 1) {
            return false;
        }
        if ($temp === null) {
            return null;
        }
        $path = $temp.'/'.$publicId;
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false) {
            return null;
        }
        if (($stat['mode'] & 0177777) !== 0040700 || $stat['uid'] !== $euid || realpath($path) !== $path) {
            return false;
        }
        $this->identity = [$stat['dev'], $stat['ino']];
        $entries = @scandir($path);
        if (! is_array($entries)) {
            return false;
        }
        $files = [];
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            clearstatcache(true, $path.'/'.$name);
            $entry = @lstat($path.'/'.$name);
            if (preg_match(self::TRANSPORT_FILE, $name) !== 1 || ! is_array($entry) || ($entry['mode'] & 0170000) !== 0100000
                || $entry['nlink'] !== 1 || $entry['uid'] !== $euid || count($files) >= 1024) {
                return false;
            }
            $files[] = $name;
        }

        return $files;
    }

    /**
     * @param  list<string>  $files
     * @param  array{0:int,1:int}  $identity  device and inode of the directory as inspected
     */
    private function remove(string $path, array $files, int $euid, array $identity): void
    {
        foreach ($files as $name) {
            // The directory must still be the one inspected: a swapped-in directory (or a link to one) is never emptied.
            clearstatcache(true, $path);
            $directory = @lstat($path);
            if (! is_array($directory) || ($directory['mode'] & 0177777) !== 0040700 || $directory['uid'] !== $euid
                || [$directory['dev'], $directory['ino']] !== $identity || realpath($path) !== $path) {
                throw new UploadSweepRefused('cleanup_incomplete');
            }
            // Re-check each file immediately before unlinking: a link or replacement placed since inspection is never removed.
            clearstatcache(true, $path.'/'.$name);
            $entry = @lstat($path.'/'.$name);
            if (! is_array($entry) || ($entry['mode'] & 0170000) !== 0100000 || $entry['uid'] !== $euid || ! @unlink($path.'/'.$name)) {
                throw new UploadSweepRefused('cleanup_incomplete');
            }
        }
        if (! @rmdir($path)) {
            throw new UploadSweepRefused('cleanup_incomplete');
        }
    }

    /** Entries of the temp root that no sweep may touch: anything not a private, owned, UUID-named directory. */
    private function foreignEntries(string $temp, int $euid): int
    {
        $entries = @scandir($temp);
        if (! is_array($entries)) {
            return 0;
        }
        $foreign = 0;
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            clearstatcache(true, $temp.'/'.$name);
            $stat = @lstat($temp.'/'.$name);
            if (preg_match(self::UUID, $name) !== 1 || ! is_array($stat) || ($stat['mode'] & 0177777) !== 0040700 || $stat['uid'] !== $euid) {
                $foreign++;
            }
        }

        return $foreign;
    }
}
