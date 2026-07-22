<x-public-layout title="Grupo Mundo Patrimonial" description="Encuentra el espacio ideal para tu siguiente etapa.">
    <section class="bg-[#061a34] text-white">
        <div class="mx-auto grid max-w-7xl gap-10 px-5 py-20 lg:grid-cols-[1.08fr_.92fr] lg:px-8">
            <div>
                <p class="text-sm font-extrabold uppercase tracking-[.22em] text-[#d3aa56]">Bienes raíces · Protección · Patrimonio</p>
                <h1 class="mt-6 max-w-3xl text-5xl font-black leading-tight md:text-6xl">Encuentra el espacio ideal para tu siguiente etapa.</h1>
                <p class="mt-6 max-w-2xl text-lg leading-8 text-slate-200">Propiedades seleccionadas en las mejores zonas, acompañamiento profesional y soluciones integrales para comprar, vender o rentar con confianza.</p>
                <form action="{{ route('properties.index') }}" class="mt-10 grid gap-3 rounded-2xl bg-white p-4 text-slate-800 shadow-2xl md:grid-cols-4">
                    <label class="grid gap-1 text-xs text-slate-500">Operación<select name="operation_type" class="rounded-xl border-slate-100"><option value="">Venta y renta</option><option value="sale">Venta</option><option value="rent">Renta</option></select></label>
                    <label class="grid gap-1 text-xs text-slate-500">Tipo<select name="property_type" class="rounded-xl border-slate-100"><option value="">Todos</option>@foreach($types as $type)<option>{{ $type }}</option>@endforeach</select></label>
                    <label class="grid gap-1 text-xs text-slate-500">Zona<input name="keyword" class="rounded-xl border-slate-100" placeholder="Ej. Del Valle"></label>
                    <button class="rounded-full bg-[#d3aa56] px-5 py-3 font-extrabold text-[#082142]">Buscar propiedades</button>
                </form>
            </div>
            <div class="relative min-h-80 rounded-[2rem] border border-white/10 bg-white/10 p-6 shadow-2xl">
                <div class="absolute inset-10 rounded-full bg-white/5"></div>
                <img src="{{ asset('images/property-placeholder.svg') }}" alt="Ilustración inmobiliaria" class="relative h-full w-full rounded-3xl object-cover">
            </div>
        </div>
    </section>
    <section class="mx-auto max-w-7xl px-5 py-20 lg:px-8">
        <div class="grid gap-6 md:grid-cols-2 md:items-end">
            <div><p class="text-sm font-extrabold uppercase tracking-[.22em] text-[#c59d47]">Propiedades destacadas</p><h2 class="mt-4 text-4xl font-black text-[#082142] md:text-5xl">Opciones seleccionadas para ti</h2></div>
            <p class="text-slate-500">Un catálogo profesional conectado a base de datos, preparado para crecer con nuevas zonas, filtros e imágenes reales.</p>
        </div>
        <div class="mt-8 flex flex-wrap gap-3">
            <a class="rounded-full bg-[#082142] px-5 py-2 text-sm font-bold text-white" href="{{ route('properties.index') }}">Todas</a>
            <a class="rounded-full border px-5 py-2 text-sm font-bold" href="{{ route('properties.index', ['operation_type'=>'sale']) }}">En venta</a>
            <a class="rounded-full border px-5 py-2 text-sm font-bold" href="{{ route('properties.index', ['operation_type'=>'rent']) }}">En renta</a>
            <a class="rounded-full border px-5 py-2 text-sm font-bold" href="{{ route('properties.index', ['property_type'=>'Departamento']) }}">Departamentos</a>
            <a class="rounded-full border px-5 py-2 text-sm font-bold" href="{{ route('properties.index', ['property_type'=>'Casa']) }}">Casas</a>
        </div>
        <div class="mt-8 grid gap-8 md:grid-cols-2 lg:grid-cols-3">
            @forelse($featuredProperties as $property)<x-property-card :property="$property" />@empty<x-empty-state title="Aún no hay propiedades destacadas" />@endforelse
        </div>
    </section>
    @include('public.partials.services')
    <section class="bg-[#082142] px-5 py-16 text-white"><div class="mx-auto flex max-w-7xl flex-col gap-6 md:flex-row md:items-center md:justify-between"><h2 class="max-w-2xl text-3xl font-black">Hablemos de tu siguiente inversión patrimonial.</h2><a href="{{ 'https://wa.me/'.config('company.whatsapp_number') }}" class="rounded-full bg-[#d3aa56] px-7 py-4 text-center font-extrabold text-[#082142]">Hablar con un asesor</a></div></section>
</x-public-layout>
