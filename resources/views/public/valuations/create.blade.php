@php
    $selectedType = old('property_type', 'house');
    $selectedMunicipality = old('municipality', 'Cuautla');
    $oldLatitude = old('latitude');
    $oldLongitude = old('longitude');
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
            <input type="hidden" name="state" value="Morelos">

            <div class="grid gap-6">
                <section class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-[#e4dccd]">
                    <p class="text-xs font-bold uppercase tracking-[.25em] text-[#b89752]">Paso 1</p>
                    <h2 class="mt-2 font-serif text-3xl">Ubicación de la propiedad</h2>
                    <p class="mt-3 max-w-2xl text-sm leading-relaxed text-[#687773]">Usamos la ubicación para analizar el entorno y las características de la zona.</p>

                    <button type="button" id="valuation-geolocate" class="mt-6 rounded-full border border-[#0d2723] px-5 py-3 text-xs font-bold uppercase tracking-[.12em] text-[#0d2723]">Usar mi ubicación</button>
                    <p id="valuation-location-status" class="mt-3 text-sm text-[#51635f]" role="status" aria-live="polite">También puedes capturar la ubicación manualmente.</p>

                    <div class="mt-6 grid gap-4 md:grid-cols-2">
                        <x-form.select
                            label="Estado"
                            name="state_display"
                            :options="['Morelos' => 'Morelos']"
                            value="Morelos"
                            disabled
                        />
                        <x-form.select
                            label="Municipio"
                            name="municipality"
                            :options="$municipalities"
                            :value="$selectedMunicipality"
                        />
                        <div class="md:col-span-2">
                            <x-form.input label="Colonia / Fraccionamiento" name="neighborhood" :value="old('neighborhood')" placeholder="Ej. Centro, Reforma, Oaxtepec" autocomplete="off" />
                            <div id="valuation-location-suggestions" class="mt-2 hidden rounded-md border border-[#d8ccb8] bg-[#fbfaf7] p-3 text-sm text-[#51635f]"></div>
                            <p class="mt-1 text-xs text-[#687773]">Escribe una colonia o fraccionamiento para encontrar una ubicación aproximada.</p>
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
            const municipality = document.querySelector('[name="municipality"]');
            const neighborhood = document.querySelector('[name="neighborhood"]');
            const latInput = document.getElementById('valuation-latitude');
            const lngInput = document.getElementById('valuation-longitude');
            const sourceInput = document.getElementById('valuation-location-source');
            const precisionInput = document.getElementById('valuation-location-precision');
            const status = document.getElementById('valuation-location-status');
            const suggestions = document.getElementById('valuation-location-suggestions');
            const geolocate = document.getElementById('valuation-geolocate');

            const clearCoordinates = () => {
                latInput.value = '';
                lngInput.value = '';
                sourceInput.value = '';
                precisionInput.value = '';
            };

            const applyLocation = (location, message) => {
                latInput.value = Number(location.latitude).toFixed(7);
                lngInput.value = Number(location.longitude).toFixed(7);
                sourceInput.value = location.location_source || 'manual_geocode';
                precisionInput.value = location.location_precision || 'neighborhood';
                if (location.municipality) municipality.value = location.municipality;
                if (location.neighborhood) neighborhood.value = location.neighborhood;
                status.textContent = message;
            };

            const geocodeManualLocation = async () => {
                if (neighborhood.value.trim().length < 3 || !municipality.value) {
                    clearCoordinates();
                    suggestions.classList.add('hidden');
                    return;
                }

                status.textContent = 'Buscando ubicación...';
                const params = new URLSearchParams({
                    state: 'Morelos',
                    municipality: municipality.value,
                    neighborhood: neighborhood.value.trim(),
                });

                try {
                    const response = await fetch(`{{ route('valuation.geocode') }}?${params}`);
                    const data = await response.json();
                    if (!response.ok) throw new Error(data.message || 'No encontramos esa ubicación.');
                    applyLocation(data.location, 'Ubicación encontrada. Puedes corregir los campos si lo necesitas.');
                    suggestions.classList.add('hidden');
                } catch (error) {
                    clearCoordinates();
                    status.textContent = error.message || 'No encontramos esa ubicación. Revisa municipio y colonia.';
                    suggestions.classList.add('hidden');
                }
            };

            let geocodeTimer = null;
            neighborhood.addEventListener('input', () => {
                clearCoordinates();
                clearTimeout(geocodeTimer);
                geocodeTimer = setTimeout(geocodeManualLocation, 650);
            });

            municipality.addEventListener('change', () => {
                clearCoordinates();
                status.textContent = 'Busca nuevamente la colonia dentro del municipio seleccionado.';
            });

            neighborhood.addEventListener('blur', geocodeManualLocation);

            geolocate.addEventListener('click', () => {
                if (!navigator.geolocation) {
                    status.textContent = 'Tu navegador no permite detectar la ubicación. Captúrala manualmente.';
                    return;
                }
                status.textContent = 'Buscando tu ubicación...';
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
        });
    </script>
</x-public-layout>
