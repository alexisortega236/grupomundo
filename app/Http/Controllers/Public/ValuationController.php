<?php

namespace App\Http\Controllers\Public;

use App\Enums\AvmPropertyType;
use App\Enums\ValuationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Public\StoreValuationRequest;
use App\Models\Valuation;
use App\Services\Valuation\PublicValuationResultResolver;
use App\Services\Valuation\PublicValuationLocationResolver;
use App\Services\Valuation\SupportedValuationLocations;
use App\Services\Valuation\ValuationService;

class ValuationController extends Controller
{
    public function create(SupportedValuationLocations $locations)
    {
        return view('public.valuations.create', [
            'propertyTypes' => collect(AvmPropertyType::options())->only(['house', 'apartment', 'land'])->all(),
            'municipalities' => $locations->municipalities(),
            'municipalityCenters' => $locations->centers(),
        ]);
    }

    public function store(StoreValuationRequest $request, ValuationService $valuations, PublicValuationLocationResolver $locations)
    {
        $data = $locations->resolve($request->validated());
        $valuation = $valuations->createAndRun($data, null, 'public');

        return redirect()->route('valuation.show', $valuation->uuid);
    }

    public function show(string $uuid, PublicValuationResultResolver $resultResolver)
    {
        $valuation = Valuation::with(['property', 'features', 'modelPredictions'])
            ->where('uuid', $uuid)
            ->where('source', 'public')
            ->firstOrFail();

        return view('public.valuations.show', [
            'valuation' => $valuation,
            'publicResult' => $resultResolver->resolve($valuation),
            'advisorUrl' => 'https://wa.me/'.config('company.whatsapp_number').'?text='.rawurlencode('Hola, realicé una valuación en el sitio de Grupo Mundo Patrimonial y quiero una valoración más detallada.'),
            'isFailed' => $valuation->status === ValuationStatus::Failed,
        ]);
    }
}
