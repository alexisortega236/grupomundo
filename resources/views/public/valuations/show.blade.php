@php
    $property = $valuation->property;
    $typeLabel = \App\Enums\AvmPropertyType::tryFrom($property->property_type)?->label() ?? 'Propiedad';
    $considered = array_filter([
        'Tipo' => $typeLabel,
        'Terreno' => $property->land_area_m2 ? number_format((float) $property->land_area_m2).' m²' : null,
        'Construcción' => $property->construction_area_m2 ? number_format((float) $property->construction_area_m2).' m²' : null,
        'Recámaras' => $property->bedrooms,
        'Baños' => $property->bathrooms,
        'Estacionamientos' => $property->parking_spaces,
        'Antigüedad' => $property->property_age_years !== null ? $property->property_age_years.' años' : null,
    ], fn ($value) => $value !== null && $value !== '');
    $location = array_filter([
        'Municipio' => $property->municipality,
        'Colonia / fraccionamiento' => $property->neighborhood,
        'Estado' => $property->state,
    ]);
    $unavailableMessage = match ($publicResult->fallbackReason) {
        'land_not_supported' => 'La valuación automática de terrenos todavía no está disponible. Nuestro equipo puede ayudarte con una valoración especializada.',
        default => 'No pudimos calcular la estimación principal en este momento. Nuestro equipo puede ayudarte con una valoración más detallada.',
    };
@endphp

<x-public-layout title="Resultado del valuador | Grupo Mundo Patrimonial" description="Resultado preliminar del valuador inmobiliario de Grupo Mundo Patrimonial.">
    <section class="bg-[#0d2723] px-4 py-14 text-white sm:px-6 lg:px-8">
        <div class="mx-auto max-w-7xl">
            <p class="text-xs font-bold uppercase tracking-[.35em] text-[#d5b673]">Resultado preliminar</p>
            <h1 class="mt-4 font-serif text-4xl sm:text-5xl">Valor estimado</h1>
            @if($publicResult->available && $publicResult->rangeLow !== null)
                <p class="mt-6 text-5xl font-bold sm:text-6xl">${{ number_format((float) $publicResult->rangeLow, 0) }} {{ $valuation->currency ?: 'MXN' }}</p>
                <p class="mt-4 max-w-2xl text-white/70">Estimación automatizada basada en los datos proporcionados y en el contexto disponible de la ubicación.</p>
            @else
                <p class="mt-6 max-w-2xl rounded-lg bg-white/10 p-5 text-white/85">{{ $unavailableMessage }}</p>
            @endif
        </div>
    </section>

    <section class="mx-auto grid max-w-7xl gap-8 px-4 py-12 sm:px-6 lg:grid-cols-[1fr_360px] lg:px-8">
        <div class="grid gap-6">
            <section class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-[#e4dccd]">
                <h2 class="font-serif text-3xl">Características consideradas</h2>
                <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach($considered as $label => $value)
                        <div class="rounded bg-[#f5f2eb] p-4">
                            <p class="text-sm text-[#687773]">{{ $label }}</p>
                            <p class="mt-1 text-xl font-bold text-[#0d2723]">{{ $value }}</p>
                        </div>
                    @endforeach
                </div>
            </section>

            @if(! empty($location))
                <section class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-[#e4dccd]">
                    <h2 class="font-serif text-3xl">Ubicación analizada</h2>
                    <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach($location as $label => $value)
                            <div class="rounded border border-[#e4dccd] p-4">
                                <p class="text-sm text-[#687773]">{{ $label }}</p>
                                <p class="mt-1 font-bold text-[#0d2723]">{{ $value }}</p>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            <section class="rounded-lg bg-[#efe9dc] p-6">
                <h2 class="font-serif text-2xl">Importante</h2>
                <p class="mt-3 text-sm leading-relaxed text-[#51635f]">Esta estimación es generada automáticamente a partir de las características de la propiedad, su ubicación y datos de mercado disponibles. Es únicamente orientativa y no sustituye un avalúo profesional.</p>
            </section>
        </div>

        <aside class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-[#e4dccd] lg:self-start">
            <h2 class="font-serif text-3xl">¿Quieres una valoración más detallada?</h2>
            <p class="mt-4 text-sm leading-relaxed text-[#51635f]">Nuestro equipo puede ayudarte a analizar tu propiedad y acompañarte en el proceso de venta o inversión.</p>
            <a class="mt-6 block rounded-full bg-[#0d2723] px-6 py-3 text-center text-sm font-bold uppercase tracking-[.14em] text-white" href="{{ $advisorUrl }}" target="_blank" rel="noopener">Hablar con un asesor</a>
            <a class="mt-3 block rounded-full border border-[#0d2723] px-6 py-3 text-center text-sm font-bold uppercase tracking-[.14em] text-[#0d2723]" href="{{ route('valuation.create') }}">Nueva valuación</a>
        </aside>
    </section>
</x-public-layout>
