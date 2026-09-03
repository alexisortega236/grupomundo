<x-public-layout :title="$property->title.' | Grupo Mundo Patrimonial'" :description="$property->short_description ?: Str::limit(strip_tags($property->description), 150)">
@php($wa = 'https://wa.me/'.config('company.whatsapp_number').'?text='.rawurlencode('Hola, me interesa la propiedad: '.$property->title.'. Me gustaria recibir mas informacion. '.url()->current()))
@php($coverImage = $property->coverImage->first() ?: $property->images->first())
    <section class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
        <div class="grid gap-8 lg:grid-cols-[1.1fr_.9fr]">
            <div x-data="{ mainImage: @js($property->coverUrl('large')), mainAlt: @js($coverImage?->alt_text ?: $property->title) }">
                <div class="aspect-[16/10] w-full overflow-hidden rounded-lg bg-[#efe9dc]">
                    <img class="h-full w-full object-cover" :src="mainImage" :alt="mainAlt" src="{{ $property->coverUrl('large') }}">
                </div>
                <div class="mt-4 grid grid-cols-4 gap-3 sm:grid-cols-5">@foreach($property->images as $image)<button type="button" class="aspect-[4/3] overflow-hidden rounded bg-white ring-1 ring-[#e4dccd] transition hover:ring-[#b89752]" @click="mainImage = @js($image->url('large')); mainAlt = @js($image->alt_text ?: $property->title)"><img class="h-full w-full object-cover" src="{{ $image->url('thumb') }}" alt="{{ $image->alt_text ?: $property->title }}"></button>@endforeach</div><div class="mt-6"><h2 class="font-serif text-2xl">Videos</h2><div class="mt-3 grid gap-4 sm:grid-cols-2">@foreach($property->videos as $video)<video class="w-full rounded-lg bg-black" controls preload="metadata" src="{{ $video->url() }}"></video>@endforeach</div></div>
            </div>
            <aside class="rounded-lg bg-white p-7 shadow-sm">
                <p class="text-sm font-bold uppercase tracking-[.18em] text-[#b89752]">{{ $property->operation_type->label() }} · {{ \App\Enums\AvmPropertyType::labelFor($property->property_type) }}</p>
                <h1 class="mt-3 font-serif text-3xl sm:text-4xl lg:text-5xl">{{ $property->title }}</h1>
                <p class="mt-4 text-3xl font-semibold">{{ $property->formattedPriceWithPeriod() }}</p>
                @if($address = $property->displayAddress())
                    <p class="mt-3 text-[#687773]">{{ $address }}</p>
                @endif
                <dl class="mt-6 grid grid-cols-2 gap-4 text-sm"><div><dt>Recámaras</dt><dd class="font-bold">{{ $property->bedrooms !== null ? $property->bedrooms : 'Por confirmar' }}</dd></div><div><dt>Baños</dt><dd class="font-bold">{{ $property->bathrooms !== null ? $property->bathrooms : 'Por confirmar' }}</dd></div><div><dt>Estacionamientos</dt><dd class="font-bold">{{ $property->parking_spaces !== null ? $property->parking_spaces : 'Por confirmar' }}</dd></div><div><dt>Construcción</dt><dd class="font-bold">{{ $property->construction_area !== null ? $property->construction_area.' m²' : 'Por confirmar' }}</dd></div><div><dt>Terreno</dt><dd class="font-bold">{{ $property->land_area !== null ? $property->land_area.' m²' : 'Por confirmar' }}</dd></div><div><dt>Antigüedad</dt><dd class="font-bold">{{ $property->age !== null ? $property->age.' años' : 'Por confirmar' }}</dd></div></dl>
                <a class="mt-8 block rounded-full bg-[#0d2723] px-6 py-3 text-center font-bold text-white" href="{{ $wa }}" target="_blank" rel="noopener">Hablar por WhatsApp</a>
            </aside>
        </div>
        <div class="mt-12 grid gap-8 lg:grid-cols-[1fr_380px]">
            <article class="rounded-lg bg-white p-7"><h2 class="font-serif text-3xl">Descripción</h2><p class="mt-4 whitespace-pre-line text-[#51635f]">{{ $property->description }}</p><h3 class="mt-8 font-serif text-2xl">Amenidades</h3><div class="mt-4 flex flex-wrap gap-2">@foreach($property->amenities as $amenity)<span class="rounded-full bg-[#f4ead3] px-3 py-1 text-sm">{{ $amenity->name }}</span>@endforeach</div></article>
            <form method="POST" action="{{ route('contact-requests.store') }}" class="rounded-lg bg-white p-7 shadow-sm">@csrf<input type="hidden" name="property_id" value="{{ $property->id }}"><input type="hidden" name="contact_form_token" value="{{ $contactFormToken }}"><div class="absolute -left-[10000px] h-px w-px overflow-hidden" aria-hidden="true"><label for="property-website">Sitio web</label><input id="property-website" name="website" tabindex="-1" autocomplete="off"></div><p class="text-xs font-bold uppercase tracking-[.2em] text-[#b89752]">Otra forma de contacto</p><h2 class="mt-2 font-serif text-3xl">¿Prefieres que te contactemos?</h2><p class="mt-3 text-sm text-[#687773]">Déjanos tu número y un asesor se pondrá en contacto contigo.</p><div class="mt-5 grid gap-4"><x-form.input label="Nombre" name="name" /><x-form.input label="Teléfono / WhatsApp" name="phone" /><div class="cf-turnstile" data-sitekey="{{ config('services.turnstile.site_key') }}"></div><button class="rounded bg-[#d5b673] px-5 py-3 font-bold text-[#0d2723]">Quiero que me contacten</button></div></form>
        </div>
        @if($related->isNotEmpty())<h2 class="mt-14 font-serif text-4xl">Propiedades relacionadas</h2><div class="mt-6 grid gap-6 md:grid-cols-3">@foreach($related as $item)<x-property-card :property="$item" />@endforeach</div>@endif
    </section>
</x-public-layout>
@if(config('services.turnstile.site_key'))<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>@endif
