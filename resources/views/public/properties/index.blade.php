<x-public-layout title="Propiedades | Grupo Mundo Patrimonial" description="Explora propiedades en venta y renta.">
    <section class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
        <h1 class="font-serif text-5xl">Propiedades</h1>
        @include('public.properties._filters', ['action' => route('properties.index'), 'clearUrl' => route('properties.index')])
        <p class="mt-6 text-sm text-[#687773]">{{ $properties->total() }} propiedades disponibles</p>
        <div class="mt-8 grid gap-6 md:grid-cols-2 lg:grid-cols-3">@forelse($properties as $property)<x-property-card :property="$property" />@empty<x-empty-state />@endforelse</div>
        <div class="mt-8">{{ $properties->links() }}</div>
    </section>
</x-public-layout>
