<x-public-layout title="Propiedades | Grupo Mundo Patrimonial" description="Catálogo de propiedades en venta y renta.">
    <section class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
        <h1 class="font-serif text-5xl">Propiedades</h1>
        <form class="mt-8 grid gap-4 rounded-lg bg-white p-5 shadow-sm md:grid-cols-4">
            <input name="keyword" value="{{ request('keyword') }}" placeholder="Palabra clave" class="rounded border-[#ded8ca]">
            <select name="operation_type" class="rounded border-[#ded8ca]"><option value="">Todas las operaciones</option><option value="sale" @selected(request('operation_type')==='sale')>Venta</option><option value="rent" @selected(request('operation_type')==='rent')>Renta</option><option value="presale" @selected(request('operation_type')==='presale')>Preventa</option></select>
            <select name="property_type" class="rounded border-[#ded8ca]"><option value="">Tipo de propiedad</option>@foreach($options['types'] as $value => $label)<option value="{{ $value }}" @selected(request('property_type')===$value)>{{ $label }}</option>@endforeach</select>
            <select name="state" class="rounded border-[#ded8ca]"><option value="">Estado</option>@foreach($options['states'] as $state)<option @selected(request('state')===$state)>{{ $state }}</option>@endforeach</select>
            <select name="municipality" class="rounded border-[#ded8ca]"><option value="">Municipio / ciudad</option>@foreach($options['municipalities'] as $municipality)<option value="{{ $municipality }}" @selected(request('municipality', request('city'))===$municipality)>{{ $municipality }}</option>@endforeach</select>
            <input name="neighborhood" value="{{ request('neighborhood') }}" placeholder="Colonia" class="rounded border-[#ded8ca]">
            <input name="min_price" value="{{ request('min_price') }}" type="number" placeholder="Precio mínimo (MXN)" class="rounded border-[#ded8ca]">
            <input name="max_price" value="{{ request('max_price') }}" type="number" placeholder="Precio máximo (MXN)" class="rounded border-[#ded8ca]">
            <input name="bedrooms" value="{{ request('bedrooms') }}" type="number" placeholder="Recámaras" class="rounded border-[#ded8ca]">
            <input name="bathrooms" value="{{ request('bathrooms') }}" type="number" step="0.5" placeholder="Baños" class="rounded border-[#ded8ca]">
            <select name="sort" class="rounded border-[#ded8ca]"><option value="recent">Más recientes</option><option value="price_asc" @selected(request('sort')==='price_asc')>Precio menor</option><option value="price_desc" @selected(request('sort')==='price_desc')>Precio mayor</option><option value="featured" @selected(request('sort')==='featured')>Destacadas</option></select>
            <div class="flex gap-2"><button class="flex-1 rounded bg-[#0d2723] px-5 py-3 text-white">Filtrar</button><a class="rounded border px-5 py-3" href="{{ route('properties.index') }}">Limpiar</a></div>
        </form>
        <p class="mt-6 text-sm text-[#687773]">{{ $properties->total() }} resultados encontrados</p>
        <div class="mt-8 grid gap-6 md:grid-cols-2 lg:grid-cols-3">@forelse($properties as $property)<x-property-card :property="$property" />@empty<x-empty-state />@endforelse</div>
        <div class="mt-8">{{ $properties->links() }}</div>
    </section>
</x-public-layout>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const state = document.querySelector('[name="state"]');
    const municipality = document.querySelector('[name="municipality"]');
    const neighborhood = document.querySelector('[name="neighborhood"]');
    if (!state || !municipality || !neighborhood) return;
    const selectedMunicipality = @json(request('municipality', request('city', '')));
    const selectedNeighborhood = @json(request('neighborhood', ''));
    const municipalitiesUrl = @json(route('valuation.locations.municipalities'));
    const settlementsUrl = @json(route('valuation.locations.settlements'));
    const resetMunicipality = (label) => { municipality.innerHTML = `<option value="">${label}</option>`; };
    const loadMunicipalities = async (selected = '') => {
        resetMunicipality('Cargando municipios...');
        if (!state.value) return;
        const response = await fetch(`${municipalitiesUrl}?state=${encodeURIComponent(state.value)}`);
        const values = await response.json(); resetMunicipality('Municipio / ciudad');
        values.forEach(value => municipality.add(new Option(value, value, false, value === selected)));
        neighborhood.disabled = !municipality.value;
    };
    const suggestSettlements = async () => {
        const query = neighborhood.value.trim(); if (!state.value || !municipality.value || query.length < 3) return;
        const response = await fetch(`${settlementsUrl}?${new URLSearchParams({state: state.value, municipality: municipality.value, q: query})}`);
        const values = await response.json();
        neighborhood.setAttribute('list', 'property-neighborhood-options');
        let list = document.getElementById('property-neighborhood-options');
        if (!list) { list = document.createElement('datalist'); list.id = 'property-neighborhood-options'; neighborhood.after(list); }
        list.innerHTML = values.map(value => `<option value="${value.name}">`).join('');
    };
    municipality.innerHTML = '<option value="">Municipio / ciudad</option>';
    municipality.disabled = !state.value; neighborhood.disabled = !state.value;
    state.addEventListener('change', () => loadMunicipalities());
    municipality.addEventListener('change', () => { neighborhood.value = ''; neighborhood.disabled = !municipality.value; });
    neighborhood.addEventListener('input', suggestSettlements);
    if (state.value) loadMunicipalities(selectedMunicipality);
    if (selectedNeighborhood) neighborhood.value = selectedNeighborhood;
});
</script>
