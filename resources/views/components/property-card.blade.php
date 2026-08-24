@props(['property'])
<article class="group overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-[#e4dccd] transition hover:-translate-y-1 hover:shadow-xl">
    <div class="relative">
        <img class="h-56 w-full object-cover" src="{{ $property->coverUrl('card') }}" alt="{{ $property->title }}">
        <span class="absolute left-4 top-4 rounded-full bg-[#0d2723] px-3 py-1 text-xs font-bold uppercase tracking-[.14em] text-white">{{ $property->operation_type->label() }}</span>
        @if($property->is_featured)<span class="absolute right-4 top-4 rounded-full bg-[#d5b673] px-3 py-1 text-xs font-bold text-[#0d2723]">Destacada</span>@endif
        <span class="absolute bottom-4 left-4 rounded-full bg-white px-4 py-2 font-semibold text-[#0d2723] shadow">{{ $property->formattedPriceWithPeriod() }}</span>
    </div>
    <div class="p-5">
        <h3 class="font-serif text-2xl text-[#0d2723]">{{ $property->title }}</h3>
        <p class="mt-2 text-sm text-[#687773]">{{ $property->neighborhood }}, {{ $property->city }}</p>
        <div class="mt-4 flex flex-wrap gap-3 text-sm text-[#687773]">
            <span>{{ $property->bedrooms !== null ? $property->bedrooms.' rec.' : 'Recámaras por confirmar' }}</span><span>{{ $property->bathrooms !== null ? $property->bathrooms.' baños' : 'Baños por confirmar' }}</span><span>{{ $property->construction_area ? number_format($property->construction_area).' m²' : 'Sup. por confirmar' }}</span>
        </div>
        <a class="mt-5 inline-block text-sm font-bold uppercase tracking-[.14em] text-[#b89752]" href="{{ route('properties.show', $property) }}">Ver propiedad</a>
    </div>
</article>
