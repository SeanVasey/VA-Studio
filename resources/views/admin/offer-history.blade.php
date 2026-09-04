<div class="space-y-4">
    @forelse ($revisions as $revision)
        <section class="rounded-lg border border-gray-300 p-4 dark:border-gray-700">
            <h3 class="font-semibold">Revision {{ $revision->revision }}{{ $revision->id === $currentId ? ' · Current' : '' }}</h3>
            <p>{{ $revision->currency }} {{ number_format($revision->price_minor / 100, 2) }} · {{ $revision->published_at?->utc()->format('Y-m-d H:i:s') }} UTC</p>
            <p>{{ $revision->snapshot['license']['name'] }} · License version {{ $revision->snapshot['license']['version'] }}</p>
            <ul class="mt-2 space-y-1">
                @foreach ($revision->snapshot['assets'] as $asset)
                    <li>{{ $asset['role'] }} · Revision #{{ $asset['id'] }} · SHA-256 {{ substr($asset['sha256'], 0, 12) }}…</li>
                @endforeach
            </ul>
            <p class="mt-2 break-all text-xs">Revision evidence: {{ $revision->snapshot_hash }}</p>
        </section>
    @empty
        <p>No published revisions yet. Save the offer draft, then publish its first revision.</p>
    @endforelse
</div>
