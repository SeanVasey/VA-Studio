<x-filament-panels::page>
    <p>Public previews carry the seller tag by default. Turn the tag off here to let every track without its own setting play the untagged preview instead, wherever an untagged variant exists. Each track can override this from Tracks, Preview tag.</p>
    <p>Switching is immediate and reprocesses nothing: both previews are kept, and only the one visitors hear changes. A track with no untagged variant keeps playing its tagged preview. Licenses, files and offers are not touched.</p>
    <form wire:submit="save" style="display: flex; flex-wrap: wrap; gap: 1rem; align-items: center;">
        <label for="preview-tag-default" style="display: flex; gap: .5rem; align-items: center; font-weight: 600; min-height: 44px;">
            <input id="preview-tag-default" type="checkbox" wire:model="defaultTagged">
            Public previews carry the seller tag (store default)
        </label>
        <button type="submit" class="fi-btn fi-color fi-size-md fi-btn-color-primary" style="padding: .5rem 1rem; font-weight: 600; min-height: 44px;">Save store default</button>
    </form>
    <section aria-labelledby="preview-tag-coverage">
        <h2 id="preview-tag-coverage" style="font-size: 1.25rem; font-weight: 700;">Untagged variants</h2>
        <p data-coverage>{{ $coverage['with_untagged'] }} of {{ $coverage['previewed'] }} tracks with a preview have a recorded untagged variant.
            Older draft tracks gain one when their master is processed again (<code>php artisan vasey:media-retag-previews --add-untagged</code>); until then they keep playing the tagged preview.</p>
    </section>
</x-filament-panels::page>
