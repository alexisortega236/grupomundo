<x-admin-layout title="Nueva valuación">
    <form method="POST" action="{{ route('admin.valuations.store') }}" class="grid gap-6">
        @csrf
        <section class="rounded-lg bg-white p-6">
            <h2 class="font-serif text-2xl">Datos de la propiedad</h2>
            <div class="mt-4 grid gap-4 md:grid-cols-3">
                <x-form.select label="Tipo de propiedad" name="property_type" :options="$propertyTypes" value="house" />
                <x-form.input label="Superficie de terreno (m²)" name="land_area_m2" type="number" step="0.01" />
                <x-form.input label="Superficie de construcción (m²)" name="construction_area_m2" type="number" step="0.01" />
                <x-form.input label="Recámaras" name="bedrooms" type="number" />
                <x-form.input label="Baños" name="bathrooms" type="number" step="0.5" />
                <x-form.input label="Estacionamientos" name="parking_spaces" type="number" />
                <x-form.input label="Antigüedad" name="property_age_years" type="number" />
            </div>
        </section>
        <section class="rounded-lg bg-white p-6">
            <h2 class="font-serif text-2xl">Ubicación</h2>
            <div class="mt-4 grid gap-4 md:grid-cols-3">
                <x-form.select label="Colonia del modelo legacy" name="legacy_colonia" :options="$colonias" value="COL_13" />
                <x-form.input label="Latitud" name="latitude" type="number" step="0.0000001" required />
                <x-form.input label="Longitud" name="longitude" type="number" step="0.0000001" required />
                <x-form.input label="Código postal" name="postal_code" />
                <x-form.input label="Colonia" name="neighborhood" />
                <x-form.input label="Localidad" name="locality" />
                <x-form.input label="Municipio" name="municipality" />
                <x-form.input label="Estado" name="state" />
            </div>
        </section>
        <button class="rounded bg-[#0d2723] px-6 py-3 font-bold text-white">Crear valuación</button>
    </form>
</x-admin-layout>
