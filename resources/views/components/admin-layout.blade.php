@props(['title' => 'Panel administrativo'])
<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $title }}</title>@vite(['resources/css/app.css', 'resources/js/app.js'])</head>
<body class="bg-[#f7f2e8] text-slate-700">
<div class="min-h-screen lg:grid lg:grid-cols-[280px_1fr]">
    <aside class="bg-[#061a34] p-5 text-white">
        <a href="{{ route('admin.dashboard') }}" class="block font-extrabold">Grupo Mundo Patrimonial</a>
        <nav class="mt-8 grid gap-2 text-sm">
            <a class="rounded-xl px-3 py-2 hover:bg-white/10" href="{{ route('admin.dashboard') }}">Dashboard</a>
            <a class="rounded-xl px-3 py-2 hover:bg-white/10" href="{{ route('admin.properties.index') }}">Propiedades</a>
            <a class="rounded-xl px-3 py-2 hover:bg-white/10" href="{{ route('admin.amenities.index') }}">Amenidades</a>
            <a class="rounded-xl px-3 py-2 hover:bg-white/10" href="{{ route('admin.contact-requests.index') }}">Solicitudes</a>
            @can('admin-only')<a class="rounded-xl px-3 py-2 hover:bg-white/10" href="{{ route('admin.users.index') }}">Usuarios</a>@endcan
            <a class="rounded-xl px-3 py-2 hover:bg-white/10" href="{{ route('home') }}">Ver sitio</a>
            <form method="post" action="{{ route('logout') }}">@csrf<button class="rounded-xl px-3 py-2 text-left hover:bg-white/10">Cerrar sesión</button></form>
        </nav>
    </aside>
    <main class="p-5 lg:p-8">
        <x-alert />
        {{ $slot }}
    </main>
</div>
</body>
</html>
