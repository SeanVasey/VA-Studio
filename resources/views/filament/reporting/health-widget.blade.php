@php
    $labels = ['ok' => 'OK', 'attention' => 'Needs attention', 'unavailable' => 'Unavailable'];
@endphp
<x-filament-widgets::widget>
    <x-filament::section :heading="$section['title']">
        <dl style="display: grid; grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr)); gap: 1rem;">
            @foreach ($section['metrics'] as $metric)
                <div data-metric="{{ $metric['key'] }}" data-state="{{ $metric['state'] }}">
                    <dt style="font-weight: 600;">{{ $metric['label'] }}</dt>
                    <dd style="margin: 0;">
                        <span style="font-size: 1.5rem; font-weight: 700;">{{ $metric['value'] === null ? 'Unavailable' : $metric['value'] }}</span>
                        <span style="margin-left: .5rem; font-weight: 600;">{{ $labels[$metric['state']] }}</span>
                        <span style="display: block; font-size: .875rem;">{{ $metric['detail'] }}</span>
                    </dd>
                </div>
            @endforeach
        </dl>
    </x-filament::section>
</x-filament-widgets::widget>
