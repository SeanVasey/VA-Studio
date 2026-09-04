<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Retained license source</title>
    <style>body{font:16px/1.6 system-ui,sans-serif;max-width:76ch;margin:2rem auto;padding:0 1.25rem;color:#17242b}pre{font:inherit;white-space:pre-wrap;overflow-wrap:anywhere;background:#f2f5f6;padding:1rem}</style>
</head>
<body>
    <h1>Retained license source — nonbinding</h1>
    <p>This record does not meet the current review schema. This view preserves the original text; it does not create review evidence or establish a rights grant. Prepare and review a successor before using it in a new published offer.</p>
    <h2>{{ $license->template->name }} · version {{ $license->version }}</h2>
    <ul>@foreach ($errors as $error)<li>{{ $error }}</li>@endforeach</ul>
    <h2>Authored source (literal text)</h2>
    <pre>{{ $license->authored_source }}</pre>
    <h2>Retained structured terms</h2>
    <pre>{{ json_encode($license->structured_terms, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
</body>
</html>
