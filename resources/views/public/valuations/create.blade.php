@php
    $selectedType = old('property_type', 'house');
    $selectedState = old('state', 'Morelos');
    $selectedMunicipality = old('municipality', $selectedState === 'Ciudad de México' ? 'Álvaro Obregón' : 'Cuautla');
    $oldLatitude = old('latitude');
    $oldLongitude = old('longitude');
    $oldSettlementId = old('postal_settlement_id', old('settlement_id'));
@endphp

<x-public-layout title="Valuador | Grupo Mundo Patrimonial" description="Obtén una estimación preliminar del valor de tu propiedad.">
    <section class="bg-[#0d2723] text-white">
        <div class="mx-auto grid max-w-7xl gap-10 px-4 py-14 sm:px-6 lg:grid-cols-[.95fr_1.05fr] lg:px-8 lg:py-20">
            <div>
                <p class="text-xs font-bold uppercase tracking-[.35em] text-[#d5b673]">Valuación preliminar</p>
                <h1 class="mt-5 max-w-3xl font-serif text-4xl leading-tight sm:text-5xl lg:text-6xl">Conoce el valor estimado de tu propiedad</h1>
                <p class="mt-6 max-w-2xl text-lg leading-relaxed text-white/75">Cuéntanos sobre tu propiedad y analizamos automáticamente sus características y el entorno disponible de la ubicación.</p>
            </div>
            <div class="rounded-lg border border-white/10 bg-white/8 p-6">
                <div class="grid gap-4 text-sm sm:grid-cols-3">
                    <div><p class="font-bold text-[#d5b673]">01</p><p>Ubicación</p></div>
                    <div><p class="font-bold text-[#d5b673]">02</p><p>Características</p></div>
                    <div><p class="font-bold text-[#d5b673]">03</p><p>Resultado</p></div>
                </div>
                <p class="mt-6 text-sm leading-relaxed text-white/70">La estimación es orientativa y funciona como una primera referencia para tomar decisiones inmobiliarias con mejor contexto.</p>
            </div>
        </div>
    </section>

    <section class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
        <form
            id="valuation-form"
            method="POST"
            action="{{ route('valuation.store') }}"
            class="grid gap-8 lg:grid-cols-[1fr_360px]"
            x-data="{ propertyType: @js($selectedType), submitting: false }"
            @submit="submitting = true"
        >
            @csrf
            <input id="valuation-latitude" type="hidden" name="latitude" value="{{ $oldLatitude }}">
            <input id="valuation-longitude" type="hidden" name="longitude" value="{{ $oldLongitude }}">
            <input id="valuation-location-source" type="hidden" name="location_source" value="{{ old('location_source') }}">
            <input id="valuation-location-precision" type="hidden" name="location_precision" value="{{ old('location_precision') }}">
            <input id="valuation-settlement-id" type="hidden" name="postal_settlement_id" value="{{ $oldSettlementId }}">
            <input id="valuation-postal-code" type="hidden" name="postal_code" value="{{ old('postal_code') }}">
            <input id="municipality-value" type="hidden" name="municipality" value="{{ old('municipality', $selectedMunicipality) }}">

            <div class="grid gap-6">
                <section class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-[#e4dccd]">
                    <p class="text-xs font-bold uppercase tracking-[.25em] text-[#b89752]">Paso 1</p>
                    <h2 class="mt-2 font-serif text-3xl">Ubicación de la propiedad</h2>
                    <p class="mt-3 max-w-2xl text-sm leading-relaxed text-[#687773]">Usamos la ubicación para analizar el entorno y las características de la zona.</p>

                    <button type="button" id="valuation-geolocate" class="mt-6 rounded-full border border-[#0d2723] px-5 py-3 text-xs font-bold uppercase tracking-[.12em] text-[#0d2723]">Usar mi ubicación</button>
                    <p id="valuation-location-status" class="mt-3 text-sm text-[#51635f]" role="status" aria-live="polite">También puedes capturar la ubicación manualmente.</p>

                    <div class="mt-6 grid gap-4 md:grid-cols-2">
                        <label class="block text-sm font-semibold text-[#0d2723]">Estado
                            <select id="valuation-state" name="state" class="mt-1 w-full rounded border-[#d8ccb8] bg-white px-3 py-2 text-sm focus:border-[#b89752] focus:ring-[#b89752]"><option value="Morelos" @selected($selectedState === 'Morelos')>Morelos</option><option value="Ciudad de México" @selected($selectedState === 'Ciudad de México')>Ciudad de México</option></select>
                        </label>
                        <label class="block text-sm font-semibold text-[#0d2723]"><span id="valuation-municipality-label">{{ $selectedState === 'Ciudad de México' ? 'Alcaldía' : 'Municipio' }}</span>
                            <select id="municipality" class="mt-1 w-full rounded border-[#d8ccb8] bg-white px-3 py-2 text-sm focus:border-[#b89752] focus:ring-[#b89752]">@foreach($municipalities as $key => $text)<option value="{{ $key }}" @selected($selectedMunicipality === $key)>{{ $text }}</option>@endforeach</select>
                        </label>
                        <div class="md:col-span-2">
                            <x-form.input label="Colonia / Fraccionamiento" name="neighborhood" :value="old('neighborhood')" placeholder="Escribe al menos 2 caracteres" autocomplete="off" role="combobox" aria-autocomplete="list" aria-controls="valuation-location-suggestions" aria-expanded="false" />
                            <div id="valuation-location-suggestions" class="mt-2 hidden rounded-md border border-[#d8ccb8] bg-[#fbfaf7] p-3 text-sm text-[#51635f]"></div>
                            <p class="mt-1 text-xs text-[#687773]">Escribe y selecciona una colonia o fraccionamiento real de la lista.</p>
                            @error('postal_settlement_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    @error('latitude')<p class="mt-2 text-xs text-red-600">{{ $message }}</p>@enderror
                    @error('longitude')<p class="mt-2 text-xs text-red-600">{{ $message }}</p>@enderror
                </section>

                <section class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-[#e4dccd]">
                    <p class="text-xs font-bold uppercase tracking-[.25em] text-[#b89752]">Paso 2</p>
                    <h2 class="mt-2 font-serif text-3xl">¿Qué tipo de propiedad deseas valuar?</h2>
                    <div class="mt-6 grid gap-3 md:grid-cols-3">
                        @foreach(['house' => ['Casa', 'Vivienda con terreno propio.'], 'apartment' => ['Departamento', 'Unidad dentro de edificio o condominio.'], 'land' => ['Terreno', 'Lote sin construcción habitacional principal.']] as $value => [$label, $description])
                            <label class="block cursor-pointer">
                                <input class="peer sr-only" type="radio" name="property_type" value="{{ $value }}" x-model="propertyType" @checked($selectedType === $value)>
                                <span class="block h-full rounded-lg border border-[#d8ccb8] bg-[#fbfaf7] p-4 transition peer-checked:border-[#0d2723] peer-checked:bg-[#efe9dc] peer-focus:ring-2 peer-focus:ring-[#b89752]">
                                    <span class="block font-serif text-2xl text-[#0d2723]">{{ $label }}</span>
                                    <span class="mt-2 block text-sm leading-relaxed text-[#687773]">{{ $description }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                    @error('property_type')<p class="mt-2 text-xs text-red-600">{{ $message }}</p>@enderror
                </section>

                <section class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-[#e4dccd]">
                    <p class="text-xs font-bold uppercase tracking-[.25em] text-[#b89752]">Paso 3</p>
                    <h2 class="mt-2 font-serif text-3xl">Características</h2>

                    <div class="mt-6 grid gap-4 md:grid-cols-2">
                        <div x-show="propertyType === 'house' || propertyType === 'land'" x-cloak>
                            <x-form.input label="Superficie de terreno" name="land_area_m2" type="number" step="0.01" min="0" inputmode="decimal" :value="old('land_area_m2')" />
                            <p class="mt-1 text-xs text-[#687773]">Área total del lote. <span class="font-semibold">m²</span></p>
                        </div>

                        <div x-show="propertyType === 'house' || propertyType === 'apartment'" x-cloak>
                            <x-form.input label="Superficie de construcción" name="construction_area_m2" type="number" step="0.01" min="0" inputmode="decimal" :value="old('construction_area_m2')" />
                            <p class="mt-1 text-xs text-[#687773]">Metros cuadrados construidos. <span class="font-semibold">m²</span></p>
                        </div>

                        <div x-show="propertyType === 'house' || propertyType === 'apartment'" x-cloak>
                            <x-form.input label="Recámaras" name="bedrooms" type="number" min="0" inputmode="numeric" :value="old('bedrooms')" />
                        </div>

                        <div x-show="propertyType === 'house' || propertyType === 'apartment'" x-cloak>
                            <x-form.input label="Baños" name="bathrooms" type="number" step="0.5" min="0" inputmode="decimal" :value="old('bathrooms')" />
                        </div>

                        <div x-show="propertyType === 'house' || propertyType === 'apartment'" x-cloak>
                            <x-form.input label="Estacionamientos" name="parking_spaces" type="number" min="0" inputmode="numeric" :value="old('parking_spaces')" />
                        </div>

                        <div x-show="propertyType === 'house' || propertyType === 'apartment'" x-cloak>
                            <x-form.input label="Antigüedad aproximada" name="property_age_years" type="number" min="0" inputmode="numeric" :value="old('property_age_years')" />
                            <p class="mt-1 text-xs text-[#687773]">Años aproximados desde su construcción.</p>
                        </div>
                    </div>
                </section>

                @if($errors->any())
                    <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">Revisa los campos marcados antes de calcular la estimación.</div>
                @endif

                <button class="w-full rounded bg-[#0d2723] px-6 py-4 text-sm font-bold uppercase tracking-[.16em] text-white disabled:opacity-70 sm:w-auto" x-bind:disabled="submitting" x-text="submitting ? 'Analizando propiedad...' : 'Calcular valor estimado'">Calcular valor estimado</button>
                <p class="-mt-3 text-sm text-[#687773]" x-show="submitting" x-cloak>Estamos considerando las características del inmueble y su ubicación.</p>
            </div>

            <aside class="rounded-lg bg-[#efe9dc] p-6 lg:sticky lg:top-28 lg:self-start">
                <p class="text-xs font-bold uppercase tracking-[.25em] text-[#b89752]">Qué recibirás</p>
                <ul class="mt-5 grid gap-4 text-sm text-[#51635f]">
                    <li><span class="font-bold text-[#0d2723]">Valor estimado</span><br>Una referencia automatizada en MXN cuando el valuador principal cuente con una ubicación compatible.</li>
                    <li><span class="font-bold text-[#0d2723]">Ubicación real</span><br>La propiedad se guarda con municipio y colonia o fraccionamiento.</li>
                    <li><span class="font-bold text-[#0d2723]">Acompañamiento</span><br>Opción para conversar con un asesor si quieres una valoración más detallada.</li>
                </ul>
            </aside>
        </form>
    </section>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const form = document.getElementById('valuation-form');
            const municipality = document.getElementById('municipality');
            const municipalityValue = document.getElementById('municipality-value');
            const state = document.querySelector('[name="state"]');
            const municipalityLabel = document.getElementById('valuation-municipality-label');
            const neighborhood = document.querySelector('[name="neighborhood"]');
            const latInput = document.getElementById('valuation-latitude');
            const lngInput = document.getElementById('valuation-longitude');
            const sourceInput = document.getElementById('valuation-location-source');
                const precisionInput = document.getElementById('valuation-location-precision');
            const settlementIdInput = document.getElementById('valuation-settlement-id');
            const postalCodeInput = document.getElementById('valuation-postal-code');
            const status = document.getElementById('valuation-location-status');
            const suggestions = document.getElementById('valuation-location-suggestions');
            const geolocate = document.getElementById('valuation-geolocate');
            let selectedNeighborhood = settlementIdInput.value ? neighborhood.value.trim() : '';

            form.addEventListener('submit', (event) => {
                municipalityValue.value = municipality.value;
                const formData = new FormData(form);

                if (formData.get('municipality') !== municipality.value) {
                    event.preventDefault();
                    status.textContent = 'Selecciona un municipio o alcaldía válido.';
                    return;
                }
            });

            const clearCoordinates = () => {
                latInput.value = '';
                lngInput.value = '';
                sourceInput.value = '';
                precisionInput.value = '';
            };

            const invalidateSettlement = () => {
                selectedNeighborhood = '';
                settlementIdInput.value = '';
                postalCodeInput.value = '';
                neighborhood.setAttribute('aria-expanded', 'false');
            };

            const applyLocation = (location, message, preserveSettlement = false) => {
                latInput.value = Number(location.latitude).toFixed(7);
                lngInput.value = Number(location.longitude).toFixed(7);
                sourceInput.value = location.location_source || 'manual_geocode';
                precisionInput.value = location.location_precision || 'neighborhood';
                if (location.municipality) {
                    municipality.value = location.municipality;
                    municipalityValue.value = municipality.value;
                }
                if (location.neighborhood && !preserveSettlement) {
                    neighborhood.value = location.neighborhood;
                    selectedNeighborhood = location.neighborhood;
                }
                if (location.postal_code && !preserveSettlement) postalCodeInput.value = location.postal_code;
                status.textContent = message;
            };

            const geocodeSettlement = async () => {
                if (!settlementIdInput.value || !municipality.value) {
                    clearCoordinates();
                    return;
                }

                status.textContent = 'Buscando ubicación...';
                const params = new URLSearchParams({
                    state: state.value,
                    municipality: municipality.value,
                    postal_settlement_id: settlementIdInput.value,
                });

                try {
                    const response = await fetch(`{{ route('valuation.geocode') }}?${params}`);
                    const data = await response.json();
                    if (!response.ok) throw new Error(data.message || 'No encontramos esa ubicación.');
                    applyLocation(data.location, 'Ubicación encontrada.', true);
                    suggestions.classList.add('hidden');
                } catch (error) {
                    clearCoordinates();
                    status.textContent = error.message || 'No pudimos ubicar esa colonia. También puedes usar tu ubicación.';
                    suggestions.classList.add('hidden');
                }
            };

            const renderSuggestions = (items) => {
                suggestions.innerHTML = '';
                if (!items.length) {
                    suggestions.textContent = 'No encontramos coincidencias en este municipio.';
                    suggestions.classList.remove('hidden');
                    return;
                }

                items.forEach((item) => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'block w-full rounded px-3 py-2 text-left hover:bg-[#efe9dc] focus:bg-[#efe9dc] focus:outline-none';
                    button.innerHTML = `<span class="block font-semibold text-[#0d2723]"></span><span class="block text-xs text-[#687773]"></span>`;
                    button.children[0].textContent = item.name;
                    button.children[1].textContent = [item.type, item.postal_code ? `CP ${item.postal_code}` : ''].filter(Boolean).join(' · ');
                    button.addEventListener('click', () => {
                        selectedNeighborhood = item.name;
                        neighborhood.value = item.name;
                        settlementIdInput.value = item.id;
                        postalCodeInput.value = item.postal_code || '';
                        clearCoordinates();
                        suggestions.classList.add('hidden');
                        neighborhood.setAttribute('aria-expanded', 'false');
                        status.textContent = 'Colonia seleccionada. Verificando ubicación...';
                        geocodeSettlement();
                    });
                    suggestions.appendChild(button);
                });
                suggestions.classList.remove('hidden');
                neighborhood.setAttribute('aria-expanded', 'true');
            };

            let searchTimer = null;
            neighborhood.addEventListener('input', () => {
                const query = neighborhood.value.trim();
                if (selectedNeighborhood && query === selectedNeighborhood) {
                    return;
                }

                selectedNeighborhood = '';
                clearCoordinates();
                invalidateSettlement();
                clearTimeout(searchTimer);
                if (query.length < 2 || !municipality.value) {
                    suggestions.classList.add('hidden');
                    return;
                }
                status.textContent = 'Buscando colonias...';
                searchTimer = setTimeout(async () => {
                    const params = new URLSearchParams({ state: state.value, municipality: municipality.value, q: query });
                    try {
                        const response = await fetch(`{{ route('valuation.locations.settlements') }}?${params}`);
                        const data = await response.json();
                        if (!response.ok) throw new Error(data.message || 'No pudimos buscar colonias.');
                        renderSuggestions(data);
                        status.textContent = data.length ? 'Selecciona una colonia de la lista.' : 'No encontramos coincidencias.';
                    } catch (error) {
                        suggestions.textContent = error.message || 'No pudimos buscar colonias.';
                        suggestions.classList.remove('hidden');
                        status.textContent = 'Revisa el municipio o utiliza tu ubicación.';
                    }
                }, 300);
            });

            municipality.addEventListener('change', () => {
                municipalityValue.value = municipality.value;
                selectedNeighborhood = '';
                clearCoordinates();
                invalidateSettlement();
                neighborhood.value = '';
                suggestions.classList.add('hidden');
                status.textContent = 'Busca una colonia dentro del municipio seleccionado.';
            });

            state.addEventListener('change', async () => {
                selectedNeighborhood = '';
                clearCoordinates();
                invalidateSettlement();
                neighborhood.value = '';
                municipality.innerHTML = '<option value="">Selecciona una opción</option>';
                municipality.value = '';
                municipalityValue.value = '';
                municipalityLabel.textContent = state.value === 'Ciudad de México' ? 'Alcaldía' : 'Municipio';
                suggestions.classList.add('hidden');
                status.textContent = 'Cargando municipios...';
                try {
                    const params = new URLSearchParams({ state: state.value });
                    const response = await fetch(`{{ route('valuation.locations.municipalities') }}?${params}`);
                    const data = await response.json();
                    if (!response.ok) throw new Error('No pudimos cargar las opciones de ubicación.');
                    data.forEach((item) => {
                        const option = document.createElement('option');
                        option.value = item;
                        option.textContent = item;
                        municipality.appendChild(option);
                    });
                    municipality.value = '';
                    municipalityValue.value = '';
                    status.textContent = 'Selecciona un municipio o alcaldía.';
                } catch (error) {
                    status.textContent = error.message || 'No pudimos cargar las opciones de ubicación.';
                }
            });

            geolocate.addEventListener('click', () => {
                if (!navigator.geolocation) {
                    status.textContent = 'Tu navegador no permite detectar la ubicación. Captúrala manualmente.';
                    return;
                }
                status.textContent = 'Buscando tu ubicación...';
                invalidateSettlement();
                navigator.geolocation.getCurrentPosition((position) => {
                    const params = new URLSearchParams({
                        latitude: position.coords.latitude,
                        longitude: position.coords.longitude,
                    });
                    fetch(`{{ route('valuation.reverse-geocode') }}?${params}`)
                        .then(async (response) => {
                            const data = await response.json();
                            if (!response.ok) throw new Error(data.message || 'No pudimos identificar esa ubicación.');
                            applyLocation(data.location, 'Ubicación detectada. Puedes corregir municipio o colonia.');
                        })
                        .catch((error) => {
                            clearCoordinates();
                            status.textContent = error.message || 'No pudimos identificar tu ubicación. Captúrala manualmente.';
                        });
                }, (error) => {
                    const message = error.code === 1
                        ? 'Permiso de ubicación rechazado. Captura municipio y colonia manualmente.'
                        : error.code === 3
                            ? 'La ubicación tardó demasiado. Captúrala manualmente.'
                            : 'No pudimos obtener tu ubicación. Captúrala manualmente.';
                    status.textContent = message;
                }, { enableHighAccuracy: true, timeout: 10000 });
            });

            document.addEventListener('click', (event) => {
                if (!suggestions.contains(event.target) && event.target !== neighborhood) {
                    suggestions.classList.add('hidden');
                    neighborhood.setAttribute('aria-expanded', 'false');
                }
            });
        });
    </script>
</x-public-layout>
