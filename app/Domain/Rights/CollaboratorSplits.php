<?php

namespace App\Domain\Rights;

use App\Domain\Catalog\Models\Track;
use App\Domain\Rights\Models\Collaborator;
use App\Domain\Rights\Models\TrackCollaboratorSplit;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The only supported writer of collaborators and per-track splits. Every command runs in one transaction that locks the
 * acting staff user, then the track, then the track's shares of the split type, and records exactly one audit event per
 * change. Splits are catalog metadata: no pricing, accounting, contract or payout code reads them.
 */
final class CollaboratorSplits
{
    private const COLLABORATOR_FIELDS = ['display_name', 'role', 'source_reference'];

    private const SPLIT_FIELDS = ['track_id', 'collaborator_id', 'split_type', 'share_bps'];

    public function saveCollaborator(?Collaborator $collaborator, array $data, User $actor): Collaborator
    {
        return DB::transaction(function () use ($collaborator, $data, $actor): Collaborator {
            $current = $this->lockActor($actor);
            $this->exactKeys($data, self::COLLABORATOR_FIELDS, 'display_name');
            $locked = $collaborator?->exists ? Collaborator::query()->lockForUpdate()->find($collaborator->getKey()) : new Collaborator;
            if ($locked === null) {
                $this->reject('display_name', 'This collaborator is no longer available. Reload and choose a current collaborator.');
            }
            $after = ['display_name' => $this->text($data['display_name'], 'display_name', false),
                'role' => $this->enum($data['role'], CollaboratorRole::values(), 'role'),
                'source_reference' => $this->text($data['source_reference'], 'source_reference', true)];
            $before = $locked->exists ? ['display_name' => $locked->display_name, 'role' => $locked->role->value,
                'source_reference' => $locked->source_reference] : null;
            $changed = array_values(array_filter(self::COLLABORATOR_FIELDS, fn (string $field): bool => $before === null || $before[$field] !== $after[$field]));
            if ($changed === []) {
                return $locked;
            }
            $locked->fill($after)->save();
            AuditEvent::record('rights.collaborator.'.($before === null ? 'created' : 'updated'), $locked, [
                'schema_version' => 1, 'changed_fields' => $changed, 'role' => $after['role'],
                'before_hash' => $before === null ? null : CanonicalJson::hash($before), 'after_hash' => CanonicalJson::hash($after),
            ], $current->id);

            return $locked;
        });
    }

    public function addSplit(array $data, User $actor): TrackCollaboratorSplit
    {
        return DB::transaction(function () use ($data, $actor): TrackCollaboratorSplit {
            $current = $this->lockActor($actor);
            $this->exactKeys($data, self::SPLIT_FIELDS, 'share_bps');
            $trackId = $this->id($data['track_id'], 'track_id');
            $collaboratorId = $this->id($data['collaborator_id'], 'collaborator_id');
            $type = $this->enum($data['split_type'], SplitType::values(), 'split_type');
            $share = $this->share($data['share_bps']);
            $this->lockTrack($trackId);
            if (Collaborator::query()->lockForUpdate()->find($collaboratorId) === null) {
                $this->reject('collaborator_id', 'Choose a current collaborator.');
            }
            $peers = $this->lockSet($trackId, $type);
            if ($peers->contains(fn (TrackCollaboratorSplit $peer): bool => (int) $peer->collaborator_id === $collaboratorId)) {
                $this->reject('collaborator_id', 'This collaborator already has a '.$type.' share on this track. Edit that share instead.');
            }
            $total = $this->total($peers, $share);
            $split = TrackCollaboratorSplit::create(['track_id' => $trackId, 'collaborator_id' => $collaboratorId,
                'split_type' => $type, 'share_bps' => $share]);
            $this->audit('added', $split, null, $share, $total, $current);

            return $split;
        });
    }

    /** The expected share is what the editor saw; a share changed by someone else since is never overwritten. */
    public function updateShare(TrackCollaboratorSplit $split, mixed $expectedShareBps, mixed $shareBps, User $actor): TrackCollaboratorSplit
    {
        return DB::transaction(function () use ($split, $expectedShareBps, $shareBps, $actor): TrackCollaboratorSplit {
            $current = $this->lockActor($actor);
            $share = $this->share($shareBps);
            [$locked, $peers] = $this->lockSplit($split, $expectedShareBps);
            if ($locked->share_bps === $share) {
                return $locked;
            }
            $before = $locked->share_bps;
            $total = $this->total($peers->reject(fn (TrackCollaboratorSplit $peer): bool => $peer->is($locked)), $share);
            $locked->share_bps = $share;
            $locked->save();
            $this->audit('updated', $locked, $before, $share, $total, $current);

            return $locked;
        });
    }

    public function removeSplit(TrackCollaboratorSplit $split, mixed $expectedShareBps, User $actor): void
    {
        DB::transaction(function () use ($split, $expectedShareBps, $actor): void {
            $current = $this->lockActor($actor);
            [$locked, $peers] = $this->lockSplit($split, $expectedShareBps);
            $before = $locked->share_bps;
            $total = (int) $peers->sum('share_bps') - $before;
            $locked->delete();
            $this->audit('removed', $locked, $before, null, $total, $current);
        });
    }

