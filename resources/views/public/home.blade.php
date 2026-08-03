<x-public-layout title="Grupo Mundo Patrimonial" description="Encuentra propiedades en venta y renta con asesoria patrimonial.">
    <section class="bg-[#0d2723] text-white">
        <div class="mx-auto grid max-w-7xl gap-10 px-4 py-16 sm:px-6 lg:grid-cols-[1.05fr_.95fr] lg:px-8 lg:py-24">
            <div>
                <p class="text-xs font-bold uppercase tracking-[.35em] text-[#d5b673]">Real estate · inversion · patrimonio</p>
                <h1 class="mt-6 font-serif text-5xl leading-tight sm:text-7xl">Encuentra el espacio ideal para tu siguiente etapa.</h1>
                <p class="mt-6 max-w-2xl text-lg text-white/75">Seleccion curada de residencias, departamentos, oficinas, locales y terrenos para vivir, operar e invertir con vision de largo plazo.</p>
                <form action="{{ route('properties.index') }}" class="mt-10 grid gap-4 rounded-lg bg-white p-4 text-[#0d2723] shadow-2xl md:grid-cols-5">
                    <select name="operation_type" class="rounded border-[#ded8ca]"><option value="">Venta y renta</option><option value="sale">Venta</option><option value="rent">Renta</option></select>
                    <select name="property_type" class="rounded border-[#ded8ca]"><option value="">Tipo de inmueble</option>@foreach($propertyTypes as $type)<option>{{ $type }}</option>@endforeach</select>
                    <input name="keyword" class="rounded border-[#ded8ca]" placeholder="Zona o palabra clave">
                    <select name="sort" class="rounded border-[#ded8ca]"><option value="recent">Mas recientes</option><option value="featured">Destacadas</option></select>
                    <button class="rounded bg-[#0d2723] px-5 py-3 text-sm font-bold uppercase tracking-[.16em] text-white">Buscar</button>
                </form>
            </div>
            <div class="relative min-h-[360px] overflow-hidden rounded-lg">
                <img class="h-full w-full object-cover" src="https://images.unsplash.com/photo-1600607687920-4e2a09cf159d?auto=format&fit=crop&w=1200&q=80" alt="Interior residencial elegante">
                <div class="absolute inset-0 bg-gradient-to-t from-[#0d2723]/45"></div>
            </div>
        </div>
    </section>
    <section class="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
        <div class="flex flex-col justify-between gap-6 md:flex-row md:items-end">
            <div><p class="text-xs font-bold uppercase tracking-[.35em] text-[#b89752]">Portafolio integral</p><h2 class="mt-4 font-serif text-5xl">Propiedades destacadas</h2></div>
            <div class="flex flex-wrap gap-3"><a class="rounded-full border px-4 py-2" href="{{ route('properties.index', ['operation_type' => 'sale']) }}">Venta</a><a class="rounded-full border px-4 py-2" href="{{ route('properties.index', ['operation_type' => 'rent']) }}">Renta</a><a class="rounded-full border px-4 py-2" href="{{ route('properties.index', ['sort' => 'featured']) }}">Destacadas</a></div>
        </div>
        <div class="mt-10 grid gap-6 md:grid-cols-2 lg:grid-cols-3">@forelse($featuredProperties as $property)<x-property-card :property="$property" />@empty<x-empty-state message="Pronto publicaremos nuevas propiedades destacadas." />@endforelse</div>
    </section>
    <section class="bg-[#efe9dc] py-16">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <p class="text-xs font-bold uppercase tracking-[.35em] text-[#b89752]">Mas que una inmobiliaria</p>
            <h2 class="mt-4 max-w-3xl font-serif text-5xl">Acompañamiento para proteger cada decision.</h2>
            <div class="mt-10 grid gap-6 md:grid-cols-2 lg:grid-cols-4">
                <x-service-card number="01" title="Compra y venta" text="Acompanamiento comercial y documental durante todo el proceso." />
                <x-service-card number="02" title="Rentas" text="Promocion, filtrado de prospectos y cierre profesional." />
                <x-service-card number="03" title="Administracion" text="Gestion de cobranza, mantenimiento y atencion a inquilinos." />
                <x-service-card number="04" title="Proteccion patrimonial" text="Polizas juridicas, seguros y soluciones complementarias." />
            </div>
        </div>
    </section>
    <section class="bg-[#123f75] px-4 py-16 text-center text-white"><h2 class="font-serif text-4xl">Convierte tu siguiente movimiento inmobiliario en una decision patrimonial.</h2><a class="mt-6 inline-block rounded-full bg-[#d5b673] px-6 py-3 font-bold text-[#0d2723]" href="https://wa.me/{{ config('company.whatsapp_number') }}">Hablar con un asesor</a></section>
</x-public-layout>
