@props(['property'])
<article class="group overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm transition hover:-translate-y-1 hover:shadow-xl">
    <div class="relative aspect-[4/3] overflow-hidden bg-slate-100">
        <img src="{{ $property->cover_url }}" alt="{{ $property->title }}" class="h-full w-full object-cover">
        <span class="absolute left-4 top-4 z-10 rounded-full bg-[#082142] px-4 py-2 text-xs font-extrabold text-white">{{ $property->operation_type->label() }}</span>
        @if($property->is_featured)<span class="absolute right-4 top-4 z-10 rounded-full bg-[#d3aa56] px-3 py-2 text-xs font-bold text-[#082142]">Destacada</span>@endif
        <span class="absolute bottom-4 left-4 z-10 rounded-xl bg-white px-4 py-2 font-extrabold text-[#082142] shadow">{{ '$'.number_format($property->price, 0) }} {{ $property->currency }}{{ $property->operation_type->value === 'rent' ? ' / '.($property->rent_period ?: 'mes') : '' }}</span>
    </div>
    <div class="p-6">
        <h3 class="text-xl font-extrabold text-[#082142]">{{ $property->title }}</h3>
        <p class="mt-2 text-sm text-slate-500">{{ $property->location_label }}</p>
        <div class="mt-4 flex flex-wrap gap-4 text-sm text-slate-600">
            <span>{{ $property->bedrooms ?? 0 }} recámaras</span><span>{{ $property->bathrooms ?? 0 }} baños</span><span>{{ $property->construction_area ? number_format($property->construction_area, 0).' m²' : 'Sup. N/D' }}</span>
        </div>
        <a href="{{ route('properties.show', $property) }}" class="mt-5 inline-flex font-extrabold text-[#c59d47]">Ver propiedad →</a>
    </div>
</article>
