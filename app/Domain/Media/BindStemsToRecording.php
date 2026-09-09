<?php

namespace App\Domain\Media;

use App\Domain\Catalog\Models\Track;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Models\StemsRecording;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BindStemsToRecording
{
    public function handle(MediaAsset $stems, array $data, User $actor): StemsRecording
    {
        // Re-read role state for direct callers holding an older User instance.
        $actor = User::findOrFail($actor->id);
        Gate::forUser($actor)->authorize('administer-catalog');
        if (array_diff(array_keys($data), ['master_asset_id', 'preview_asset_id', 'verification_reference', 'same_recording_confirmed'])) {
            throw ValidationException::withMessages(['master_asset_id' => 'Only recording selection and confirmation fields may be submitted.']);
        }
        if (is_string($data['verification_reference'] ?? null)) {
            $data['verification_reference'] = trim($data['verification_reference']);
        }
        $data = Validator::make($data, [
            'master_asset_id' => ['required', 'integer', 'min:1'],
            'preview_asset_id' => ['required', 'integer', 'min:1'],
            'verification_reference' => ['required', 'string', 'max:240'],
            'same_recording_confirmed' => ['required', 'accepted'],
        ])->validate();
        $stems = MediaAsset::findOrFail($stems->id);

        return DB::transaction(function () use ($stems, $data, $actor) {
            // This track lock serializes association with publication, quote selection and media completion.
            $track = Track::query()->lockForUpdate()->findOrFail($stems->track_id);
            if ($track->status !== 'draft') {
                throw ValidationException::withMessages(['master_asset_id' => 'Unpublish the track before associating stems.']);
            }
            $stems = MediaAsset::findOrFail($stems->id);
            $master = MediaAsset::find($data['master_asset_id']);
            $preview = $track->assets()->where('role', 'preview_tagged')->where('status', 'ready')->latest('id')->first();
            if ($stems->role !== 'stems_zip' || ! $master || $master->role !== 'master_wav' || $master->track_id !== $track->id
                || ! $preview || $preview->id !== (int) $data['preview_asset_id'] || $master->parent_asset_id !== $preview->parent_asset_id
                || $master->processing_run_id !== $preview->processing_run_id) {
                throw ValidationException::withMessages(['master_asset_id' => 'Choose a verified master for this track’s current preview. Reopen the action if the recording changed.']);
            }
            $associations = app(RecordingAssociation::class);
            foreach ([$stems, $master, $preview] as $asset) {
                if (! $associations->freshDigestMatches($asset)) {
                    throw ValidationException::withMessages(['master_asset_id' => 'Stems, master and preview must have intact processing evidence and matching private bytes.']);
                }
            }
            $existing = StemsRecording::where('stems_asset_id', $stems->id)->first();
            if ($existing) {
                if ($existing->master_asset_id === $master->id && $existing->preview_asset_id === $preview->id
                    && $existing->verification_reference === $data['verification_reference'] && $associations->verified($stems)) {
                    return $existing;
                }
                throw ValidationException::withMessages(['master_asset_id' => 'This stems revision is already associated. Preserve its evidence and upload a new stems revision to make a correction.']);
            }
            $binding = new StemsRecording([
                'track_id' => $track->id, 'stems_asset_id' => $stems->id, 'master_asset_id' => $master->id, 'preview_asset_id' => $preview->id,
                'recording_source_id' => $master->parent_asset_id, 'verified_by' => $actor->id, 'verified_at' => now()->startOfSecond(),
                'verification_reference' => $data['verification_reference'], 'canonicalization_version' => CanonicalJson::VERSION,
            ]);
            $binding->evidence = $associations->evidence($binding, $stems, $master, $preview);
            $binding->evidence_hash = CanonicalJson::hash($binding->evidence);
            $binding->save();
            AuditEvent::record('media.stems.recording_associated', $binding, [
                'stems_asset_id' => $stems->id, 'master_asset_id' => $master->id, 'preview_asset_id' => $preview->id, 'evidence_hash' => $binding->evidence_hash,
            ], $actor->id);

            return $binding;
        });
    }
}
