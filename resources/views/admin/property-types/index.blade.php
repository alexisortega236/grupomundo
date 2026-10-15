<x-admin-layout title="Tipos de propiedad">
    <a class="mb-4 inline-block rounded bg-[#d5b673] px-4 py-2" href="{{ route('admin.property-types.create') }}">Crear tipo</a>
    <div class="rounded-lg bg-white p-4">
        @forelse($propertyTypes as $propertyType)
            <div class="flex flex-wrap items-center justify-between gap-3 border-b py-3 last:border-b-0">
                <div><span class="font-semibold">{{ $propertyType->name }}</span><span class="ml-3 text-sm text-[#687773]">{{ $propertyType->properties_count }} propiedades · orden {{ $propertyType->sort_order }}</span></div>
                <div class="flex items-center gap-3"><span class="text-sm {{ $propertyType->is_active ? 'text-green-700' : 'text-gray-500' }}">{{ $propertyType->is_active ? 'Activo' : 'Inactivo' }}</span><a href="{{ route('admin.property-types.edit', $propertyType) }}">Editar</a><form method="POST" action="{{ route('admin.property-types.toggle-active', $propertyType) }}">@csrf @method('PATCH')<button>{{ $propertyType->is_active ? 'Desactivar' : 'Activar' }}</button></form></div>
            </div>
        @empty
            <p class="text-sm text-[#687773]">No hay tipos de propiedad registrados.</p>
        @endforelse
    </div>
    {{ $propertyTypes->links() }}
</x-admin-layout>
