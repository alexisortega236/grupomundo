@props(['label', 'name', 'options' => [], 'value' => null])
<label class="block text-sm font-semibold text-[#0d2723]">{{ $label }}
    <select name="{{ $name }}" {{ $attributes->merge(['class' => 'mt-1 w-full rounded border-[#d8ccb8] bg-white px-3 py-2 text-sm focus:border-[#b89752] focus:ring-[#b89752]']) }}>
        {{ $slot }}
        @foreach($options as $key => $text)<option value="{{ $key }}" @selected(old($name, $value) == $key)>{{ $text }}</option>@endforeach
    </select>
</label>
@error($name)<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
