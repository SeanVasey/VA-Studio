<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }} — VASEY.AUDIO preview</title>
    @vite('resources/css/fonts.css')
    <link rel="stylesheet" href="/brand/theme.css">
    <link rel="stylesheet" href="/css/track-embed.css">
</head>
<body>
<main aria-labelledby="preview-title">
    <p class="brand">VASEY.AUDIO / TAGGED PREVIEW</p>
    <h1 id="preview-title">{{ $title }}</h1>
    <p class="artist">{{ $artist }}</p>
    <audio controls preload="none" src="{{ $previewUrl }}" aria-label="Tagged preview of {{ $title }}">
        Your browser does not support this audio player. Open the track on VASEY.AUDIO.
    </audio>
    <a href="{{ $storeUrl }}" target="_blank" rel="noopener noreferrer">Open track on VASEY.AUDIO <span class="new-window">(new tab)</span></a>
</main>
</body>
</html>
