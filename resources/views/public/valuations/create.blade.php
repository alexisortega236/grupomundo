@php
    $selectedType = old('property_type', 'house');
    $selectedState = old('state', '');
    $hasOldLocation = old('state') !== null || old('municipality') !== null || old('neighborhood') !== null;
    $selectedMunicipality = old('municipality', '');
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

            <div class="grid gap-6">
                <section class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-[#e4dccd]">
                    <p class="text-xs font-bold uppercase tracking-[.25em] text-[#b89752]">Paso 1</p>
                    <h2 class="mt-2 font-serif text-3xl">Ubicación de la propiedad</h2>
                    <p class="mt-3 max-w-2xl text-sm leading-relaxed text-[#687773]">Usamos la ubicación para analizar el entorno y las características de la zona.</p>

                    <button type="button" id="valuation-geolocate" class="mt-6 rounded-full border border-[#0d2723] px-5 py-3 text-xs font-bold uppercase tracking-[.12em] text-[#0d2723]">Usar mi ubicación</button>
                    <p id="valuation-location-status" class="mt-3 text-sm text-[#51635f]" role="status" aria-live="polite">También puedes capturar la ubicación manualmente.</p>

                    <div class="mt-6 grid gap-4 md:grid-cols-2">
                        <label class="block text-sm font-semibold text-[#0d2723]">Estado
                            <select id="valuation-state" name="state" class="mt-1 w-full rounded border-[#d8ccb8] bg-white px-3 py-2 text-sm focus:border-[#b89752] focus:ring-[#b89752]"><option value="">Selecciona un estado</option><option value="Morelos" @selected($selectedState === 'Morelos')>Morelos</option><option value="Ciudad de México" @selected($selectedState === 'Ciudad de México')>Ciudad de México</option></select>
                        </label>
                        <label class="block text-sm font-semibold text-[#0d2723]"><span id="valuation-municipality-label">{{ $selectedState === 'Ciudad de México' ? 'Alcaldía' : 'Municipio' }}</span>
                            <select id="municipality" name="municipality" class="mt-1 w-full rounded border-[#d8ccb8] bg-white px-3 py-2 text-sm focus:border-[#b89752] focus:ring-[#b89752]">@foreach($municipalities as $key => $text)<option value="{{ $key }}" @selected($selectedMunicipality === $key)>{{ $text }}</option>@endforeach</select>
                        </label>
                        <div class="md:col-span-2">
                            <x-form.input label="Colonia / Fraccionamiento" name="neighborhood" :value="old('neighborhood')" placeholder="Escribe al menos 2 caracteres" autocomplete="off" role="combobox" aria-autocomplete="list" aria-controls="valuation-location-suggestions" aria-expanded="false" />
                            <div id="valuation-location-suggestions" class="mt-2 hidden rounded-md border border-[#d8ccb8] bg-[#fbfaf7] p-3 text-sm text-[#51635f]"></div>
                            <p class="mt-1 text-xs text-[#687773]">La ubicación debe corresponder al catálogo disponible para el municipio o alcaldía seleccionado.</p>
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

                <button type="submit" class="w-full rounded bg-[#0d2723] px-6 py-4 text-sm font-bold uppercase tracking-[.16em] text-white disabled:opacity-70 sm:w-auto" x-bind:disabled="submitting" x-text="submitting ? 'Analizando propiedad...' : 'Calcular valor estimado'">Calcular valor estimado</button>
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
            const state = document.getElementById('valuation-state');
            const municipality = document.getElementById('municipality');
            const municipalityLabel = document.getElementById('valuation-municipality-label');
            const neighborhood = form.querySelector('[name="neighborhood"]');
            const settlementId = document.getElementById('valuation-settlement-id');
            const postalCode = document.getElementById('valuation-postal-code');
            const latitude = document.getElementById('valuation-latitude');
            const longitude = document.getElementById('valuation-longitude');
            const locationSource = document.getElementById('valuation-location-source');
            const locationPrecision = document.getElementById('valuation-location-precision');
            const status = document.getElementById('valuation-location-status');
            const suggestions = document.getElementById('valuation-location-suggestions');
            const geolocate = document.getElementById('valuation-geolocate');
            const submitButton = form.querySelector('button[type="submit"]');
            const initialLocation = @js([
                'state' => $selectedState,
                'municipality' => $selectedMunicipality,
                'neighborhood' => old('neighborhood', ''),
                'postal_settlement_id' => $oldSettlementId,
                'postal_code' => old('postal_code', ''),
            ]);

            let locationPending = false;
            let selectedNeighborhood = '';
            let municipalitiesRequestId = 0;
            let settlementsRequestId = 0;
            let locationOperationId = 0;
            let municipalitiesController = null;
            let settlementsController = null;
            let searchTimer = null;

            const setPending = (pending) => {
                locationPending = pending;
                municipality.disabled = pending;
                submitButton.disabled = pending;
            };

            const clearCoordinates = () => {
                latitude.value = '';
                longitude.value = '';
                locationSource.value = '';
                locationPrecision.value = '';
            };

            const resetSettlement = () => {
                selectedNeighborhood = '';
                neighborhood.value = '';
                settlementId.value = '';
                postalCode.value = '';
                clearCoordinates();
                suggestions.innerHTML = '';
                suggestions.classList.add('hidden');
                neighborhood.setAttribute('aria-expanded', 'false');
            };

            const resetMunicipality = () => {
                municipality.value = '';
                resetSettlement();
            };

            const setStatus = (message) => {
                status.textContent = message;
            };

            const normalize = (value) => (value || '').trim().toLocaleLowerCase('es-MX');

            const loadMunicipalities = async (stateValue, desiredMunicipality = '', { keepPending = false } = {}) => {
                const requestId = ++municipalitiesRequestId;
                municipalitiesController?.abort();
                const controller = new AbortController();
                municipalitiesController = controller;
                municipality.disabled = true;
                municipality.innerHTML = '<option value="">Cargando opciones...</option>';
                municipality.value = '';
                const url = `{{ route('valuation.locations.municipalities') }}?${new URLSearchParams({ state: stateValue })}`;
                if (window.__VALUATION_DEBUG__) console.debug('[valuation] municipalities request', url);

                try {
                    const response = await fetch(url, { signal: controller.signal });
                    if (window.__VALUATION_DEBUG__) console.debug('[valuation] municipalities response', { status: response.status, ok: response.ok });
                    const data = await response.json();
                    if (window.__VALUATION_DEBUG__) console.debug('[valuation] municipalities data', data);
                    if (!response.ok) throw new Error('No pudimos cargar las opciones de ubicación.');
                    if (requestId !== municipalitiesRequestId) return false;
                    if (!Array.isArray(data)) throw new Error('La respuesta de municipios no tiene un formato válido.');

                    municipality.innerHTML = '<option value="">Selecciona una opción</option>';
                    data.forEach((item) => {
                        const option = document.createElement('option');
                        option.value = item;
                        option.textContent = item;
                        municipality.appendChild(option);
                    });

                    const optionExists = Array.from(municipality.options).some((option) => option.value === desiredMunicipality);
                    municipality.value = optionExists ? desiredMunicipality : '';
                    return optionExists || !desiredMunicipality;
                } catch (error) {
                    if (error.name === 'AbortError' || requestId !== municipalitiesRequestId) return false;
                    municipality.innerHTML = '<option value="">Selecciona una opción</option>';
                    municipality.value = '';
                    throw error;
                } finally {
                    if (requestId === municipalitiesRequestId) {
                        municipality.disabled = false;
                        if (status.textContent === 'Cargando municipios...' || status.textContent === 'Cargando opciones...') {
                            setStatus('No fue posible cargar los municipios. Intenta nuevamente.');
                        }
                        if (!keepPending) setPending(false);
                    }
                }
            };

            const validateLocationState = () => {
                const valid = Boolean(state.value && municipality.value && settlementId.value && neighborhood.value.trim());
                const impossible = !municipality.value && (Boolean(settlementId.value) || Boolean(neighborhood.value.trim()));
                if (impossible) resetSettlement();
                if (window.__VALUATION_DEBUG__) {
                    console.debug('valuation location state', {
                        state: state.value,
                        municipality: municipality.value,
                        neighborhood: neighborhood.value,
                        postalSettlementId: settlementId.value,
                        valid,
                        impossible,
                    });
                }
                return valid && !impossible;
            };

            const geocodeSettlement = async () => {
                if (!state.value || !municipality.value || !settlementId.value) return false;
                const requestId = ++settlementsRequestId;
                setPending(true);
                setStatus('Buscando ubicación...');
                try {
                    const params = new URLSearchParams({
                        state: state.value,
                        municipality: municipality.value,
                        postal_settlement_id: settlementId.value,
                    });
                    const response = await fetch(`{{ route('valuation.geocode') }}?${params}`);
                    const data = await response.json();
                    if (!response.ok) throw new Error(data.message || 'No encontramos esa ubicación.');
                    if (requestId !== settlementsRequestId) return false;
                    if (data.location.state !== state.value || data.location.municipality !== municipality.value) {
                        throw new Error('La ubicación no corresponde al municipio seleccionado.');
                    }
                    latitude.value = Number(data.location.latitude).toFixed(7);
                    longitude.value = Number(data.location.longitude).toFixed(7);
                    locationSource.value = data.location.location_source || 'manual_geocode';
                    locationPrecision.value = data.location.location_precision || 'neighborhood';
                    setStatus('Ubicación encontrada.');
                    suggestions.classList.add('hidden');
                    return validateLocationState();
                } catch (error) {
                    if (error.name === 'AbortError') return false;
                    clearCoordinates();
                    setStatus(error.message || 'No pudimos ubicar esa colonia. Captúrala manualmente.');
                    return false;
                } finally {
                    if (requestId === settlementsRequestId) setPending(false);
                }
            };

            const selectSettlement = async (item) => {
                selectedNeighborhood = item.name;
                neighborhood.value = item.name;
                settlementId.value = item.id;
                postalCode.value = item.postal_code || '';
                clearCoordinates();
                suggestions.classList.add('hidden');
                neighborhood.setAttribute('aria-expanded', 'false');
                setStatus('Colonia seleccionada. Verificando ubicación...');
                return geocodeSettlement();
            };

            const searchSettlements = async (query) => {
                const requestId = ++settlementsRequestId;
                settlementsController?.abort();
                settlementsController = new AbortController();
                try {
                    const params = new URLSearchParams({ state: state.value, municipality: municipality.value, q: query });
                    const response = await fetch(`{{ route('valuation.locations.settlements') }}?${params}`, { signal: settlementsController.signal });
                    const data = await response.json();
                    if (!response.ok) throw new Error(data.message || 'No pudimos buscar colonias.');
                    if (requestId !== settlementsRequestId) return;
                    suggestions.innerHTML = '';
                    data.forEach((item) => {
                        const button = document.createElement('button');
                        button.type = 'button';
                        button.className = 'block w-full rounded px-3 py-2 text-left hover:bg-[#efe9dc] focus:bg-[#efe9dc] focus:outline-none';
                        button.innerHTML = `<span class="block font-semibold text-[#0d2723]"></span><span class="block text-xs text-[#687773]"></span>`;
                        button.children[0].textContent = item.name;
                        button.children[1].textContent = [item.type, item.postal_code ? `CP ${item.postal_code}` : ''].filter(Boolean).join(' · ');
                        button.addEventListener('click', () => selectSettlement(item));
                        suggestions.appendChild(button);
                    });
                    suggestions.classList.remove('hidden');
                    neighborhood.setAttribute('aria-expanded', 'true');
                    setStatus(data.length ? 'Selecciona una colonia de la lista.' : 'No encontramos coincidencias.');
                    return data;
                } catch (error) {
                    if (error.name === 'AbortError' || requestId !== settlementsRequestId) return;
                    setStatus(error.message || 'No pudimos buscar colonias.');
                    return [];
                }
            };

            const restoreInitialLocation = async () => {
                const operationId = locationOperationId;
                state.value = initialLocation.state;
                municipalityLabel.textContent = state.value === 'Ciudad de México' ? 'Alcaldía' : 'Municipio';
                if (!initialLocation.municipality) {
                    resetMunicipality();
                    setPending(false);
                    setStatus('Selecciona manualmente un municipio o alcaldía.');
                    return;
                }
                const expectedRequestId = municipalitiesRequestId + 1;
                const municipalityRestored = await loadMunicipalities(initialLocation.state, initialLocation.municipality, { keepPending: true });
                if (operationId !== locationOperationId || expectedRequestId !== municipalitiesRequestId) return;
                if (!municipalityRestored) {
                    resetMunicipality();
                    setPending(false);
                    setStatus('Selecciona manualmente un municipio o alcaldía.');
                    return;
                }
                neighborhood.value = initialLocation.neighborhood || '';
                settlementId.value = initialLocation.postal_settlement_id || '';
                postalCode.value = initialLocation.postal_code || '';
                selectedNeighborhood = settlementId.value ? neighborhood.value.trim() : '';
                if (settlementId.value) validateLocationState();
                setPending(false);
            };

            const handleStateChange = async () => {
                ++locationOperationId;
                resetMunicipality();
                municipalityLabel.textContent = state.value === 'Ciudad de México' ? 'Alcaldía' : 'Municipio';
                setStatus('Cargando municipios...');
                setPending(true);
                try {
                    const municipalitiesLoaded = await loadMunicipalities(state.value);
                    if (!municipalitiesLoaded) return;
                    setStatus('Selecciona un municipio o alcaldía.');
                } catch (error) {
                    setStatus(error.message || 'No pudimos cargar las opciones de ubicación.');
                }
            };

            municipality.addEventListener('change', () => {
                ++locationOperationId;
                resetSettlement();
                setStatus('Busca una colonia dentro del municipio seleccionado.');
            });

            state.addEventListener('change', handleStateChange);

            neighborhood.addEventListener('input', () => {
                const query = neighborhood.value.trim();
                if (selectedNeighborhood && query === selectedNeighborhood) return;
                resetSettlement();
                neighborhood.value = query;
                clearTimeout(searchTimer);
                if (query.length < 2 || !state.value || !municipality.value) return;
                setStatus('Buscando colonias...');
                searchTimer = setTimeout(() => searchSettlements(query), 300);
            });

            geolocate.addEventListener('click', async () => {
                if (!navigator.geolocation) {
                    setStatus('Tu navegador no permite detectar la ubicación. Captúrala manualmente.');
                    return;
                }
                const operationId = ++locationOperationId;
                setPending(true);
                resetMunicipality();
                setStatus('Buscando tu ubicación...');
                try {
                    const position = await new Promise((resolve, reject) => navigator.geolocation.getCurrentPosition(resolve, reject, { enableHighAccuracy: true, timeout: 10000 }));
                    const reverseParams = new URLSearchParams({ latitude: position.coords.latitude, longitude: position.coords.longitude });
                    const reverseResponse = await fetch(`{{ route('valuation.reverse-geocode') }}?${reverseParams}`);
                    const reverseData = await reverseResponse.json();
                    if (!reverseResponse.ok) throw new Error(reverseData.message || 'No pudimos identificar esa ubicación.');
                    if (operationId !== locationOperationId) return;
                    const reverse = reverseData.location;
                    state.value = reverse.state || '';
                    municipalityLabel.textContent = state.value === 'Ciudad de México' ? 'Alcaldía' : 'Municipio';
                    const municipalityLoaded = await loadMunicipalities(state.value, reverse.municipality || '', { keepPending: true });
                    if (!municipalityLoaded) return;
                    if (!municipalityLoaded || municipality.value !== reverse.municipality) throw new Error('No pudimos asociar la ubicación a un municipio disponible. Captúrala manualmente.');
                    const query = (reverse.neighborhood || '').trim();
                    if (query.length < 2) throw new Error('No pudimos identificar una colonia del catálogo. Captúrala manualmente.');
                    const settlements = await searchSettlements(query);
                    if (operationId !== locationOperationId) return;
                    const exact = settlements.find((item) => normalize(item.name) === normalize(query))
                        || settlements.find((item) => reverse.postal_code && item.postal_code === reverse.postal_code);
                    if (!exact) throw new Error('No pudimos asociar la colonia al catálogo SEPOMEX. Captúrala manualmente.');
                    await selectSettlement(exact);
                } catch (error) {
                    resetMunicipality();
                    clearCoordinates();
                    setStatus(error.message || 'No pudimos identificar tu ubicación. Captúrala manualmente.');
                } finally {
                    if (operationId === locationOperationId) setPending(false);
                }
            });

            form.addEventListener('submit', (event) => {
                if (locationPending || !validateLocationState()) {
                    event.preventDefault();
                    setStatus(locationPending ? 'Espera a que terminemos de resolver la ubicación.' : 'Selecciona estado, municipio y una colonia válida.');
                }
            });

            document.addEventListener('click', (event) => {
                if (!suggestions.contains(event.target) && event.target !== neighborhood) {
                    suggestions.classList.add('hidden');
                    neighborhood.setAttribute('aria-expanded', 'false');
                }
            });

            setPending(true);
            restoreInitialLocation().catch(() => {
                resetMunicipality();
                setPending(false);
                setStatus('Selecciona manualmente el municipio y la colonia.');
            });
        });
    </script>
</x-public-layout>
