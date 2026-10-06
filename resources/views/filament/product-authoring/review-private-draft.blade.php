@if ($review !== null)
    <div class="space-y-4">
        <p>Every saved version is retained. This review records private descriptive contents.</p>
        @if ($review['before_manifest'] !== null)
            <div>
                <h3 class="font-semibold">Saved version {{ $review['version'] }}</h3>
                <pre class="whitespace-pre-wrap break-words text-sm">{{ $resource::describe($review['before_manifest']) }}</pre>
            </div>
        @else
            <p>This creates the first private draft version.</p>
        @endif
        <div>
            <h3 class="font-semibold">Entered contents</h3>
            <pre class="whitespace-pre-wrap break-words text-sm">{{ $resource::describe($review['manifest']) }}</pre>
        </div>
    </div>
@else
    <p>Keep a copy of the entered contents. Close and reopen the current draft to obtain a fresh review.</p>
@endif
