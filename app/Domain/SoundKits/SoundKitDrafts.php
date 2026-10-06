<?php

namespace App\Domain\SoundKits;

use App\Domain\SoundKits\Models\SoundKitDraft;
use App\Domain\SoundKits\Models\SoundKitRevision;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SoundKitDrafts
{
    public function save(?SoundKitDraft $draft, array $data, User $actor): SoundKitDraft
    {
        return DB::transaction(function () use ($draft, $data, $actor) {
            $actor = $this->actor($actor);
            if (array_diff(array_keys($data), ['title', 'description', 'provenance', 'version'])) {
                $this->reject('title', 'Only kit descriptive metadata and a source reference may be edited.');
            }
            $current = $draft === null ? new SoundKitDraft : $this->lock((int) $draft->getKey());
            if ($current->exists) {
                $this->expected($current, $data['version'] ?? null);
            } elseif (array_key_exists('version', $data)) {
                $this->reject('title', 'A new kit cannot supply a revision.');
            }
            $fields = [];
            foreach (['title' => [180, true], 'description' => [4000, false], 'provenance' => [500, true]] as $field => [$limit, $required]) {
                $text = $data[$field] ?? ($required ? null : '');
                if (! is_string($text) || ! mb_check_encoding($text, 'UTF-8') || mb_strlen($text) > $limit
                    || ($required && trim($text) === '') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $text)) {
                    $this->reject($field, 'Enter bounded plain text for this field.');
                }
                $fields[$field] = trim($text);
            }
            $before = $current->exists ? $current->only(array_keys($fields)) : null;
            if ($before !== null && CanonicalJson::hash($before) === CanonicalJson::hash($fields)) {
                return $current;
            }
            if (! $current->exists) {
                $current->fill(['public_id' => (string) Str::uuid(), 'created_by' => $actor->id, 'version' => 0]);
            }
            $current->fill($fields);
            $this->advance($current);
            $current->save();
            AuditEvent::record('sound_kit.draft.'.($before === null ? 'created' : 'updated'), $current, [
                'version' => $current->version, 'before_hash' => $before === null ? null : CanonicalJson::hash($before),
                'after_hash' => CanonicalJson::hash($fields),
            ], $actor->id);

            return $current;
        });
    }

    public function snapshot(int $id, User $actor): array
    {
        return DB::transaction(function () use ($id, $actor) {
            $this->actor($actor);
            $draft = $this->lock($id);
            $revisions = $draft->revisions()->orderByDesc('number')->lockForUpdate()->get()->map(function (SoundKitRevision $revision): array {
                $verified = $revision->status === 'ready' ? app(SoundKitManifest::class)->verified($revision) : null;

                return ['id' => (int) $revision->id, 'number' => $revision->number, 'status' => $revision->status,
                    'original_name' => $revision->original_name, 'source_size_bytes' => $revision->source_size_bytes,
                    'source_sha256' => $revision->source_sha256, 'failure_code' => $revision->failure_code,
                    'attempts' => $revision->attempts, 'description_snapshot' => $revision->description_snapshot,
                    'manifest' => $verified, 'manifest_sha256' => $revision->manifest_sha256,
                    'retryable' => $revision->status === 'quarantined' || ($revision->status === 'processing' && $revision->claimed_until?->lte(now()))];
            })->all();

            return ['id' => (int) $draft->id, ...$draft->only(['title', 'description', 'provenance', 'version']), 'revisions' => $revisions];
        });
    }

    /** Internal transaction fence shared by intake, retry and processing; never a cached precheck. */
    public function actor(User $actor): User
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Sound-kit authority requires a transaction.');
        }
        $current = $actor->exists ? User::query()->lockForUpdate()->find($actor->getKey()) : null;
        if ($current === null) {
            throw new AuthorizationException;
        }
        Gate::forUser($current)->authorize('administer-catalog', [true]);
        if (! AdminMultiFactor::satisfiedBy($current, lockForUpdate: true)) {
            throw new AuthorizationException('Admin multi-factor authentication is required.');
        }

        return $current;
    }

    public function lock(int $id): SoundKitDraft
    {
        $draft = $id > 0 ? SoundKitDraft::query()->lockForUpdate()->find($id) : null;
        if ($draft === null) {
            $this->reject('title', 'This private kit is no longer available.');
        }

        return $draft;
    }

    public function expected(SoundKitDraft $draft, mixed $version): void
    {
        if (! (is_int($version) || (is_string($version) && preg_match('/\A[1-9][0-9]*\z/D', $version)))
            || (string) (int) $version !== (string) $version || (int) $version !== $draft->version || $draft->version < 1) {
            $this->reject('title', 'This kit changed since you opened it. Reload before saving or uploading.');
        }
    }

    public function advance(SoundKitDraft $draft): void
    {
        if ($draft->version < 0 || $draft->version >= 2147483647) {
            $this->reject('title', 'This kit cannot accept another revision.');
        }
        $draft->version++;
    }

    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
