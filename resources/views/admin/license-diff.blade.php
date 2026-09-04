<div class="space-y-3">
    <p>Changes from the predecessor version. Review source text, summaries, deliverables and effective dates together.</p>
    <pre class="max-h-96 overflow-auto whitespace-pre-wrap break-words rounded-lg border border-gray-300 p-4 text-sm dark:border-gray-700">{{ json_encode($changes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) }}</pre>
</div>
