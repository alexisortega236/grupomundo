@if (session('status'))
    <div class="mb-5 rounded border border-[#d5b673]/40 bg-[#fff8e6] px-4 py-3 text-sm text-[#6f5520]">{{ session('status') }}</div>
@endif
@if ($errors->any())
    <div class="mb-5 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">Revisa los campos marcados.</div>
@endif
