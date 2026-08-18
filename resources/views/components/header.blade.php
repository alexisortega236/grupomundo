@php($wa = 'https://wa.me/'.config('company.whatsapp_number').'?text='.rawurlencode('Hola, me gustaria hablar con un asesor de Grupo Mundo Patrimonial.'))
<header x-data="{ open: false }" class="sticky top-0 z-40 border-b border-[#e6dfd2] bg-white/95 backdrop-blur">
    <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-4 sm:px-6 lg:px-8">
        <a href="{{ route('home') }}" class="flex items-center gap-3">
            <span class="grid h-10 w-10 place-items-center border border-[#c9a968] font-serif text-lg text-[#c9a968]">M</span>
            <span class="text-xs font-bold uppercase tracking-[.18em] text-[#0d2723]">Grupo Mundo<br><span class="text-[#c9a968]">Patrimonial</span></span>
        </a>
        <button class="rounded p-2 md:hidden" @click="open = !open" aria-label="Abrir menu"><span class="block h-0.5 w-6 bg-[#0d2723]"></span><span class="mt-1.5 block h-0.5 w-6 bg-[#0d2723]"></span><span class="mt-1.5 block h-0.5 w-6 bg-[#0d2723]"></span></button>
        <nav class="hidden items-center gap-8 text-sm font-medium text-[#51635f] md:flex">
            <a href="{{ route('properties.index') }}">Propiedades</a>
            <a href="{{ route('valuation.create') }}">Valuador</a>
            <a href="{{ route('services') }}">Servicios</a>
            <a href="{{ route('about') }}">Nosotros</a>
            <a href="{{ route('contact') }}">Contacto</a>
            <a class="rounded-full border border-[#0d2723] px-5 py-3 text-xs font-bold uppercase tracking-[.16em] text-[#0d2723] hover:bg-[#0d2723] hover:text-white" href="{{ $wa }}" target="_blank" rel="noopener">Hablar con un asesor</a>
        </nav>
    </div>
    <nav x-show="open" x-cloak class="grid gap-2 border-t border-[#e6dfd2] bg-white px-4 pb-4 text-sm md:hidden">
        <a class="py-2" href="{{ route('properties.index') }}">Propiedades</a>
        <a class="py-2" href="{{ route('valuation.create') }}">Valuador</a>
        <a class="py-2" href="{{ route('services') }}">Servicios</a>
        <a class="py-2" href="{{ route('about') }}">Nosotros</a>
        <a class="py-2" href="{{ route('contact') }}">Contacto</a>
        <a class="rounded-full bg-[#0d2723] px-5 py-3 text-center text-xs font-bold uppercase tracking-[.16em] text-white" href="{{ $wa }}">Hablar con un asesor</a>
    </nav>
</header>
