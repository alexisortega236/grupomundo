<x-admin-layout :title="$propertyType->exists ? 'Editar tipo de propiedad' : 'Crear tipo de propiedad'">
    <form method="POST" action="{{ $propertyType->exists ? route('admin.property-types.update', $propertyType) : route('admin.property-types.store') }}" class="rounded-lg bg-white p-6">
        @csrf @if($propertyType->exists) @method('PUT') @endif
        <div class="grid gap-4 md:grid-cols-3"><x-form.input label="Nombre" name="name" :value="$propertyType->name" required/><x-form.input label="Orden" name="sort_order" type="number" min="0" :value="$propertyType->sort_order ?: 0" required/><label class="mt-7 flex items-center gap-2"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $propertyType->is_active))> Activo</label></div>
        <p class="mt-4 text-sm text-[#687773]">Desactivar un tipo impide nuevas asignaciones, pero conserva las propiedades que ya lo utilizan.</p>
        <button class="mt-5 rounded bg-[#0d2723] px-5 py-3 text-white">Guardar</button>
    </form>
</x-admin-layout>
