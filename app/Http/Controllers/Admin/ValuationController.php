<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AvmPropertyType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreValuationRequest;
use App\Models\Valuation;
use App\Services\Valuation\LegacyAvmCatalog;
use App\Services\Valuation\ValuationService;

class ValuationController extends Controller
{
    public function index()
    {
        return view('admin.valuations.index', [
            'valuations' => Valuation::with('property')->latest()->paginate(15),
        ]);
    }

    public function create(LegacyAvmCatalog $catalog)
    {
        return view('admin.valuations.create', [
            'propertyTypes' => collect(AvmPropertyType::options())->only(['house', 'apartment', 'land'])->all(),
            'colonias' => $catalog->options(),
        ]);
    }

    public function store(StoreValuationRequest $request, ValuationService $valuations)
    {
        $valuation = $valuations->createAndRun($request->validated(), $request->user());

        return redirect()
            ->route('admin.valuations.show', $valuation)
            ->with('status', 'Valuación creada.');
    }

    public function show(Valuation $valuation)
    {
        $valuation->load(['property', 'features', 'modelVersion', 'modelPredictions']);
        $predictions = $valuation->modelPredictions->keyBy('model_name');
        $value = fn (string $model) => isset($predictions[$model]) && $predictions[$model]->estimated_value !== null
            ? (float) $predictions[$model]->estimated_value
            : null;
        $modelComparison = [
            'legacy' => $valuation->estimated_value !== null ? (float) $valuation->estimated_value : null,
            'v1' => $value('avm_v2_v1'),
            'v2' => $value('avm_residential_v2'),
        ];
        $difference = function (?float $left, ?float $right): ?array {
            if ($left === null || $right === null) {
                return null;
            }

            return [
                'absolute' => $left - $right,
                'percentage' => $right > 0 ? (($left - $right) / $right) * 100 : null,
            ];
        };
        $modelComparison['v1_vs_v2'] = $difference($modelComparison['v1'], $modelComparison['v2']);
        $modelComparison['v1_vs_legacy'] = $difference($modelComparison['v1'], $modelComparison['legacy']);
        $modelComparison['v2_vs_legacy'] = $difference($modelComparison['v2'], $modelComparison['legacy']);

        return view('admin.valuations.show', [
            'valuation' => $valuation,
            'modelComparison' => $modelComparison,
        ]);
    }
}
