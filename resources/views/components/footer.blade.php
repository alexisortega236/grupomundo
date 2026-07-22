<footer class="bg-[#061a34] text-white">
    <div class="mx-auto grid max-w-7xl gap-8 px-5 py-12 md:grid-cols-3 lg:px-8">
        <div><p class="font-extrabold">Grupo Mundo Patrimonial</p><p class="mt-3 text-sm text-slate-300">Bienes raíces, protección y patrimonio con acompañamiento profesional.</p></div>
        <div><p class="font-bold text-[#d3aa56]">Navegación</p><div class="mt-3 grid gap-2 text-sm text-slate-300"><a href="{{ route('properties.index') }}">Propiedades</a><a href="{{ route('services') }}">Servicios</a><a href="{{ route('about') }}">Nosotros</a><a href="{{ route('contact') }}">Contacto</a></div></div>
        <div><p class="font-bold text-[#d3aa56]">Contacto</p><p class="mt-3 text-sm text-slate-300">{{ config('company.contact_email') }}</p></div>
    </div>
</footer>
