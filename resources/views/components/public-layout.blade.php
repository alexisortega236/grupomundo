@props(['title' => config('app.name'), 'description' => 'Inmobiliaria patrimonial en Ciudad de Mexico.'])
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <meta name="description" content="{{ $description }}">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <x-vite-assets />
</head>
<body class="bg-[#f5f2eb] font-sans text-[#112f2b] antialiased">
    <x-header />
    <main>{{ $slot }}</main>
    <x-footer />
</body>
</html>
