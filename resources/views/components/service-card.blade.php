@props(['number', 'title', 'text', 'link' => route('services')])
<article {{ $attributes->merge(['class' => 'group rounded-lg border border-[#e4dccd] bg-white p-7 shadow-sm transition hover:-translate-y-1 hover:border-[#c9a968]']) }}>
    <div class="flex items-start justify-between"><span class="font-serif text-2xl text-[#b89752]">{{ $number }}</span><span class="grid h-11 w-11 place-items-center rounded-full bg-[#f4ead3] text-[#b89752]" aria-hidden="true"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 11.5 12 5l8 6.5V20H4v-8.5Z"/><path d="M9 20v-5h6v5M7 9h.01M12 9h.01M17 9h.01"/></svg></span></div>
    <h3 class="mt-8 font-serif text-2xl text-[#0d2723]">{{ $title }}</h3>
    <p class="mt-3 min-h-[4.5rem] text-[#687773]">{{ $text }}</p>
    <a class="mt-6 inline-flex items-center gap-2 text-xs font-bold uppercase tracking-[.16em] text-[#b89752]" href="{{ $link }}">Saber más <span aria-hidden="true">→</span></a>
</article>
