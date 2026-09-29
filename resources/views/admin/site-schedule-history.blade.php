<div class="space-y-4">
    <p>Every scheduled publication is retained. Times are UTC. A schedule publishes only through the same checks as Publish release.</p>
    @if ($rows === [])
        <p>No publication has been scheduled.</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr>
                        <th scope="col" class="p-2">Schedule</th>
                        <th scope="col" class="p-2">Release</th>
                        <th scope="col" class="p-2">Publish at</th>
                        <th scope="col" class="p-2">State</th>
                        <th scope="col" class="p-2">Outcome</th>
                        <th scope="col" class="p-2">Scheduled by</th>
                        <th scope="col" class="p-2">Resolved</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr class="border-t border-gray-300 dark:border-gray-700">
                            <td class="p-2">#{{ $row['id'] }}</td>
                            <td class="p-2">{{ $row['release'] }}</td>
                            <td class="p-2">{{ $row['publish_at'] }}</td>
                            <td class="p-2">{{ $row['state'] }}</td>
                            <td class="p-2">{{ $row['reason'] }}@if ($row['revision'] !== null) (publication revision {{ $row['revision'] }})@endif</td>
                            <td class="p-2">{{ $row['scheduled_by'] }}</td>
                            <td class="p-2">{{ $row['resolved'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
