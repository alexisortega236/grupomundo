<x-public-layout :title="$property->title.' | Grupo Mundo Patrimonial'" :description="$property->short_description ?: Str::limit(strip_tags($property->description), 150)">
@php($wa = 'https://wa.me/'.config('company.whatsapp_number').'?text='.rawurlencode('Hola, me interesa la propiedad: '.$property->title.'. Me gustaria recibir mas informacion. '.url()->current()))
    <section class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
        <div class="grid gap-8 lg:grid-cols-[1.1fr_.9fr]">
            <div>
                <div class="flex h-[520px] w-full items-center justify-center overflow-hidden rounded-lg bg-white">
                    <img class="max-h-full max-w-full object-contain" src="{{ $property->coverUrl() }}" alt="{{ $property->title }}">
                </div>
                <div class="mt-4 grid grid-cols-4 gap-3">@foreach($property->images as $image)<div class="flex h-24 items-center justify-center rounded bg-white"><img class="max-h-full max-w-full object-contain" src="{{ $image->url('thumb') }}" alt="{{ $image->alt_text ?: $property->title }}"></div>@endforeach</div>
            </div>
            <aside class="rounded-lg bg-white p-7 shadow-sm">
                <p class="text-sm font-bold uppercase tracking-[.18em] text-[#b89752]">{{ $property->operation_type->label() }} · {{ $property->property_type }}</p>
                <h1 class="mt-3 font-serif text-5xl">{{ $property->title }}</h1>
                <p class="mt-4 text-3xl font-semibold">{{ $property->formattedPrice() }}</p>
                <p class="mt-3 text-[#687773]">{{ $property->street }} {{ $property->exterior_number }}, {{ $property->neighborhood }}, {{ $property->city }}, {{ $property->state }}</p>
                <dl class="mt-6 grid grid-cols-2 gap-4 text-sm"><div><dt>Recamaras</dt><dd class="font-bold">{{ $property->bedrooms ?? '-' }}</dd></div><div><dt>Banos</dt><dd class="font-bold">{{ $property->bathrooms ?? '-' }}</dd></div><div><dt>Estacionamientos</dt><dd class="font-bold">{{ $property->parking_spaces ?? '-' }}</dd></div><div><dt>Construccion</dt><dd class="font-bold">{{ $property->construction_area ?? '-' }} m2</dd></div><div><dt>Terreno</dt><dd class="font-bold">{{ $property->land_area ?? '-' }} m2</dd></div><div><dt>Antiguedad</dt><dd class="font-bold">{{ $property->age ?? '-' }}</dd></div></dl>
                <a class="mt-8 block rounded-full bg-[#0d2723] px-6 py-3 text-center font-bold text-white" href="{{ $wa }}" target="_blank" rel="noopener">Solicitar informacion por WhatsApp</a>
            </aside>
        </div>
        <div class="mt-12 grid gap-8 lg:grid-cols-[1fr_380px]">
            <article class="rounded-lg bg-white p-7"><h2 class="font-serif text-3xl">Descripcion</h2><p class="mt-4 whitespace-pre-line text-[#51635f]">{{ $property->description }}</p><h3 class="mt-8 font-serif text-2xl">Amenidades</h3><div class="mt-4 flex flex-wrap gap-2">@foreach($property->amenities as $amenity)<span class="rounded-full bg-[#f4ead3] px-3 py-1 text-sm">{{ $amenity->name }}</span>@endforeach</div><div class="mt-8 rounded bg-[#efe9dc] p-6">Mapa preparado para coordenadas: {{ $property->latitude ?: 'latitud pendiente' }}, {{ $property->longitude ?: 'longitud pendiente' }}</div></article>
            <form method="POST" action="{{ route('contact-requests.store') }}" class="rounded-lg bg-white p-7 shadow-sm">@csrf<input type="hidden" name="property_id" value="{{ $property->id }}"><input class="hidden" name="website" tabindex="-1" autocomplete="off"><h2 class="font-serif text-3xl">Solicitar informacion</h2><div class="mt-5 grid gap-4"><x-form.input label="Nombre" name="name" /><x-form.input label="Telefono" name="phone" /><x-form.input label="Correo electronico" name="email" type="email" /><x-form.textarea label="Mensaje" name="message" :value="'Hola, me interesa la propiedad '.$property->title.'.'" /><button class="rounded bg-[#d5b673] px-5 py-3 font-bold text-[#0d2723]">Enviar solicitud</button></div></form>
        </div>
        <h2 class="mt-14 font-serif text-4xl">Propiedades relacionadas</h2><div class="mt-6 grid gap-6 md:grid-cols-3">@foreach($related as $item)<x-property-card :property="$item" />@endforeach</div>
    </section>
</x-public-layout>
