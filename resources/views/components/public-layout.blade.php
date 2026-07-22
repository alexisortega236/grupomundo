@props(['title' => config('company.name'), 'description' => 'Propiedades seleccionadas y protección patrimonial en México.'])
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <meta name="description" content="{{ $description }}">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:type" content="website">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-white font-sans text-slate-700 antialiased">
    <x-header />
    <main>{{ $slot }}</main>
    <x-footer />
</body>
</html>
