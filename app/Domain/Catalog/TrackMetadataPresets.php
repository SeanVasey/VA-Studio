<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\Models\TrackMetadataPreset;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** One seller's mutable reusable defaults. A copied form is independent of the source preset. */
class TrackMetadataPresets
{
    private const MAX_VERSION = 2147483647;

    public function handle(?TrackMetadataPreset $preset, array $data, User $actor): TrackMetadataPreset
    {
        return DB::transaction(function () use ($preset, $data, $actor) {
            $currentActor = $this->lockActor($actor);
            if (array_diff(array_keys($data), ['name', ...TrackMetadataPresetPayload::FIELDS, 'version'])) {
                throw ValidationException::withMessages(['name' => 'Only a preset name and reusable descriptive metadata may be saved here.']);
            }
            $locked = $preset?->exists ? $this->lockPreset((int) $preset->getKey()) : new TrackMetadataPreset;
            if ($locked->exists) {
                $this->checkVersion($locked, $data['version'] ?? null);
                $this->requireActive($locked);
            } elseif (array_key_exists('version', $data)) {
                throw ValidationException::withMessages(['name' => 'A new preset cannot supply a revision.']);
            }
            $before = $locked->exists ? $this->state($locked) : null;
            $payload = app(TrackMetadataPresetPayload::class);
            $name = $payload->name($data['name'] ?? ($before['name'] ?? null));
            unset($data['name'], $data['version']);
            $metadata = $payload->normalize($data + ($before['metadata'] ?? []));
            $after = ['name' => $name, 'metadata' => $metadata, 'archived' => false];
            $changed = [];
            if ($before === null || $before['name'] !== $name) {
                $changed[] = 'name';
            }
            foreach (TrackMetadataPresetPayload::FIELDS as $field) {
                if ($before === null || $before['metadata'][$field] !== $metadata[$field]) {
                    $changed[] = $field;
                }
            }
            if ($changed === []) {
                return $locked;
            }
            $locked->name = $name;
            $locked->metadata = $metadata;
            $locked->version = $locked->exists ? $this->nextVersion($locked) : 1;
            $locked->save();
            $this->audit($before === null ? 'created' : 'updated', $locked, $before, $after, $changed, $currentActor);

            return $locked;
        });
    }

    /** Selection labels only; no description is exposed until a fresh authorized copy is prepared. */
    public function active(User $actor): array
    {
        return DB::transaction(function () use ($actor) {
            $this->lockActor($actor);
            // Consistent actor -> ascending preset lock order across every command.
            $presets = TrackMetadataPreset::query()->whereNull('archived_at')->orderBy('id')->lockForUpdate()->get();
            $rows = $presets->map(fn (TrackMetadataPreset $preset) => [
                'id' => (int) $preset->id, 'version' => $preset->version, 'name' => $preset->name,
            ])->all();
            usort($rows, fn (array $left, array $right) => strcmp($left['name'], $right['name']) ?: $left['id'] <=> $right['id']);

            return $rows;
        });
    }

    /** Start a new independent reviewed copy only while its source is active. */
    public function snapshot(int $id, User $actor, ?int $expectedVersion = null): array
    {
        return DB::transaction(function () use ($id, $actor, $expectedVersion) {
            $this->lockActor($actor);
            $preset = $this->lockPreset($id);
            if ($expectedVersion !== null) {
                $this->checkVersion($preset, $expectedVersion);
            }
            $this->requireActive($preset);
            $state = $this->state($preset);

            return ['id' => (int) $preset->id, 'version' => $preset->version, 'name' => $state['name'], 'metadata' => $state['metadata']];
        });
    }

    public function archive(TrackMetadataPreset $preset, int $expectedVersion, User $actor): TrackMetadataPreset
    {
        return DB::transaction(function () use ($preset, $expectedVersion, $actor) {
            $currentActor = $this->lockActor($actor);
            $locked = $this->lockPreset((int) $preset->getKey());
            $this->checkVersion($locked, $expectedVersion);
            if ($locked->archived_at !== null) {
                return $locked;
            }
            $before = $this->state($locked);
            $locked->archived_at = now();
            $locked->version = $this->nextVersion($locked);
            $locked->save();
            $this->audit('archived', $locked, $before, [...$before, 'archived' => true], ['archived_at'], $currentActor);

            return $locked;
        });
    }

