<x-filament-panels::page>
    <p>Upload privately in resumable parts. Uploading does not process, publish or license a track. The existing whole-file upload remains available on the Media assets page.</p>
    <x-filament::section heading="New upload">
        {{ $this->form }}
    </x-filament::section>
    <div wire:ignore data-resumable-upload data-operator="{{ auth()->id() }}" data-base-url="{{ route('filament.admin.resumable-uploads.start', absolute: false) }}" style="display: grid; gap: 1rem; min-width: 0; overflow-wrap: anywhere;">
        <label style="display: grid; gap: .5rem;">
            <span>File from your device</span>
            <input data-upload-file type="file" accept=".wav,.zip,.png,.jpg,.jpeg" aria-describedby="upload-file-help" style="max-width: 100%; min-height: 44px;" />
        </label>
        <p id="upload-file-help">WAV master or WAV-only stems ZIP: up to 200 MiB. PNG/JPEG artwork: up to 20 MiB. Files are verified with SHA-256 before sending; this may take a moment. Sessions last 24 hours. Keep the upload ID to resume after a reload.</p>
        <div style="display: flex; flex-wrap: wrap; gap: .75rem;">
            <x-filament::button type="button" style="min-height: 44px;" wire:click="prepareUpload" data-upload-action="start">Start upload</x-filament::button>
            <x-filament::button type="button" style="min-height: 44px;" data-upload-action="pause" disabled color="gray">Pause</x-filament::button>
        </div>
        <label style="display: grid; gap: .5rem;">
            <span>Upload ID</span>
            <input data-upload-id type="text" maxlength="36" autocomplete="off" spellcheck="false" style="width: 100%; min-height: 44px; padding: .5rem; color: inherit; background: transparent; border: 1px solid currentColor; border-radius: .375rem;" />
        </label>
        <p>To resume, inspect this ID, reselect the same file and choose Continue upload. If starting was interrupted before an ID appeared, retry Start upload with the same file, track and role.</p>
        <div style="display: flex; flex-wrap: wrap; gap: .75rem;">
            <x-filament::button type="button" style="min-height: 44px;" data-upload-action="inspect" color="gray">Inspect upload</x-filament::button>
            <x-filament::button type="button" style="min-height: 44px;" data-upload-action="resume" color="gray">Continue upload</x-filament::button>
            <x-filament::button type="button" style="min-height: 44px;" data-upload-action="finish" disabled>Finish upload</x-filament::button>
            <x-filament::button type="button" style="min-height: 44px;" data-upload-action="cancel" disabled color="danger">Cancel upload</x-filament::button>
        </div>
        <progress max="1" value="0" aria-label="Confirmed upload progress" style="width: 100%;"></progress>
        <p data-upload-details></p>
        <p data-upload-status role="status" aria-live="polite">Choose a track, role and file to start, or inspect an existing upload.</p>
        <a href="{{ \App\Filament\Resources\MediaAssetResource::getUrl() }}" style="text-decoration: underline; min-height: 44px; display: inline-flex; align-items: center;">Return to Media assets</a>
    </div>
    @vite('resources/js/admin/resumable-media-upload.ts')
</x-filament-panels::page>