    private function lockActor(User $actor): User
    {
        $current = $actor->exists ? User::query()->lockForUpdate()->find($actor->getKey()) : null;
        if ($current === null) {
            throw new AuthorizationException;
        }
        // The gate also reloads the user: require a current read, not an old MySQL Repeatable Read view.
        Gate::forUser($current)->authorize('administer-catalog', [true]);
        if (! AdminMultiFactor::satisfiedBy($current, lockForUpdate: true)) {
            throw new AuthorizationException('Admin multi-factor authentication is required.');
        }

        return $current;
    }

    private function lockTrack(int $trackId): Track
    {
        $track = Track::query()->lockForUpdate()->find($trackId);
        if ($track === null) {
            $this->reject('track_id', 'Choose a current track.');
        }

        return $track;
    }

    /** Caller holds the track lock. A locking read is current even inside an older Repeatable Read snapshot. */
    private function lockSet(int $trackId, string $type)
    {
        return TrackCollaboratorSplit::query()->where('track_id', $trackId)->where('split_type', $type)
            ->orderBy('id')->lockForUpdate()->get();
    }

    private function lockSplit(TrackCollaboratorSplit $split, mixed $expectedShareBps): array
    {
        // A split never changes track or type (database guard), so this plain read safely names the track to lock first.
        $identity = $split->exists ? TrackCollaboratorSplit::query()->find($split->getKey()) : null;
        if ($identity === null) {
            $this->reject('share_bps', 'This split is no longer recorded. Reload collaborator splits.');
        }
        $this->lockTrack((int) $identity->track_id);
        $peers = $this->lockSet((int) $identity->track_id, $identity->split_type->value);
        $locked = $peers->first(fn (TrackCollaboratorSplit $peer): bool => $peer->is($identity));
        if ($locked === null) {
            $this->reject('share_bps', 'This split is no longer recorded. Reload collaborator splits.');
        }
        if ($this->share($expectedShareBps, 'The share you reviewed is not a valid share. Reload collaborator splits.') !== $locked->share_bps) {
            $this->reject('share_bps', 'This share changed since you opened it. Reload collaborator splits, then apply your change.');
        }

        return [$locked, $peers];
    }

    private function total($peers, int $share): int
    {
        $total = (int) $peers->sum('share_bps') + $share;
        if ($total > CollaboratorSplitReport::COMPLETE_BPS) {
            $this->reject('share_bps', 'Shares for one track and split type cannot total more than 10000 basis points. '
                .'The other shares total '.($total - $share).'.');
        }

        return $total;
    }

    private function audit(string $action, TrackCollaboratorSplit $split, ?int $before, ?int $after, int $total, User $actor): void
    {
        AuditEvent::record('rights.collaborator_split.'.$action, $split, [
            'schema_version' => 1, 'track_id' => (int) $split->track_id, 'collaborator_id' => (int) $split->collaborator_id,
            'split_type' => $split->split_type->value, 'before_share_bps' => $before, 'after_share_bps' => $after,
            'set_total_bps' => $total, 'set_complete' => $total === CollaboratorSplitReport::COMPLETE_BPS,
        ], $actor->id);
    }

    private function exactKeys(array $data, array $fields, string $field): void
    {
        if (array_diff(array_keys($data), $fields) !== [] || array_diff($fields, array_keys($data)) !== []) {
            $this->reject($field, 'Only '.implode(', ', $fields).' may be saved here.');
        }
    }

    private function id(mixed $value, string $field): int
    {
        if (is_string($value) && preg_match('/\A[1-9][0-9]{0,17}\z/D', $value)) {
            $value = (int) $value;
        }
        if (! is_int($value) || $value < 1) {
            $this->reject($field, 'Choose a current record.');
        }

        return $value;
    }

    private function share(mixed $value, string $message = 'A share is a whole number of basis points from 1 to 10000 (10000 is 100%).'): int
    {
        if (is_string($value) && preg_match('/\A[1-9][0-9]{0,4}\z/D', $value)) {
            $value = (int) $value;
        }
        if (! is_int($value) || $value < 1 || $value > CollaboratorSplitReport::COMPLETE_BPS) {
            $this->reject('share_bps', $message);
        }

        return $value;
    }

    private function enum(mixed $value, array $allowed, string $field): string
    {
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }
        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            $this->reject($field, 'Choose one of: '.implode(', ', $allowed).'.');
        }

        return $value;
    }

    private function text(mixed $value, string $field, bool $nullable): ?string
    {
        if ($nullable && ($value === null || (is_string($value) && trim($value) === ''))) {
            return null;
        }
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            $this->reject($field, 'Enter plain text.');
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 255 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            $this->reject($field, 'Enter 1 to 255 characters on one line.');
        }
        // Splits are metadata only: an email address here would be contact data this model must not hold.
        if (preg_match('/[^\s@]+@[^\s@]+\.[^\s@]+/', $value)) {
            $this->reject($field, 'Do not record email addresses, payment details, tax ids or other contact data here.');
        }

        return $value;
    }

    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
