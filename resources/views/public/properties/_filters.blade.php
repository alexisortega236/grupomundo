<form id="property-search-form" x-data="propertySearchLocation({ initialState: @js(request('state', '')), initialMunicipality: @js(request('municipality', request('city', ''))), initialNeighborhood: @js(request('neighborhood', '')), municipalitiesUrl: @js(route('valuation.locations.municipalities')), settlementsUrl: @js(route('valuation.locations.settlements')) })" action="{{ $action ?? route('properties.index') }}" method="GET" class="mt-8 rounded-lg bg-white p-5 shadow-sm ring-1 ring-[#eee8dc] md:p-6">
    <div class="flex flex-col gap-1">
        <p class="text-xs font-bold uppercase tracking-[.2em] text-[#b89752]">Búsqueda sencilla</p>
        <h2 class="font-serif text-2xl text-[#0d2723]">¿Dónde te gustaría encontrar tu propiedad?</h2>
    </div>

    <div class="mt-5 grid gap-4 md:grid-cols-[1fr_1fr_auto] md:items-end">
        <div>
            <label for="property-search-state" class="mb-1 block text-sm font-semibold text-[#0d2723]">1. Estado o ciudad</label>
            <select name="state" id="property-search-state" x-model="state" @change="loadMunicipalities()" class="w-full rounded border-[#ded8ca]"><option value="">Todos los estados</option>@foreach($options['states'] as $state)<option value="{{ $state }}">{{ $state }}</option>@endforeach</select>
        </div>
        <div id="property-municipality-field" x-show="state" x-cloak>
            <label for="property-search-municipality" class="mb-1 block text-sm font-semibold text-[#0d2723]">2. Municipio o alcaldía</label>
            <select name="municipality" id="property-search-municipality" x-model="municipality" @change="clearNeighborhood()" :disabled="!state || loadingMunicipalities" class="w-full rounded border-[#ded8ca] disabled:bg-[#f5f2eb]"><option value="">Selecciona una opción</option><template x-for="item in municipalities" :key="item"><option x-text="item" :value="item"></option></template></select>
        </div>
        <div class="flex gap-2">
            <button class="flex-1 rounded bg-[#0d2723] px-5 py-3 font-semibold text-white">Buscar</button>
            <a class="rounded border border-[#ded8ca] px-4 py-3 text-[#0d2723]" href="{{ $clearUrl ?? route('properties.index') }}">Limpiar</a>
        </div>
    </div>

    <div id="property-neighborhood-field" x-show="municipality" x-cloak class="relative mt-4 max-w-md">
        <label for="property-search-neighborhood" class="mb-1 block text-sm font-semibold text-[#0d2723]">3. Colonia <span class="font-normal text-[#687773]">(opcional)</span></label>
        <input name="neighborhood" id="property-search-neighborhood" x-model="settlementQuery" @input.debounce.300ms="searchSettlements()" placeholder="Escribe al menos 2 letras" class="w-full rounded border-[#ded8ca]" :disabled="!municipality" autocomplete="off">
        <div x-show="settlements.length" class="absolute z-10 mt-1 max-h-56 w-full overflow-auto rounded border border-[#d8ccb8] bg-white shadow" x-cloak>
            <template x-for="settlement in settlements" :key="settlement.id || settlement.name">
                <button type="button" class="block w-full px-3 py-2 text-left text-sm hover:bg-[#f5f2eb]" @click="selectSettlement(settlement)">
                    <span x-text="settlement.name"></span><span class="text-[#687773]" x-text="settlement.postal_code ? ' · CP '+settlement.postal_code : ''"></span>
                </button>
            </template>
        </div>
        <p x-show="loadingSettlements" class="mt-1 text-xs text-[#687773]" x-cloak>Buscando colonias...</p>
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
