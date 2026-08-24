<x-admin-layout title="Valuaciones">
    <div class="mb-5 flex justify-end">
        <a class="rounded bg-[#0d2723] px-4 py-2 text-sm font-bold text-white" href="{{ route('admin.valuations.create') }}">Nueva valuación</a>
    </div>
    <div class="overflow-hidden rounded-lg bg-white shadow-sm">
        <table class="w-full text-left text-sm">
            <thead class="bg-[#efe9dc] text-[#0d2723]">
                <tr><th class="p-3">Fecha</th><th class="p-3">Tipo de propiedad</th><th class="p-3">Estado</th><th class="p-3">Valor estimado</th><th class="p-3"></th></tr>
            </thead>
            <tbody>
                @forelse($valuations as $valuation)
                    <tr class="border-t border-[#eee6d8]">
                        <td class="p-3">{{ $valuation->created_at->format('d/m/Y H:i') }}</td>
                        <td class="p-3">{{ \App\Enums\AvmPropertyType::tryFrom($valuation->property->property_type)?->label() ?? $valuation->property->property_type }}</td>
                        <td class="p-3">{{ $valuation->status->label() }}</td>
                        <td class="p-3">{{ $valuation->estimated_value ? '$'.number_format((float) $valuation->estimated_value, 2).' MXN' : 'Pendiente' }}</td>
                        <td class="p-3 text-right"><a class="font-bold text-[#b89752]" href="{{ route('admin.valuations.show', $valuation) }}">Ver</a></td>
                    </tr>
                @empty
                    <tr><td class="p-6 text-center text-[#687773]" colspan="5">Todavía no hay valuaciones.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $valuations->links() }}</div>
</x-admin-layout>