    /** Reviewed ordinary metadata only. Never reread or merge a source preset during saving. */
    public function createDraft(array $reviewedMetadata, User $actor): Track
    {
        return DB::transaction(function () use ($reviewedMetadata, $actor) {
            $currentActor = $this->lockActor($actor);

            return app(SaveTrackMetadata::class)->handle(null, $reviewedMetadata, $currentActor);
        });
    }

    private function lockActor(User $actor): User
    {
        $current = $actor->exists ? User::query()->lockForUpdate()->find($actor->getKey()) : null;
        if ($current === null) {
            throw new AuthorizationException;
        }
        // The gate also reloads the user: require a current read, not an old MySQL RR view.
        Gate::forUser($current)->authorize('administer-catalog', [true]);
        if (! AdminMultiFactor::satisfiedBy($current, lockForUpdate: true)) {
            throw new AuthorizationException('Admin multi-factor authentication is required.');
        }

        return $current;
    }

    private function lockPreset(int $id): TrackMetadataPreset
    {
        $preset = $id > 0 ? TrackMetadataPreset::query()->lockForUpdate()->find($id) : null;
        if ($preset === null) {
            throw ValidationException::withMessages(['name' => 'This preset is no longer available. Choose a current preset.']);
        }

        return $preset;
    }

    private function checkVersion(TrackMetadataPreset $preset, mixed $expectedVersion): void
    {
        Validator::make(['version' => $expectedVersion], ['version' => ['required', 'integer', 'min:1', 'max:'.self::MAX_VERSION]])->validate();
        if ((int) $expectedVersion !== $preset->version) {
            throw ValidationException::withMessages(['name' => 'This preset changed since you opened it. Close and reopen the editor, then apply your changes.']);
        }
    }

    private function nextVersion(TrackMetadataPreset $preset): int
    {
        if ($preset->version < 1 || $preset->version >= self::MAX_VERSION) {
            throw ValidationException::withMessages(['name' => 'This preset cannot accept another revision. Create a new preset.']);
        }

        return $preset->version + 1;
    }

    private function requireActive(TrackMetadataPreset $preset): void
    {
        if ($preset->archived_at !== null) {
            throw ValidationException::withMessages(['name' => 'This preset has been archived. Choose an active preset for a new copy.']);
        }
    }

    private function state(TrackMetadataPreset $preset): array
    {
        $metadata = $preset->metadata;
        if (! is_array($metadata) || array_diff(TrackMetadataPresetPayload::FIELDS, array_keys($metadata))) {
            throw ValidationException::withMessages(['name' => 'This preset has invalid stored metadata. Create a new preset.']);
        }
        $payload = app(TrackMetadataPresetPayload::class);
        $normalized = $payload->normalize($metadata);
        if (CanonicalJson::hash($metadata) !== CanonicalJson::hash($normalized) || $payload->name($preset->name) !== $preset->name || $preset->version < 1 || $preset->version > self::MAX_VERSION) {
            throw ValidationException::withMessages(['name' => 'This preset has invalid stored metadata. Create a new preset.']);
        }

        return ['name' => $preset->name, 'metadata' => $normalized, 'archived' => $preset->archived_at !== null];
    }

    private function audit(string $action, TrackMetadataPreset $preset, ?array $before, array $after, array $changed, User $actor): void
    {
        AuditEvent::record('catalog.track_metadata_preset.'.$action, $preset, [
            'schema_version' => 1,
            'version' => $preset->version,
            'changed_fields' => $changed,
            'canonicalization_version' => CanonicalJson::VERSION,
            'before_hash' => $before === null ? null : CanonicalJson::hash($before),
            'after_hash' => CanonicalJson::hash($after),
        ], $actor->id);
    }
}
