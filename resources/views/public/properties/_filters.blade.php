<form id="property-search-form" action="{{ $action ?? route('properties.index') }}" method="GET" class="mt-8 rounded-lg bg-white p-5 shadow-sm ring-1 ring-[#eee8dc] md:p-6">
    <div class="flex flex-col gap-1">
        <p class="text-xs font-bold uppercase tracking-[.2em] text-[#b89752]">Búsqueda sencilla</p>
        <h2 class="font-serif text-2xl text-[#0d2723]">¿Dónde te gustaría encontrar tu propiedad?</h2>
    </div>

    <div class="mt-5 grid gap-4 md:grid-cols-[1.1fr_1fr_1fr_auto] md:items-end">
        <div>
            <label for="property-search-keyword" class="mb-1 block text-sm font-semibold text-[#0d2723]">Zona o palabra clave <span class="font-normal text-[#687773]">(opcional)</span></label>
            <input id="property-search-keyword" name="keyword" value="{{ request('keyword') }}" placeholder="Ej. Del Valle, casa..." class="w-full rounded border-[#ded8ca]">
        </div>
        <div>
            <label for="property-search-state" class="mb-1 block text-sm font-semibold text-[#0d2723]">1. Estado o ciudad</label>
            <select name="state" id="property-search-state" class="w-full rounded border-[#ded8ca]"><option value="">Todos los estados</option>@foreach($options['states'] as $state)<option value="{{ $state }}" @selected(request('state')===$state)>{{ $state }}</option>@endforeach</select>
        </div>
        <div id="property-municipality-field" class="hidden">
            <label for="property-search-municipality" class="mb-1 block text-sm font-semibold text-[#0d2723]">2. Municipio o alcaldía</label>
            <select name="municipality" id="property-search-municipality" class="w-full rounded border-[#ded8ca]" disabled><option value="">Selecciona un estado</option>@foreach($options['municipalities'] as $municipality)<option value="{{ $municipality }}" @selected(request('municipality', request('city'))===$municipality)>{{ $municipality }}</option>@endforeach</select>
        </div>
        <div class="flex gap-2">
            <button class="flex-1 rounded bg-[#0d2723] px-5 py-3 font-semibold text-white">Buscar</button>
            <a class="rounded border border-[#ded8ca] px-4 py-3 text-[#0d2723]" href="{{ $clearUrl ?? route('properties.index') }}">Limpiar</a>
        </div>
    </div>

    <div id="property-neighborhood-field" class="mt-4 hidden max-w-md">
        <label for="property-search-neighborhood" class="mb-1 block text-sm font-semibold text-[#0d2723]">3. Colonia <span class="font-normal text-[#687773]">(opcional)</span></label>
        <input name="neighborhood" id="property-search-neighborhood" value="{{ request('neighborhood') }}" placeholder="Escribe para ver sugerencias" class="w-full rounded border-[#ded8ca]" disabled autocomplete="off">
    </div>

    <details class="mt-6 border-t border-[#eee8dc] pt-4">
        <summary class="cursor-pointer text-sm font-semibold text-[#0d2723]">Más filtros <span class="font-normal text-[#687773]">(operación, tipo, precio y características)</span></summary>
        <div class="mt-4 grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            <select name="operation_type" aria-label="Tipo de operación" class="rounded border-[#ded8ca]"><option value="">Todas las operaciones</option><option value="sale" @selected(request('operation_type')==='sale')>Venta</option><option value="rent" @selected(request('operation_type')==='rent')>Renta</option><option value="presale" @selected(request('operation_type')==='presale')>Preventa</option></select>
            <select name="property_type" aria-label="Tipo de propiedad" class="rounded border-[#ded8ca]"><option value="">Tipo de propiedad</option>@foreach($options['types'] as $value => $label)<option value="{{ $value }}" @selected(request('property_type')===$value)>{{ $label }}</option>@endforeach</select>
            <select name="sort" aria-label="Ordenar resultados" class="rounded border-[#ded8ca]"><option value="recent">Más recientes</option><option value="price_asc" @selected(request('sort')==='price_asc')>Precio menor</option><option value="price_desc" @selected(request('sort')==='price_desc')>Precio mayor</option><option value="featured" @selected(request('sort')==='featured')>Destacadas</option></select>
            <input name="min_price" value="{{ request('min_price') }}" type="number" placeholder="Precio mínimo (MXN)" class="rounded border-[#ded8ca]">
            <input name="max_price" value="{{ request('max_price') }}" type="number" placeholder="Precio máximo (MXN)" class="rounded border-[#ded8ca]">
            <input name="bedrooms" value="{{ request('bedrooms') }}" type="number" placeholder="Recámaras" class="rounded border-[#ded8ca]">
            <input name="bathrooms" value="{{ request('bathrooms') }}" type="number" step="0.5" placeholder="Baños" class="rounded border-[#ded8ca]">
        </div>
    </details>
</form>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('property-search-form');
    if (!form) return;
    const state = form.querySelector('[name="state"]');
    const municipality = form.querySelector('[name="municipality"]');
    const neighborhood = form.querySelector('[name="neighborhood"]');
    const municipalityField = form.querySelector('#property-municipality-field');
    const neighborhoodField = form.querySelector('#property-neighborhood-field');
    const municipalitiesUrl = @json(route('valuation.locations.municipalities'));
    const settlementsUrl = @json(route('valuation.locations.settlements'));
    const selectedMunicipality = @json(request('municipality', request('city', '')));
    const selectedNeighborhood = @json(request('neighborhood', ''));
    let municipalityRequest = 0;
    let settlementRequest = 0;

    const resetMunicipality = (label) => {
        municipality.innerHTML = '';
        municipality.add(new Option(label, ''));
    };
    const resetNeighborhood = () => {
        neighborhood.value = '';
        neighborhood.removeAttribute('list');
        const list = document.getElementById('property-neighborhood-options');
        list?.remove();
    };
    const showLocationStep = (element, visible) => {
        element.classList.toggle('hidden', !visible);
        element.setAttribute('aria-hidden', visible ? 'false' : 'true');
    };
    const loadMunicipalities = async (selected = '') => {
        const requestId = ++municipalityRequest;
        ++settlementRequest;
        resetNeighborhood();
        resetMunicipality(state.value ? 'Cargando municipios...' : 'Selecciona primero un estado');
        municipality.disabled = !state.value;
        neighborhood.disabled = true;
        showLocationStep(municipalityField, Boolean(state.value));
        showLocationStep(neighborhoodField, false);
        if (!state.value) return;
        try {
            const response = await fetch(`${municipalitiesUrl}?state=${encodeURIComponent(state.value)}`);
            const values = await response.json();
            if (requestId !== municipalityRequest || state.value === '') return;
            resetMunicipality('Municipio / alcaldía');
            values.forEach(value => municipality.add(new Option(value, value, false, value === selected)));
            municipality.disabled = false;
            neighborhood.disabled = !municipality.value;
            showLocationStep(neighborhoodField, Boolean(municipality.value));
        } catch {
            if (requestId === municipalityRequest) resetMunicipality('No fue posible cargar municipios');
        }
    };
    const suggestSettlements = async () => {
        const query = neighborhood.value.trim();
        const requestId = ++settlementRequest;
        if (!state.value || !municipality.value || query.length < 3) return;
        try {
            const params = new URLSearchParams({ state: state.value, municipality: municipality.value, q: query });
            const response = await fetch(`${settlementsUrl}?${params}`);
            const values = await response.json();
            if (requestId !== settlementRequest) return;
            let list = document.getElementById('property-neighborhood-options');
            if (!list) { list = document.createElement('datalist'); list.id = 'property-neighborhood-options'; neighborhood.after(list); }
            list.innerHTML = Array.isArray(values) ? values.map(value => `<option value="${value.name}">`).join('') : '';
            neighborhood.setAttribute('list', list.id);
        } catch { /* El formulario sigue siendo utilizable aunque falle la sugerencia. */ }
    };

    state.addEventListener('change', () => loadMunicipalities());
    municipality.addEventListener('change', () => { ++settlementRequest; resetNeighborhood(); neighborhood.disabled = !municipality.value; showLocationStep(neighborhoodField, Boolean(municipality.value)); });
    neighborhood.addEventListener('input', suggestSettlements);
    municipality.disabled = true;
    neighborhood.disabled = true;
    showLocationStep(municipalityField, false);
    showLocationStep(neighborhoodField, false);
    if (state.value) loadMunicipalities(selectedMunicipality).then(() => {
        if (selectedNeighborhood && municipality.value) { neighborhood.value = selectedNeighborhood; suggestSettlements(); }
    });
});
</script>
