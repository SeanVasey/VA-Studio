@if ($sharing === null)
    <p role="status">Public sharing is unavailable for this track. Refresh the catalog and try again.</p>
@else
    @php
        $fieldPrefix = 'operator-sharing-'.hash('sha256', json_encode($sharing, JSON_THROW_ON_ERROR));
    @endphp
    <div
        wire:key="{{ $fieldPrefix }}"
        x-data="{{ file_get_contents(resource_path('js/admin/operator-sharing.js')) }}"
        x-on:close-modal.window="cancel()"
        x-on:modal-closed.window="cancel()"
        style="display: grid; gap: 1.5rem; min-width: 0; overflow-wrap: anywhere;"
    >
        <p style="font-weight: 600;">{{ $sharing['title'] }}</p>
        <p id="{{ $fieldPrefix }}-help">Copy the public link or embed code. You can also select either field and use your device’s copy command.</p>

        <div style="display: grid; gap: 0.5rem; min-width: 0;">
            <label for="{{ $fieldPrefix }}-link" style="font-weight: 600;">Public link</label>
            <x-filament::input.wrapper>
                <x-filament::input
                    id="{{ $fieldPrefix }}-link"
                    x-ref="trackLink"
                    type="text"
                    :value="$sharing['trackUrl']"
                    readonly
                    spellcheck="false"
                    aria-describedby="{{ $fieldPrefix }}-help"
                    style="min-height: 44px;"
                />
            </x-filament::input.wrapper>
            <div style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
                <x-filament::button type="button" x-on:click="copy('link')" x-bind:disabled="copying !== null" style="min-height: 44px;">Copy link</x-filament::button>
                <x-filament::button type="button" color="gray" x-on:click="select('link')" x-bind:disabled="copying !== null" style="min-height: 44px;">Select link</x-filament::button>
            </div>
        </div>

        <div style="display: grid; gap: 0.5rem; min-width: 0;">
            <label for="{{ $fieldPrefix }}-embed-url" style="font-weight: 600;">Preview embed URL</label>
            <x-filament::input.wrapper>
                <x-filament::input
                    id="{{ $fieldPrefix }}-embed-url"
                    type="text"
                    :value="$sharing['embedUrl']"
                    readonly
                    spellcheck="false"
                    style="min-height: 44px;"
                />
            </x-filament::input.wrapper>
        </div>

        <div style="display: grid; gap: 0.5rem; min-width: 0;">
            <label for="{{ $fieldPrefix }}-embed-code" style="font-weight: 600;">Embed code</label>
            <x-filament::input.wrapper>
                <textarea
                    id="{{ $fieldPrefix }}-embed-code"
                    x-ref="embedCode"
                    readonly
                    spellcheck="false"
                    rows="6"
                    aria-describedby="{{ $fieldPrefix }}-help {{ $fieldPrefix }}-embed-help"
                    style="display: block; width: 100%; min-width: 0; padding: 0.5rem 0.75rem; resize: vertical; background: transparent; color: inherit; border: 0; font: inherit;"
                >{{ $sharing['embedCode'] }}</textarea>
            </x-filament::input.wrapper>
            <p id="{{ $fieldPrefix }}-embed-help">The embed plays the public tagged preview. It becomes unavailable when the track is no longer publicly eligible.</p>
            <div style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
                <x-filament::button type="button" x-on:click="copy('embed')" x-bind:disabled="copying !== null" style="min-height: 44px;">Copy embed</x-filament::button>
                <x-filament::button type="button" color="gray" x-on:click="select('embed')" x-bind:disabled="copying !== null" style="min-height: 44px;">Select embed code</x-filament::button>
            </div>
        </div>

        <p role="status" aria-live="polite" aria-atomic="true" x-text="status" style="min-height: 1.5rem;"></p>
    </div>
@endif
