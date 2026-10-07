<div style="display:grid;gap:1rem;overflow-wrap:anywhere">
    <p>LOCAL/TESTING FREE-PURPOSE DEFINITION. This does not prove operative identity, production terms approval or paid status.</p>
    <h2>{{ $definition['title'] }}</h2>
    <p>{{ $definition['product']['title'] }} / {{ $definition['product']['artist'] }}</p>
    <p>Purpose: {{ $definition['purpose'] }}; reference {{ $definition['termsReference'] }}</p>
    <p>{{ $definition['assentText'] }}</p>
    <h3>{{ $definition['license']['name'] }} / version {{ $definition['license']['version'] }}</h3>
    <pre style="white-space:pre-wrap;font:inherit">{{ $definition['license']['termsText'] }}</pre>
    <p>Up to {{ $definition['maxOrigins'] }} grants; {{ $definition['maxDownloads'] }} committed download attempts per grant; authorization lifetime {{ $definition['tokenTtlSeconds'] }} seconds.</p>
    <p>Admission version {{ $definition['version'] }}: {{ $definition['open'] ? 'open' : 'closed' }}. Definition hash {{ $definition['definitionHash'] }}; review {{ $definition['reviewHash'] ?? 'pending' }}.</p>
    <ul>@foreach ($definition['assets'] as $asset)<li>{{ $asset['role'] }} / {{ $asset['size_bytes'] }} bytes / SHA256 {{ $asset['sha256'] }}</li>@endforeach</ul>
</div>
