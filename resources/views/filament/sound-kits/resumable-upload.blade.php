<x-filament-panels::page>
    <p>Retain a private WAV sample kit ZIP in resumable parts. Technical verification is requested after the archive is saved. This does not approve rights, publication, checkout or delivery.</p>
    <x-filament::section heading="Selected kit draft">
        <p>{{ $this->kitTitle }} · Draft version {{ $this->expectedVersion }}</p>
        <p>If this draft changes during upload, completion is refused. Cancel the old upload and reopen this page for its current version.</p>
    </x-filament::section>
    <div wire:ignore data-resumable-kit-upload data-kit-id="{{ $this->kitId }}" data-operator="{{ auth()->id() }}" data-base-url="{{ route('filament.admin.sound-kit-uploads.start', absolute: false) }}" style="display: grid; gap: 1rem; min-width: 0; overflow-wrap: anywhere;">
        <label style="display: grid; gap: .5rem;">
            <span>File from your device</span>
            <input data-upload-file type="file" accept=".zip,application/zip" aria-describedby="upload-file-help" style="max-width: 100%; min-height: 44px;" />
        </label>
        <p id="upload-file-help">WAV sample kit ZIP: up to 200 MiB, sent in 8 MiB parts. MIDI and plugin presets are not accepted. Files are verified with SHA-256 before sending; this may take a moment. Sessions last 24 hours. Up to four unfinished uploads can be retained per operator. Keep the upload ID to resume after a reload. The whole-file form remains available for small files within the server request limit.</p>
        <div style="display: flex; flex-wrap: wrap; gap: .75rem;">
            <x-filament::button type="button" style="min-height: 44px;" wire:click="prepareUpload" data-upload-action="start">Start upload</x-filament::button>
            <x-filament::button type="button" style="min-height: 44px;" data-upload-action="pause" disabled color="gray">Pause</x-filament::button>
        </div>
        <label style="display: grid; gap: .5rem;">
            <span>Upload ID</span>
            <input data-upload-id type="text" maxlength="36" autocomplete="off" spellcheck="false" style="width: 100%; min-height: 44px; padding: .5rem; color: inherit; background: transparent; border: 1px solid currentColor; border-radius: .375rem;" />
        </label>
        <p>To resume, inspect this ID, reselect the same file and choose Continue upload. If starting was interrupted before an ID appeared, retry Start upload with the same file and unchanged kit draft.</p>
        <div style="display: flex; flex-wrap: wrap; gap: .75rem;">
            <x-filament::button type="button" style="min-height: 44px;" data-upload-action="inspect" color="gray">Inspect upload</x-filament::button>
            <x-filament::button type="button" style="min-height: 44px;" data-upload-action="resume" color="gray">Continue upload</x-filament::button>
            <x-filament::button type="button" style="min-height: 44px;" data-upload-action="finish" disabled>Finish upload</x-filament::button>
            <x-filament::button type="button" style="min-height: 44px;" data-upload-action="cancel" disabled color="danger">Cancel upload</x-filament::button>
        </div>
        <progress max="1" value="0" aria-label="Confirmed upload progress" style="width: 100%;"></progress>
        <p data-upload-details></p>
        <p data-upload-status role="status" aria-live="polite">Choose a WAV sample kit ZIP to start, or inspect an existing upload.</p>
        <a href="{{ \App\Filament\Resources\SoundKitDraftResource::getUrl() }}" style="text-decoration: underline; min-height: 44px; display: inline-flex; align-items: center;">Return to Sound kit drafts</a>
    </div>
    @vite('resources/js/admin/resumable-kit-upload.ts')
</x-filament-panels::page>
