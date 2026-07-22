@php($wa = 'https://wa.me/'.config('company.whatsapp_number').'?text='.urlencode('Hola, quiero hablar con un asesor de Grupo Mundo Patrimonial.'))
<header class="sticky top-0 z-40 border-b border-slate-100 bg-white/95 backdrop-blur">
    <nav x-data="{ open:false }" class="mx-auto flex max-w-7xl items-center justify-between px-5 py-4 lg:px-8">
        <a href="{{ route('home') }}" class="flex items-center gap-3 font-extrabold tracking-wide text-[#082142]">
            <span class="grid h-11 w-11 place-items-center rounded-full border-2 border-[#082142] text-[#d3aa56]">GMP</span>
            <span class="leading-tight">GRUPO MUNDO<br><span class="text-sm font-semibold text-[#d3aa56]">PATRIMONIAL</span></span>
        </a>
        <button class="rounded-md p-2 text-[#082142] lg:hidden" @click="open=!open" aria-label="Abrir menú">
            <span class="block h-0.5 w-6 bg-current"></span><span class="mt-1.5 block h-0.5 w-6 bg-current"></span><span class="mt-1.5 block h-0.5 w-6 bg-current"></span>
        </button>
        <div class="hidden items-center gap-8 text-sm font-medium text-slate-700 lg:flex">
            <a href="{{ route('properties.index') }}">Propiedades</a>
            <a href="{{ route('services') }}">Servicios</a>
            <a href="{{ route('about') }}">Nosotros</a>
            <a href="{{ route('contact') }}">Contacto</a>
            <a href="{{ $wa }}" class="rounded-full border border-[#082142] px-6 py-3 font-bold text-[#082142]">Hablar con un asesor</a>
        </div>
        <div x-show="open" x-cloak class="absolute left-0 right-0 top-full border-b bg-white px-5 py-4 shadow-lg lg:hidden">
            <div class="grid gap-4">
                <a href="{{ route('properties.index') }}">Propiedades</a><a href="{{ route('services') }}">Servicios</a><a href="{{ route('about') }}">Nosotros</a><a href="{{ route('contact') }}">Contacto</a>
                <a href="{{ $wa }}" class="rounded-full bg-[#d3aa56] px-5 py-3 text-center font-bold text-[#082142]">Hablar con un asesor</a>
            </div>
        </div>
    </nav>
</header>
