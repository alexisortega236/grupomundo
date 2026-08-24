<x-admin-layout title="Panel">
    <div class="grid gap-4 md:grid-cols-4">@foreach($stats as $label => $value)<div class="rounded-lg bg-white p-5 shadow-sm"><p class="text-sm text-[#687773]">{{ $label }}</p><p class="mt-2 text-3xl font-bold">{{ $value }}</p></div>@endforeach</div>
    <div class="mt-8 grid gap-6 lg:grid-cols-2">
        <section class="rounded-lg bg-white p-6"><div class="flex flex-wrap justify-between gap-3"><h2 class="font-serif text-2xl">Últimas propiedades</h2><a class="text-[#b89752]" href="{{ route('admin.properties.create') }}">Crear propiedad</a></div><div class="mt-4 divide-y">@forelse($latestProperties as $p)<a class="block py-3" href="{{ route('admin.properties.show', $p) }}">{{ $p->title }} <span class="text-sm text-[#687773]">{{ $p->status->label() }}</span></a>@empty<p class="py-3 text-sm text-[#687773]">No hay propiedades recientes.</p>@endforelse</div></section>
        <section class="rounded-lg bg-white p-6"><h2 class="font-serif text-2xl">Últimas solicitudes</h2><div class="mt-4 divide-y">@forelse($latestRequests as $r)<a class="block py-3" href="{{ route('admin.contact-requests.show', $r) }}">{{ $r->name }} <span class="text-sm text-[#687773]">{{ $r->property?->title }}</span></a>@empty<p class="py-3 text-sm text-[#687773]">No hay solicitudes recientes.</p>@endforelse</div></section>
    </div>
</x-admin-layout>
