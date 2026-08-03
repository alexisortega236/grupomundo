@php
    $manifestPath = public_path('build/manifest.json');
    $manifest = is_file($manifestPath) ? json_decode(file_get_contents($manifestPath), true) : [];
    $css = $manifest['resources/css/app.css']['file'] ?? null;
    $js = $manifest['resources/js/app.js']['file'] ?? null;
@endphp

@if ($css)
    <link rel="preload" as="style" href="/build/{{ $css }}">
    <link rel="stylesheet" href="/build/{{ $css }}">
@endif

@if ($js)
    <link rel="modulepreload" href="/build/{{ $js }}">
    <script type="module" src="/build/{{ $js }}"></script>
@endif
