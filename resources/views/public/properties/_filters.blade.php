<form id="property-search-form" action="{{ $action ?? route('properties.index') }}" method="GET" class="mt-8 grid gap-4 rounded-lg bg-white p-5 shadow-sm md:grid-cols-4">
    <input name="keyword" value="{{ request('keyword') }}" placeholder="Palabra clave o zona" class="rounded border-[#ded8ca]">
    <select name="operation_type" class="rounded border-[#ded8ca]"><option value="">Todas las operaciones</option><option value="sale" @selected(request('operation_type')==='sale')>Venta</option><option value="rent" @selected(request('operation_type')==='rent')>Renta</option><option value="presale" @selected(request('operation_type')==='presale')>Preventa</option></select>
    <select name="property_type" class="rounded border-[#ded8ca]"><option value="">Tipo de propiedad</option>@foreach($options['types'] as $value => $label)<option value="{{ $value }}" @selected(request('property_type')===$value)>{{ $label }}</option>@endforeach</select>
    <select name="state" id="property-search-state" class="rounded border-[#ded8ca]"><option value="">Estado</option>@foreach($options['states'] as $state)<option value="{{ $state }}" @selected(request('state')===$state)>{{ $state }}</option>@endforeach</select>
    <select name="municipality" id="property-search-municipality" class="rounded border-[#ded8ca]" disabled><option value="">Selecciona primero un estado</option>@foreach($options['municipalities'] as $municipality)<option value="{{ $municipality }}" @selected(request('municipality', request('city'))===$municipality)>{{ $municipality }}</option>@endforeach</select>
    <input name="neighborhood" id="property-search-neighborhood" value="{{ request('neighborhood') }}" placeholder="Selecciona una colonia" class="rounded border-[#ded8ca]" disabled autocomplete="off">
    <input name="min_price" value="{{ request('min_price') }}" type="number" placeholder="Precio mínimo (MXN)" class="rounded border-[#ded8ca]">
    <input name="max_price" value="{{ request('max_price') }}" type="number" placeholder="Precio máximo (MXN)" class="rounded border-[#ded8ca]">
    <input name="bedrooms" value="{{ request('bedrooms') }}" type="number" placeholder="Recámaras" class="rounded border-[#ded8ca]">
    <input name="bathrooms" value="{{ request('bathrooms') }}" type="number" step="0.5" placeholder="Baños" class="rounded border-[#ded8ca]">
    <select name="sort" class="rounded border-[#ded8ca]"><option value="recent">Más recientes</option><option value="price_asc" @selected(request('sort')==='price_asc')>Precio menor</option><option value="price_desc" @selected(request('sort')==='price_desc')>Precio mayor</option><option value="featured" @selected(request('sort')==='featured')>Destacadas</option></select>
    <div class="flex gap-2"><button class="flex-1 rounded bg-[#0d2723] px-5 py-3 text-white">Filtrar</button><a class="rounded border px-5 py-3" href="{{ $clearUrl ?? route('properties.index') }}">Limpiar</a></div>
</form>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('property-search-form');
    if (!form) return;
    const state = form.querySelector('[name="state"]');
    const municipality = form.querySelector('[name="municipality"]');
    const neighborhood = form.querySelector('[name="neighborhood"]');
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
    const loadMunicipalities = async (selected = '') => {
        const requestId = ++municipalityRequest;
        ++settlementRequest;
        resetNeighborhood();
        resetMunicipality(state.value ? 'Cargando municipios...' : 'Selecciona primero un estado');
        municipality.disabled = !state.value;
        neighborhood.disabled = true;
        if (!state.value) return;
        try {
            const response = await fetch(`${municipalitiesUrl}?state=${encodeURIComponent(state.value)}`);
            const values = await response.json();
            if (requestId !== municipalityRequest || state.value === '') return;
            resetMunicipality('Municipio / alcaldía');
            values.forEach(value => municipality.add(new Option(value, value, false, value === selected)));
            municipality.disabled = false;
            neighborhood.disabled = !municipality.value;
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
    municipality.addEventListener('change', () => { ++settlementRequest; resetNeighborhood(); neighborhood.disabled = !municipality.value; });
    neighborhood.addEventListener('input', suggestSettlements);
    municipality.disabled = true;
    neighborhood.disabled = true;
    if (state.value) loadMunicipalities(selectedMunicipality).then(() => {
        if (selectedNeighborhood && municipality.value) { neighborhood.value = selectedNeighborhood; suggestSettlements(); }
    });
});
</script>
