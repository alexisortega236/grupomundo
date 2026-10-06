@props(['property'])
<article class="group overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-[#e4dccd] transition hover:-translate-y-1 hover:shadow-xl">
    @php
        $cover = $property->coverImage->first();
        $galleryImages = $property->images
            ->sortBy(fn ($image) => [
                $cover && $image->is($cover) ? 0 : 1,
                $image->position ?? PHP_INT_MAX,
            ])
            ->values();
        $gallery = $galleryImages->map(fn ($image) => ['url' => $image->url('card'), 'alt' => $image->alt_text ?: $property->title])->values();
        if ($gallery->isEmpty()) $gallery = collect([['url' => $property->coverUrl('card'), 'alt' => $property->title]]);
    @endphp
    <div x-data="propertyCardGallery(@js($gallery), @js(route('properties.show', $property)))" class="relative" @touchstart="startTouch($event)" @touchmove="moveTouch($event)" @touchend="endTouch($event)">
        <a class="block h-56" href="{{ route('properties.show', $property) }}" @click="openDetail($event)">
            <img class="h-full w-full object-cover" src="{{ $gallery[0]['url'] }}" :src="current.url" :alt="current.alt" loading="lazy" decoding="async">
        </a>
        @if($gallery->count() > 1)
            <button type="button" class="absolute left-3 top-1/2 hidden -translate-y-1/2 rounded-full bg-white/90 px-3 py-1 text-xl text-[#0d2723] shadow md:block" @click.stop.prevent="previous()" aria-label="Fotografía anterior">‹</button>
            <button type="button" class="absolute right-3 top-1/2 hidden -translate-y-1/2 rounded-full bg-white/90 px-3 py-1 text-xl text-[#0d2723] shadow md:block" @click.stop.prevent="next()" aria-label="Siguiente fotografía">›</button>
            <span class="absolute bottom-3 right-3 rounded-full bg-black/60 px-2 py-1 text-xs text-white" x-text="`${index + 1} / ${images.length}`"></span>
        @endif
        <span class="absolute left-4 top-4 rounded-full bg-[#0d2723] px-3 py-1 text-xs font-bold uppercase tracking-[.14em] text-white">{{ $property->operation_type->label() }}</span>
        @if($property->is_featured)<span class="absolute right-4 top-4 rounded-full bg-[#d5b673] px-3 py-1 text-xs font-bold text-[#0d2723]">Destacada</span>@endif
        <span class="absolute bottom-4 left-4 rounded-full bg-white px-4 py-2 font-semibold text-[#0d2723] shadow">{{ $property->formattedPriceWithPeriod() }}</span>
    </div>
    <div class="p-5">
        <h3 class="font-serif text-2xl text-[#0d2723]">{{ $property->title }}</h3>
        @if(filled($property->short_description))<p class="mt-2 line-clamp-2 text-sm leading-relaxed text-[#51635f]">{{ $property->short_description }}</p>@endif
        <p class="mt-2 text-sm text-[#687773]">{{ $property->neighborhood }}, {{ $property->city }}</p>
        <div class="mt-4 flex flex-wrap gap-3 text-sm text-[#687773]">
            <span>{{ $property->bedrooms !== null && $property->bedrooms > 0 ? $property->bedrooms.' rec.' : 'Recámaras por confirmar' }}</span><span>{{ $property->bathroomsLabel() }}</span><span>{{ $property->construction_area ? number_format($property->construction_area).' m²' : 'Sup. por confirmar' }}</span>
        </div>
        <a class="mt-5 inline-flex items-center gap-2 text-sm font-bold uppercase tracking-[.14em] text-[#b89752]" href="{{ route('properties.show', $property) }}">Ver propiedad <span aria-hidden="true">→</span></a>
    </div>
</article>
