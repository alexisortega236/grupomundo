<x-public-layout title="Propiedades | Grupo Mundo Patrimonial">
    <section class="bg-[#f7f2e8] px-5 py-14"><div class="mx-auto max-w-7xl"><h1 class="text-4xl font-black text-[#082142]">Propiedades</h1><p class="mt-3 text-slate-600">{{ $properties->total() }} resultados encontrados.</p></div></section>
    <section class="mx-auto max-w-7xl px-5 py-10 lg:px-8">
        <form class="grid gap-4 rounded-2xl bg-white p-5 shadow md:grid-cols-3 lg:grid-cols-5">
            <input name="keyword" value="{{ request('keyword') }}" placeholder="Palabra clave" class="rounded-xl border-slate-200">
            <select name="operation_type" class="rounded-xl border-slate-200"><option value="">Operación</option><option value="sale" @selected(request('operation_type')==='sale')>Venta</option><option value="rent" @selected(request('operation_type')==='rent')>Renta</option></select>
            <select name="property_type" class="rounded-xl border-slate-200"><option value="">Tipo</option>@foreach($types as $type)<option @selected(request('property_type')===$type)>{{ $type }}</option>@endforeach</select>
            <select name="state" class="rounded-xl border-slate-200"><option value="">Estado</option>@foreach($states as $state)<option @selected(request('state')===$state)>{{ $state }}</option>@endforeach</select>
            <select name="city" class="rounded-xl border-slate-200"><option value="">Ciudad</option>@foreach($cities as $city)<option @selected(request('city')===$city)>{{ $city }}</option>@endforeach</select>
            <input name="neighborhood" value="{{ request('neighborhood') }}" placeholder="Zona o colonia" class="rounded-xl border-slate-200">
            <input name="min_price" value="{{ request('min_price') }}" placeholder="Precio mínimo" class="rounded-xl border-slate-200">
            <input name="max_price" value="{{ request('max_price') }}" placeholder="Precio máximo" class="rounded-xl border-slate-200">
            <input name="bedrooms" value="{{ request('bedrooms') }}" placeholder="Recámaras" class="rounded-xl border-slate-200">
            <input name="bathrooms" value="{{ request('bathrooms') }}" placeholder="Baños" class="rounded-xl border-slate-200">
            <select name="sort" class="rounded-xl border-slate-200"><option value="">Más recientes</option><option value="price_asc" @selected(request('sort')==='price_asc')>Precio menor a mayor</option><option value="price_desc" @selected(request('sort')==='price_desc')>Precio mayor a menor</option><option value="featured" @selected(request('sort')==='featured')>Destacadas</option></select>
            <button class="rounded-full bg-[#082142] px-5 py-3 font-bold text-white">Filtrar</button><a href="{{ route('properties.index') }}" class="rounded-full border px-5 py-3 text-center font-bold">Limpiar filtros</a>
        </form>
        <div class="mt-10 grid gap-8 md:grid-cols-2 lg:grid-cols-3">@forelse($properties as $property)<x-property-card :property="$property" />@empty<x-empty-state />@endforelse</div>
        <div class="mt-10">{{ $properties->links() }}</div>
    </section>
</x-public-layout>
