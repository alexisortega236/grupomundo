@props(['number', 'title', 'text'])
<div class="rounded-2xl bg-white p-7 shadow-sm ring-1 ring-slate-100">
    <span class="rounded-full bg-[#f4ead5] px-4 py-2 text-xs font-extrabold text-[#c59d47]">{{ $number }}</span>
    <h3 class="mt-6 text-xl font-extrabold text-[#082142]">{{ $title }}</h3>
    <p class="mt-3 text-slate-600">{{ $text }}</p>
</div>
