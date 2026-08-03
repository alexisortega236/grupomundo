@props(['label', 'name', 'value' => null])
<label class="block text-sm font-semibold text-[#0d2723]">{{ $label }}
    <textarea name="{{ $name }}" rows="5" {{ $attributes->merge(['class' => 'mt-1 w-full rounded border-[#d8ccb8] bg-white px-3 py-2 text-sm focus:border-[#b89752] focus:ring-[#b89752]']) }}>{{ old($name, $value) }}</textarea>
</label>
@error($name)<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
