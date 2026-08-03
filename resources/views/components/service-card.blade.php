@props(['number', 'title', 'text'])
<article class="rounded-lg border border-[#e4dccd] bg-white p-7 shadow-sm">
    <span class="rounded-full bg-[#f4ead3] px-4 py-1 font-serif text-lg text-[#b89752]">{{ $number }}</span>
    <h3 class="mt-8 font-serif text-2xl text-[#0d2723]">{{ $title }}</h3>
    <p class="mt-3 text-[#687773]">{{ $text }}</p>
</article>
