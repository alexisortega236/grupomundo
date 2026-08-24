@props(['title' => 'Panel administrativo'])
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} | {{ config('app.name') }}</title>
    <x-vite-assets />
</head>
<body class="bg-[#f5f2eb] text-[#112f2b] antialiased">
    <div class="min-h-screen lg:flex">
        <aside class="bg-[#0d2723] p-6 text-white lg:w-72">
            <a href="{{ route('admin.dashboard') }}" class="flex items-center gap-3">
                <span class="grid h-10 w-10 place-items-center border border-[#d5b673] font-serif text-lg text-[#d5b673]">M</span>
                <span class="text-sm font-bold uppercase tracking-[.18em]">Grupo Mundo<br><span class="text-[#d5b673]">Patrimonial</span></span>
            </a>
            <nav class="mt-8 grid gap-2 text-sm">
                <a class="rounded px-3 py-2 hover:bg-white/10" href="{{ route('admin.dashboard') }}">Panel</a>
                <a class="rounded px-3 py-2 hover:bg-white/10" href="{{ route('admin.properties.index') }}">Propiedades</a>
                <a class="rounded px-3 py-2 hover:bg-white/10" href="{{ route('admin.valuations.index') }}">Valuaciones</a>
                <a class="rounded px-3 py-2 hover:bg-white/10" href="{{ route('admin.amenities.index') }}">Amenidades</a>
                <a class="rounded px-3 py-2 hover:bg-white/10" href="{{ route('admin.contact-requests.index') }}">Solicitudes</a>
                @can('admin-only')<a class="rounded px-3 py-2 hover:bg-white/10" href="{{ route('admin.users.index') }}">Usuarios</a>@endcan
                <form method="POST" action="{{ route('logout') }}">@csrf<button class="mt-4 w-full rounded border border-white/20 px-3 py-2 text-left hover:bg-white/10">Cerrar sesión</button></form>
            </nav>
        </aside>
        <div class="min-w-0 flex-1">
            <header class="border-b border-[#ded8ca] bg-white px-6 py-5">
                <h1 class="font-serif text-3xl text-[#0d2723]">{{ $title }}</h1>
            </header>
            <main class="p-6">
                <x-alert />
                {{ $slot }}
            </main>
        </div>
    </div>
</body>
</html>
