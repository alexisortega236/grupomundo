<x-admin-layout title="Detalle de valuación">
    <div class="grid gap-6 lg:grid-cols-[1fr_360px]">
        <section class="rounded-lg bg-white p-6">
            <p class="text-sm font-bold uppercase tracking-[.18em] text-[#b89752]">Resultado</p>
            <h2 class="mt-2 font-serif text-3xl">{{ $valuation->status->label() }}</h2>
            @if($valuation->status === \App\Enums\ValuationStatus::Completed)
                <p class="mt-4 text-4xl font-bold">${{ number_format((float) $valuation->estimated_value, 2) }} {{ $valuation->currency ?: 'MXN' }}</p>
                @if($valuation->estimated_price_m2)<p class="mt-2 text-[#687773]">${{ number_format((float) $valuation->estimated_price_m2, 2) }} {{ $valuation->currency ?: 'MXN' }} / m²</p>@endif
                @if($valuation->zone_inferred)<p class="mt-3 text-sm text-[#687773]">Zona inferida: <span class="font-bold text-[#0d2723]">{{ ucfirst($valuation->zone_inferred) }}</span></p>@endif
            @elseif($valuation->status === \App\Enums\ValuationStatus::Failed)
                <p class="mt-4 rounded bg-red-50 p-4 text-sm text-red-700">{{ $valuation->error_message ?: 'No fue posible completar la valuación.' }}</p>
            @else
                <p class="mt-4 text-[#687773]">La valuación está en proceso.</p>
            @endif
        </section>
        <aside class="rounded-lg bg-white p-6">
            <h2 class="font-serif text-2xl">Propiedad</h2>
            <dl class="mt-4 grid gap-3 text-sm">
                <div><dt class="text-[#687773]">Tipo de propiedad</dt><dd class="font-bold">{{ \App\Enums\AvmPropertyType::labelFor($valuation->property->property_type) }}</dd></div>
                <div><dt class="text-[#687773]">Ubicación</dt><dd class="font-bold">{{ $valuation->property->neighborhood ?: '-' }}, {{ $valuation->property->municipality ?: '-' }}</dd></div>
                <div><dt class="text-[#687773]">Coordenadas capturadas</dt><dd class="font-bold">{{ $valuation->property->latitude }}, {{ $valuation->property->longitude }}</dd></div>
                <div><dt class="text-[#687773]">Terreno</dt><dd class="font-bold">{{ $valuation->property->land_area_m2 ?? '-' }} m²</dd></div>
                <div><dt class="text-[#687773]">Construcción</dt><dd class="font-bold">{{ $valuation->property->construction_area_m2 ?? '-' }} m²</dd></div>
                <div><dt class="text-[#687773]">Modelo</dt><dd class="font-bold">{{ $valuation->modelVersion?->version ?? 'Sin modelo registrado' }}</dd></div>
            </dl>
        </aside>
    </div>
    @php($v2Prediction = $valuation->modelPredictions->firstWhere('model_name', 'avm_residential_v2'))
    <section class="mt-6 grid gap-6 lg:grid-cols-3">
        <div class="rounded-lg bg-white p-6">
            <h2 class="font-serif text-2xl">Ubicación capturada</h2>
            <dl class="mt-4 grid gap-3 text-sm">
                <div><dt class="text-[#687773]">Municipio</dt><dd class="font-bold">{{ $valuation->property->municipality ?: '-' }}</dd></div>
                <div><dt class="text-[#687773]">Colonia / fraccionamiento</dt><dd class="font-bold">{{ $valuation->property->neighborhood ?: '-' }}</dd></div>
                <div><dt class="text-[#687773]">Latitud / longitud</dt><dd class="font-bold">{{ $valuation->property->latitude ?: '-' }}, {{ $valuation->property->longitude ?: '-' }}</dd></div>
            </dl>
        </div>
        <div class="rounded-lg bg-white p-6">
            <h2 class="font-serif text-2xl">Ubicación espacial AVM v2</h2>
            @php($v2Location = $v2Prediction?->response_json['location'] ?? [])
            <dl class="mt-4 grid gap-3 text-sm">
                <div><dt class="text-[#687773]">Municipio detectado</dt><dd class="font-bold">{{ $v2Location['municipality'] ?? '-' }}</dd></div>
                <div><dt class="text-[#687773]">Localidad</dt><dd class="font-bold">{{ $v2Location['locality'] ?? '-' }}</dd></div>
                <div><dt class="text-[#687773]">AGEB</dt><dd class="font-bold">{{ $v2Location['ageb'] ?? '-' }}</dd></div>
            </dl>
        </div>
        <div class="rounded-lg bg-white p-6">
            <h2 class="font-serif text-2xl">Compatibilidad legacy</h2>
            <dl class="mt-4 grid gap-3 text-sm">
                <div><dt class="text-[#687773]">COL_XX utilizado</dt><dd class="font-bold">{{ $valuation->property->avm_colonia ?: 'Sin mapping válido' }}</dd></div>
                <div><dt class="text-[#687773]">Estado legacy</dt><dd class="font-bold">{{ $valuation->status->label() }}</dd></div>
                <div><dt class="text-[#687773]">Error legacy</dt><dd class="font-bold">{{ $valuation->error_code ?: '-' }}</dd></div>
            </dl>
        </div>
    </section>
    <section class="mt-6 rounded-lg bg-white p-6">
        <h2 class="font-serif text-2xl">Comparación de modelos</h2>
        <p class="mt-1 text-sm text-[#687773]">Valores observados para esta valuación; no representan una conclusión sobre cuál modelo es correcto.</p>
        <div class="mt-4 grid gap-4 md:grid-cols-3">
            @foreach(['v1' => 'Modelo experimental v1', 'v2' => 'Residential v2/v2', 'legacy' => 'Legacy'] as $key => $label)
                <div class="rounded border border-[#e4dccd] p-4">
                    <p class="text-sm text-[#687773]">{{ $label }}</p>
                    <p class="mt-1 text-xl font-bold">{{ $modelComparison[$key] !== null ? '$'.number_format($modelComparison[$key], 2).' MXN' : 'Sin resultado' }}</p>
                </div>
            @endforeach
        </div>
        <dl class="mt-4 grid gap-2 text-sm md:grid-cols-3">
            @foreach(['v1_vs_v2' => 'Diferencia B vs C', 'v1_vs_legacy' => 'Diferencia B vs Legacy', 'v2_vs_legacy' => 'Diferencia C vs Legacy'] as $key => $label)
                <div><dt class="text-[#687773]">{{ $label }}</dt><dd class="font-bold">@if($modelComparison[$key]) ${{ number_format(abs($modelComparison[$key]['absolute']), 0) }} ({{ $modelComparison[$key]['percentage'] !== null && $modelComparison[$key]['percentage'] >= 0 ? '+' : '-' }}{{ number_format(abs($modelComparison[$key]['percentage'] ?? 0), 1) }}%) @else - @endif</dd></div>
            @endforeach
        </dl>
    </section>
    <section class="mt-6 rounded-lg bg-white p-6">
        <h2 class="font-serif text-2xl">Predicciones del modelo</h2>
        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead class="border-b border-[#e4dccd] text-xs uppercase tracking-[.14em] text-[#687773]">
                    <tr>
                        <th class="py-3 pr-4">Modelo</th>
                        <th class="py-3 pr-4">Estado</th>
                        <th class="py-3 pr-4">Valor</th>
                        <th class="py-3 pr-4">Rango</th>
                        <th class="py-3 pr-4">Confianza</th>
                        <th class="py-3 pr-4">Diferencia vs legacy</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#eee7dc]">
                    <tr>
                        <td class="py-3 pr-4 font-bold">Legacy</td>
                        <td class="py-3 pr-4">{{ $valuation->status->label() }}</td>
                        <td class="py-3 pr-4">
                            @if($valuation->estimated_value)
                                ${{ number_format((float) $valuation->estimated_value, 2) }} {{ $valuation->currency ?: 'MXN' }}
                            @else
                                -
                            @endif
                        </td>
                        <td class="py-3 pr-4">-</td>
                        <td class="py-3 pr-4">-</td>
                        <td class="py-3 pr-4">-</td>
                    </tr>
                    @forelse($valuation->modelPredictions as $prediction)
                        @php($difference = ($prediction->estimated_value !== null && $valuation->estimated_value !== null) ? (float) $prediction->estimated_value - (float) $valuation->estimated_value : null)
                        @php($differencePct = ($difference !== null && (float) $valuation->estimated_value > 0) ? ($difference / (float) $valuation->estimated_value) * 100 : null)
                        <tr>
                            <td class="py-3 pr-4 font-bold">{{ $prediction->model_name }}<span class="block text-xs font-normal text-[#687773]">{{ $prediction->model_version }}</span></td>
                            <td class="py-3 pr-4">{{ ucfirst($prediction->status) }}</td>
                            <td class="py-3 pr-4">
                                @if($prediction->estimated_value)
                                    ${{ number_format((float) $prediction->estimated_value, 2) }} MXN
                                @elseif(! $prediction->eligible)
                                    No elegible
                                @else
                                    -
                                @endif
                            </td>
                            <td class="py-3 pr-4">
                                @if($prediction->range_low && $prediction->range_high)
                                    ${{ number_format((float) $prediction->range_low, 0) }} - ${{ number_format((float) $prediction->range_high, 0) }}
                                @else
                                    -
                                @endif
                            </td>
                            <td class="py-3 pr-4">{{ $prediction->confidence ? ucfirst(strtolower($prediction->confidence)) : '-' }}</td>
                            <td class="py-3 pr-4">
                                @if($difference !== null)
                                    ${{ number_format(abs($difference), 0) }} <span class="text-xs text-[#687773]">({{ $difference >= 0 ? '+' : '-' }}{{ number_format(abs($differencePct), 1) }}%)</span>
                                @else
                                    -
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="py-3 pr-4 text-[#687773]" colspan="6">Sin predicciones experimentales registradas.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
    <section class="mt-6 rounded-lg bg-white p-6">
        <h2 class="font-serif text-2xl">Contexto cercano</h2>
        @php($response = $valuation->avm_response_json ?? [])
        @php($derived = $response['features_derivadas'] ?? [])
        @php($pois = $response['pois'] ?? [])
        <div class="mt-4 grid gap-4 md:grid-cols-2">
            <div class="rounded bg-[#f5f2eb] p-4">
                <p class="text-sm text-[#687773]">Escuelas cercanas</p>
                <p class="text-2xl font-bold">{{ ($derived['cerca_escuelas'] ?? 0) ? 'Sí' : 'No' }}</p>
            </div>
            <div class="rounded bg-[#f5f2eb] p-4">
                <p class="text-sm text-[#687773]">Transporte cercano</p>
                <p class="text-2xl font-bold">{{ ($derived['cerca_transporte'] ?? 0) ? 'Sí' : 'No' }}</p>
            </div>
        </div>
        @if(! empty($pois['nearest_m']))
            <h3 class="mt-6 font-serif text-xl">Distancias principales</h3>
            <dl class="mt-3 grid gap-3 text-sm md:grid-cols-3">
                @foreach($pois['nearest_m'] as $category => $distance)
                    <div class="rounded border border-[#e4dccd] p-3"><dt class="text-[#687773]">{{ str_replace('_', ' ', ucfirst($category)) }}</dt><dd class="font-bold">{{ $distance ? number_format($distance).' m' : 'Sin dato' }}</dd></div>
                @endforeach
            </dl>
        @endif
        @if(! empty($pois['counts']))
            <h3 class="mt-6 font-serif text-xl">POIs principales</h3>
            <dl class="mt-3 grid gap-3 text-sm md:grid-cols-3">
                @foreach($pois['counts'] as $category => $count)
                    <div class="rounded border border-[#e4dccd] p-3"><dt class="text-[#687773]">{{ str_replace('_', ' ', ucfirst($category)) }}</dt><dd class="font-bold">{{ $count }}</dd></div>
                @endforeach
            </dl>
        @endif
    </section>
</x-admin-layout>
