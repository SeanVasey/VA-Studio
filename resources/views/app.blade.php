<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#101214">
    @viteReactRefresh
    @vite('resources/js/app.tsx')
    <x-inertia::head>
        @isset($metadata)
            <title data-inertia="title">{{ $metadata['title'] }}</title>
            <meta data-inertia="description" name="description" content="{{ $metadata['description'] }}">
            <meta data-inertia="robots" name="robots" content="{{ $metadata['robots'] }}">
            <link data-inertia="canonical" rel="canonical" href="{{ $metadata['canonicalUrl'] }}">
            <meta data-inertia="og:site_name" property="og:site_name" content="VASEY.AUDIO">
            <meta data-inertia="og:type" property="og:type" content="{{ $metadata['type'] }}">
            <meta data-inertia="og:title" property="og:title" content="{{ $metadata['title'] }}">
            <meta data-inertia="og:description" property="og:description" content="{{ $metadata['description'] }}">
            <meta data-inertia="og:url" property="og:url" content="{{ $metadata['canonicalUrl'] }}">
            <meta data-inertia="og:image" property="og:image" content="{{ $metadata['imageUrl'] }}">
            <meta data-inertia="og:image:alt" property="og:image:alt" content="{{ $metadata['imageAlt'] }}">
            @if (($metadata['imageWidth'] ?? null) !== null)
                <meta data-inertia="og:image:width" property="og:image:width" content="{{ $metadata['imageWidth'] }}">
                <meta data-inertia="og:image:height" property="og:image:height" content="{{ $metadata['imageHeight'] }}">
                <meta data-inertia="og:image:type" property="og:image:type" content="{{ $metadata['imageType'] }}">
            @endif
            <meta data-inertia="twitter:card" name="twitter:card" content="summary_large_image">
            <meta data-inertia="twitter:title" name="twitter:title" content="{{ $metadata['title'] }}">
            <meta data-inertia="twitter:description" name="twitter:description" content="{{ $metadata['description'] }}">
            <meta data-inertia="twitter:image" name="twitter:image" content="{{ $metadata['imageUrl'] }}">
            <meta data-inertia="twitter:image:alt" name="twitter:image:alt" content="{{ $metadata['imageAlt'] }}">
        @endisset
    </x-inertia::head>
</head>
<body>
    @inertia
</body>
</html>
